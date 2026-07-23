<?php
// admin/users.php – User Management
require_once dirname(__DIR__) . '/includes/config.php';
require_once APP_ROOT . '/includes/mailer.php';
requireAnyRole(['superadmin', 'admin', 'traceability']);
ensureUsersRoleSupportsRework();
ensureUsersTraceabilityColumns();
$hasUserRolesTable = ensureUserRolesTable();
$pageTitle = t('users_title');
$activePage = 'users';

$isTraceabilityManager = hasOnlyRole('traceability');
$scope = (string)get('scope', '');
$isTraceabilityScope = $isTraceabilityManager || $scope === 'traceability';

$allManageableRoles = ['superadmin', 'admin', 'write', 'read', 'traceability', 'writer', 'rework', 'rework_admin', 'serizovac', 'serizovac_admin'];
$traceabilityManageableRoles = ['writer', 'read'];
$manageableRoles = $isTraceabilityManager ? $traceabilityManageableRoles : $allManageableRoles;

$rolePrioritySql = "'superadmin','admin','traceability','writer','write','rework_admin','rework','serizovac_admin','serizovac','read'";

$rolePasswordMinLen = static function (string $role): int {
    return in_array($role, ['writer', 'read', 'rework', 'rework_admin', 'serizovac', 'serizovac_admin'], true) ? 6 : 12;
};

$roleLabel = static function (string $role): string {
    return match ($role) {
        'superadmin' => t('role_display_superadmin'),
        'admin' => 'Admin',
        'writer' => t('role_display_writer'),
        'write' => t('role_display_write'),
        'traceability' => t('role_display_traceability'),
        'rework' => t('role_display_rework'),
        'rework_admin' => t('role_display_rework_admin'),
        'serizovac' => t('role_display_serizovac'),
        'serizovac_admin' => t('role_display_serizovac_admin'),
        default => t('role_display_read'),
    };
};

$roleBadgeBackground = static function (string $role): string {
    return match ($role) {
        'superadmin' => 'var(--accent)',
        'admin' => '#72d6ff',
        'writer' => '#22c55e',
        'write' => 'var(--warn)',
        'traceability' => '#38bdf8',
        'rework' => '#a855f7',
        'rework_admin' => '#7c3aed',
        'serizovac' => '#14b8a6',
        'serizovac_admin' => '#0f766e',
        default => 'var(--muted)',
    };
};

$roleBadgeColor = static function (string $role): string {
    return in_array($role, ['superadmin', 'admin', 'traceability', 'writer', 'rework', 'rework_admin', 'serizovac', 'serizovac_admin'], true) ? 'var(--bg)' : 'var(--text)';
};

$normalizeRoleSelection = static function ($rawRoles) use ($manageableRoles, $isTraceabilityManager): array {
    $items = is_array($rawRoles) ? $rawRoles : [$rawRoles];
    $roles = [];
    foreach ($items as $item) {
        $role = strtolower(trim((string)$item));
        if (in_array($role, $manageableRoles, true)) {
            $roles[] = $role;
        }
    }
    $roles = sanitizeRoles($roles);
    if ($roles === []) {
        $roles = [$isTraceabilityManager ? 'writer' : 'read'];
    }
    return $roles;
};

$rolesAreManageable = static function (array $roles) use ($traceabilityManageableRoles): bool {
    $normalized = sanitizeRoles($roles);
    if ($normalized === []) {
        return false;
    }
    foreach ($normalized as $role) {
        if (!in_array($role, $traceabilityManageableRoles, true)) {
            return false;
        }
    }
    return true;
};

$syncUserToTraceability = static function (int $userId, string $username, string $skutecneJmeno, string $primaryRole, string $cardCode = ''): void {
    if ($userId <= 0 || !ensureUsersTraceabilityColumns()) {
        return;
    }

    $username = trim($username);
    $displayName = trim($skutecneJmeno);
    $traceName = $displayName !== '' ? $displayName : $username;
    if ($traceName === '') {
        return;
    }

    $rights = mapRoleToTraceabilityRights($primaryRole);
    $cardCode = trim($cardCode);

    // Uložit číslo karty do dvd_vyroba.users
    if ($cardCode !== '') {
        db()->prepare('UPDATE users SET traceability_card_code = ? WHERE id = ?')
            ->execute([$cardCode, $userId]);
    }

    try {
        $mappedId = (int)db_val('SELECT traceability_user_id FROM users WHERE id = ? LIMIT 1', [$userId]);
        if ($mappedId > 0) {
            $exists = (int)trace_db_val('SELECT COUNT(*) FROM user WHERE id = ?', [$mappedId]);
            if ($exists > 0) {
                if ($cardCode !== '') {
                    // Heslo v plain-textu (číslo karty)
                    trace_db()->prepare('UPDATE user SET username = ?, password = ?, rights = ? WHERE id = ?')
                        ->execute([$traceName, $cardCode, $rights, $mappedId]);
                } else {
                    trace_db()->prepare('UPDATE user SET username = ?, rights = ? WHERE id = ?')
                        ->execute([$traceName, $rights, $mappedId]);
                }
                db()->prepare('UPDATE users SET traceability_rights = ? WHERE id = ?')
                    ->execute([$rights, $userId]);
                return;
            }
        }

        $existingId = 0;
        foreach (array_values(array_unique(array_filter([$traceName, $username], static fn(string $v): bool => trim($v) !== ''))) as $candidate) {
            $found = (int)trace_db_val('SELECT id FROM user WHERE username = ? LIMIT 1', [$candidate]);
            if ($found > 0) {
                $existingId = $found;
                break;
            }
        }

        if ($existingId > 0) {
            if ($cardCode !== '') {
                trace_db()->prepare('UPDATE user SET username = ?, password = ?, rights = ? WHERE id = ?')
                    ->execute([$traceName, $cardCode, $rights, $existingId]);
            } else {
                trace_db()->prepare('UPDATE user SET username = ?, rights = ? WHERE id = ?')
                    ->execute([$traceName, $rights, $existingId]);
            }
            db()->prepare('UPDATE users SET traceability_user_id = ?, traceability_rights = ? WHERE id = ?')
                ->execute([$existingId, $rights, $userId]);
            return;
        }

        // Nový řádek v traceability2.user — heslo plain-text
        $stmt = trace_db()->prepare('INSERT INTO user (username, password, rights) VALUES (?, ?, ?)');
        $stmt->execute([$traceName, $cardCode, $rights]);
        $newTraceId = (int)trace_db()->lastInsertId();
        if ($newTraceId > 0) {
            db()->prepare('UPDATE users SET traceability_user_id = ?, traceability_rights = ? WHERE id = ?')
                ->execute([$newTraceId, $rights, $userId]);
        }
    } catch (Throwable $e) {
        error_log('User sync to traceability failed for user #' . $userId . ': ' . $e->getMessage());
    }
};

function normalizeCsvUserValue(?string $value): ?string {
    if ($value === null) {
        return null;
    }

    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    if ($value === null || $value === '') {
        return $value;
    }

    if (!mb_check_encoding($value, 'UTF-8')) {
        $converted = @iconv('Windows-1250', 'UTF-8//IGNORE', $value);
        if ($converted === false || $converted === '') {
            $converted = @iconv('ISO-8859-2', 'UTF-8//IGNORE', $value);
        }
        if ($converted !== false && $converted !== '') {
            $value = $converted;
        }
    }

    return trim($value);
}

$fSearch = get('search', '');
$fRole = get('role', '');
$fActive = get('active', '');
$fPass = get('pass', '');

if ($isTraceabilityScope && !in_array($fRole, $traceabilityManageableRoles, true)) {
    $fRole = '';
}

$where = [];
$params = [];
if ($fSearch !== '') {
    $where[] = '(u.username LIKE ? OR u.skutecneJmeno LIKE ?)';
    $params[] = '%'.$fSearch.'%';
    $params[] = '%'.$fSearch.'%';
}
if ($isTraceabilityScope) {
    if ($hasUserRolesTable) {
        $where[] = '(u.role IN ("writer","read") OR EXISTS (SELECT 1 FROM user_roles urx WHERE urx.user_id = u.id AND urx.role IN ("writer","read")))';
    } else {
        $where[] = 'u.role IN ("writer","read")';
    }
}
if (in_array($fRole, $manageableRoles, true)) {
    if ($hasUserRolesTable) {
        $where[] = '(u.role = ? OR EXISTS (SELECT 1 FROM user_roles urf WHERE urf.user_id = u.id AND urf.role = ?))';
        $params[] = $fRole;
        $params[] = $fRole;
    } else {
        $where[] = 'u.role = ?';
        $params[] = $fRole;
    }
}
if ($fActive === '1' || $fActive === '0') {
    $where[] = 'u.active = ?';
    $params[] = (int)$fActive;
}
if ($fPass === 'changed') {
    $where[] = 'u.password_changed_at IS NOT NULL';
} elseif ($fPass === 'initial') {
    $where[] = 'u.password_changed_at IS NULL';
}

$whereSql = empty($where) ? '1=1' : implode(' AND ', $where);

if ($hasUserRolesTable) {
    $users = db_all(
        "SELECT u.id,
                u.username,
                u.skutecneJmeno,
                u.role AS primary_role,
                u.active,
                u.created_at,
                u.password_changed_at,
                COALESCE(NULLIF(GROUP_CONCAT(DISTINCT ur.role ORDER BY FIELD(ur.role, $rolePrioritySql) SEPARATOR ','), ''), u.role) AS roles_csv
         FROM users u
         LEFT JOIN user_roles ur ON ur.user_id = u.id
         WHERE $whereSql
         GROUP BY u.id
         ORDER BY u.username",
        $params
    );
} else {
    $users = db_all(
        "SELECT u.id,
                u.username,
                u.skutecneJmeno,
                u.role AS primary_role,
                u.active,
                u.created_at,
                u.password_changed_at,
                u.role AS roles_csv
         FROM users u
         WHERE $whereSql
         ORDER BY u.username",
        $params
    );
}

// Resetovat heslo na iniciální (nastavit na NULL => příště bude vyžadována změna)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'reset_password') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $userId = (int)post('id');
    $target = db_row('SELECT id FROM users WHERE id=?', [$userId]);
    $targetRoles = getUserRolesByUserId($userId);
    if (!$target || ($isTraceabilityManager && !$rolesAreManageable($targetRoles))) {
        $error = t('insufficient_role_permission');
    } else {
    db()->prepare('UPDATE users SET password_changed_at = NULL WHERE id=?')->execute([$userId]);
    logAction('USER_PASSWORD_RESET', 'users', $userId, [], [], t('password_reset_to_initial'));
    redirect(APP_URL . '/admin/users.php?ok=1');
    }
}

// Znovu odeslat inicialni mail + nastavit nove inicialni heslo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'resend_initial_mail') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }

    $userId = (int)post('id');
    $email = trim((string)post('email'));
    $targetUser = db_row('SELECT id, username, skutecneJmeno, role FROM users WHERE id=?', [$userId]);
    $targetRoles = getUserRolesByUserId($userId);
    $targetPrimaryRole = getPrimaryRoleFromRoles($targetRoles);

    if (!$targetUser) {
        $error = t('user_not_found');
    } elseif ($isTraceabilityManager && !$rolesAreManageable($targetRoles)) {
        $error = t('insufficient_role_permission');
    } elseif ($email === '') {
        $error = t('email_required');
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = t('invalid_email');
    } else {
        $initialPassword = bin2hex(random_bytes(8));
        $mailResult = appSendInitialCredentialsOutlook(
            $email,
            (string)($targetUser['skutecneJmeno'] ?? ''),
            (string)$targetUser['username'],
            $initialPassword,
            $targetPrimaryRole
        );

        if ($mailResult['ok']) {
            $hash = password_hash($initialPassword, PASSWORD_DEFAULT);
            db()->prepare('UPDATE users SET pass_hash=?, password_changed_at=NULL WHERE id=?')->execute([$hash, $userId]);

            logAction(
                'USER_INIT_MAIL_RESENT',
                'users',
                $userId,
                [],
                ['username' => $targetUser['username'], 'email' => $email],
                t('initial_mail_resent')
            );

            redirect(APP_URL . '/admin/users.php?ok=1');
        }

        $error = t('initial_mail_send_failed') . ': ' . (string)($mailResult['error'] ?? t('unknown_error'));
        logAction(
            'USER_INIT_MAIL_RESEND_FAILED',
            'users',
            $userId,
            [],
            ['username' => $targetUser['username'], 'email' => $email, 'error' => (string)($mailResult['error'] ?? '')],
            t('initial_mail_send_failed')
        );
    }
}

// Smazat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $userId = (int)post('id');
    $target = db_row('SELECT id FROM users WHERE id=?', [$userId]);
    $targetRoles = getUserRolesByUserId($userId);
    if (!$target) {
        $error = t('user_not_found');
    } elseif ($userId === 1) {
        $error = t('cannot_delete');
    } elseif ($isTraceabilityManager && !$rolesAreManageable($targetRoles)) {
        $error = t('insufficient_role_permission');
    } else {
        db()->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
        logAction('USER_DELETE', 'users', $userId, ['action' => 'delete'], [], t('user_deleted'));
        redirect(APP_URL . '/admin/users.php?ok=1');
    }
}

// Přidat/upravit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $id = post('id') ? (int)post('id') : null;
    $username = trim(post('username'));
    $skutecneJmeno = trim(post('skutecneJmeno'));
    $email = trim((string)post('email'));
    $password = post('password');
    $selectedRoles = $normalizeRoleSelection(post('roles'));
    $primaryRole = getPrimaryRoleFromRoles($selectedRoles);
    $active = post('active') ? 1 : 0;
    
    $errors = [];
    if (empty($username)) $errors[] = t('username_required');
    if (strlen($username) < 3) $errors[] = t('min_username');
    if (!$id && empty($password)) $errors[] = t('password_required') . ' ' . t('required_for_change');
    $passwordMinLen = $rolePasswordMinLen($primaryRole);
    if ($password && strlen($password) < $passwordMinLen) {
        $errors[] = $passwordMinLen === 6 ? t('min_password_traceability') : t('min_password');
    }
    if (!$id && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) $errors[] = t('invalid_email');
    
    if (!empty($errors)) {
        $error = implode("<br>", $errors);
    } else {
        // Kontrola duplicity
        if (!$id) {
            $exists = db_val('SELECT COUNT(*) FROM users WHERE username=?', [$username]);
            if ($exists) $error = t('user_exists');
        }
        
        if (empty($error)) {
            if (!$id) {
                // Nový uživatel
                // Role read (Operátor) = karta, účet rovnou aktivní bez iniciálního hesla
                $isCardRole = $primaryRole === 'read';
                $hash = password_hash($password, PASSWORD_DEFAULT);
                if ($isCardRole) {
                    db()->prepare('INSERT INTO users (username, skutecneJmeno, pass_hash, role, active, password_changed_at) VALUES (?,?,?,?,?,NOW())')
                       ->execute([$username, $skutecneJmeno, $hash, $primaryRole, $active]);
                } else {
                    db()->prepare('INSERT INTO users (username, skutecneJmeno, pass_hash, role, active) VALUES (?,?,?,?,?)')
                       ->execute([$username, $skutecneJmeno, $hash, $primaryRole, $active]);
                }
                $newUserId = (int)db()->lastInsertId();
                if ($newUserId > 0) {
                    saveUserRoles($newUserId, $selectedRoles);
                    db()->prepare('UPDATE users SET traceability_rights = ? WHERE id = ?')
                        ->execute([mapRoleToTraceabilityRights($primaryRole), $newUserId]);
                    // $password = číslo karty; předáme jako cardCode do sync
                    $syncUserToTraceability($newUserId, $username, $skutecneJmeno, $primaryRole, (string)$password);
                }
                logAction('USER_CREATE', 'users', null, [], ['username' => $username, 'role' => $primaryRole, 'roles' => implode(',', $selectedRoles), 'skutecneJmeno' => $skutecneJmeno], t('user_created'));

                if ($email !== '') {
                    $mailResult = appSendInitialCredentialsOutlook($email, $skutecneJmeno, $username, (string)$password, $primaryRole);
                    if ($mailResult['ok']) {
                        logAction('USER_CREATE_INIT_MAIL_SENT', 'users', null, [], ['username' => $username, 'email' => $email], 'Initial password sent via Outlook');
                    } else {
                        logAction('USER_CREATE_INIT_MAIL_FAILED', 'users', null, [], ['username' => $username, 'email' => $email, 'error' => (string)($mailResult['error'] ?? '')], 'Initial password email failed');
                    }
                }
            } else {
                // Úprava
                $oldUser = db_row('SELECT * FROM users WHERE id=?', [$id]);
                $oldUserRoles = getUserRolesByUserId((int)$id);
                if ($isTraceabilityManager && $oldUser && !$rolesAreManageable($oldUserRoles)) {
                    $error = t('insufficient_role_permission');
                }
                if ($isTraceabilityManager && !$rolesAreManageable($selectedRoles)) {
                    $error = t('insufficient_role_permission');
                }
                if (!empty($error)) {
                    // no-op
                } elseif ($password) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    db()->prepare('UPDATE users SET username=?, skutecneJmeno=?, pass_hash=?, role=?, active=? WHERE id=?')
                       ->execute([$username, $skutecneJmeno, $hash, $primaryRole, $active, $id]);
                } else {
                    db()->prepare('UPDATE users SET username=?, skutecneJmeno=?, role=?, active=? WHERE id=?')
                       ->execute([$username, $skutecneJmeno, $primaryRole, $active, $id]);
                }
                if (empty($error)) {
                    saveUserRoles((int)$id, $selectedRoles);
                    db()->prepare('UPDATE users SET traceability_rights = ? WHERE id = ?')
                        ->execute([mapRoleToTraceabilityRights($primaryRole), $id]);
                    // Při editaci předat cardCode pouze pokud bylo zadáno nové heslo
                    $syncUserToTraceability((int)$id, $username, $skutecneJmeno, $primaryRole, (string)$password);
                }
                if (empty($error)) logAction('USER_UPDATE', 'users', $id, 
                         ['username' => $oldUser['username'], 'role' => $oldUser['role'], 'roles' => implode(',', $oldUserRoles), 'skutecneJmeno' => $oldUser['skutecneJmeno'] ?? ''],
                         ['username' => $username, 'role' => $primaryRole, 'roles' => implode(',', $selectedRoles), 'skutecneJmeno' => $skutecneJmeno], 
                         t('user_updated'));
            }
            if (empty($error)) {
                redirect(APP_URL . '/admin/users.php?ok=1');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'csv_import') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $imp = 0;
    $skip = 0;

    $csvTmp = isset($_FILES['csv_file']) ? validateUploadedCsv($_FILES['csv_file']) : null;
    if ($csvTmp !== null) {
        $h = fopen($csvTmp, 'r');
        if ($h !== false) {
            fgetcsv($h, 0, ';');
            $stmt = db()->prepare('INSERT IGNORE INTO users (username, skutecneJmeno, pass_hash, role, active) VALUES (?,?,?,?,?)');

            while (($r = fgetcsv($h, 0, ';')) !== false) {
                $username = normalizeCsvUserValue($r[0] ?? null);
                $realName = normalizeCsvUserValue($r[1] ?? null);
                $roleRaw = strtolower((string)(normalizeCsvUserValue($r[2] ?? null) ?? 'read'));
                $activeRaw = strtolower((string)(normalizeCsvUserValue($r[3] ?? null) ?? '1'));
                $password = (string)(normalizeCsvUserValue($r[4] ?? null) ?? '');

                if ($username === null || $username === '' || strlen($username) < 3) {
                    $skip++;
                    continue;
                }

                if (strlen($password) < 12) {
                    // handled by role-specific validation below
                }

                $selectedRoles = $normalizeRoleSelection([$roleRaw]);
                $primaryRole = getPrimaryRoleFromRoles($selectedRoles);
                $active = in_array($activeRaw, ['1', 'true', 'yes', 'ano'], true) ? 1 : 0;
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $minLen = $rolePasswordMinLen($primaryRole);
                if (strlen($password) < $minLen) {
                    $skip++;
                    continue;
                }

                try {
                    $stmt->execute([$username, $realName ?: null, $hash, $primaryRole, $active]);
                    $imp += $stmt->rowCount();
                    if ($stmt->rowCount() > 0) {
                        $newUserId = (int)db()->lastInsertId();
                        if ($newUserId > 0) {
                            saveUserRoles($newUserId, $selectedRoles);
                            db()->prepare('UPDATE users SET traceability_rights = ? WHERE id = ?')
                                ->execute([mapRoleToTraceabilityRights($primaryRole), $newUserId]);
                            $syncUserToTraceability($newUserId, $username, (string)($realName ?? ''), $primaryRole);
                        }
                    }
                } catch (Exception) {
                    $skip++;
                }
            }
            fclose($h);
        }
    } else {
        $skip++;
    }

    $msg = t('import_ok').': '.$imp.' '.t('import_rows').', '.$skip.' '.t('import_skip');
}

$editUser = null;
if (get('edit')) {
    $editUser = db_row('SELECT id, username, skutecneJmeno, role AS primary_role, active FROM users WHERE id=?', [(int)get('edit')]);
    if ($editUser) {
        $editUser['roles'] = getUserRolesByUserId((int)$editUser['id']);
    }
}

require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-people me-2"></i><?= t('users_title') ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <button class="btn-dvd btn-dvd-sm" data-bs-toggle="modal" data-bs-target="#addModal" onclick="resetForm()">
            <i class="bi bi-plus-circle me-1"></i><?= t('add') ?> <?= t('username') ?>
        </button>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">
    <div class="row mb-3 no-print">
        <div class="col-12">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <input type="text" name="search" class="form-control" placeholder="<?= t('search_user_placeholder') ?>" value="<?= h($fSearch) ?>">
                </div>
                <?php if ($isTraceabilityScope): ?>
                <input type="hidden" name="scope" value="traceability">
                <?php endif; ?>
                <div class="col-6 col-md-2">
                    <select name="role" class="form-select">
                        <option value="">Role: vše</option>
                        <?php if (!$isTraceabilityScope): ?>
                        <option value="superadmin" <?= $fRole==='superadmin'?'selected':'' ?>>Superadmin</option>
                        <option value="admin" <?= $fRole==='admin'?'selected':'' ?>>Admin</option>
                        <option value="write" <?= $fRole==='write'?'selected':'' ?>><?= t('role_display_write') ?></option>
                        <option value="traceability" <?= $fRole==='traceability'?'selected':'' ?>><?= t('role_display_traceability') ?></option>
                        <option value="rework" <?= $fRole==='rework'?'selected':'' ?>><?= t('role_display_rework') ?></option>
                        <option value="rework_admin" <?= $fRole==='rework_admin'?'selected':'' ?>><?= t('role_display_rework_admin') ?></option>
                        <option value="serizovac" <?= $fRole==='serizovac'?'selected':'' ?>><?= t('role_display_serizovac') ?></option>
                        <option value="serizovac_admin" <?= $fRole==='serizovac_admin'?'selected':'' ?>><?= t('role_display_serizovac_admin') ?></option>
                        <?php endif; ?>
                        <option value="writer" <?= $fRole==='writer'?'selected':'' ?>><?= t('role_display_writer') ?></option>
                        <option value="read" <?= $fRole==='read'?'selected':'' ?>><?= t('role_display_read') ?></option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select name="active" class="form-select">
                        <option value="">Stav: vše</option>
                        <option value="1" <?= $fActive==='1'?'selected':'' ?>><?= t('active') ?></option>
                        <option value="0" <?= $fActive==='0'?'selected':'' ?>><?= t('inactive') ?></option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select name="pass" class="form-select">
                        <option value="">Heslo: vše</option>
                        <option value="changed" <?= $fPass==='changed'?'selected':'' ?>>Změněno</option>
                        <option value="initial" <?= $fPass==='initial'?'selected':'' ?>>Iniciální</option>
                    </select>
                </div>
                <div class="col-6 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-search"></i></button>
                    <?php if ($fSearch || $fRole || $fActive || $fPass): ?><a href="users.php<?= $isTraceabilityScope ? '?scope=traceability' : '' ?>" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem">✕</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if (get('ok')): ?>
    <div class="alert" style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--success);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem">
        ✓ <?= t('save') ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
    <div class="alert" style="background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);color:var(--danger);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem">
        ✗ <?= $error ?>
    </div>
    <?php endif; ?>

    <?php if (isset($msg)): ?>
    <div class="alert" style="background:rgba(0,229,176,.1);border:1px solid rgba(0,229,176,.25);color:var(--accent);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem">
        <?= h($msg) ?>
    </div>
    <?php endif; ?>

    <div class="card-dvd mb-3">
        <div class="card-header-dvd" data-collapsible><span class="s-num">⬆</span><span class="s-title"><?= t('import_csv') ?> – <?= t('import_csv_users') ?></span><i class="bi bi-chevron-down s-toggle"></i></div>
        <div class="card-body-dvd" style="display:none">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="csv_import">
                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                <div class="row g-2 align-items-end">
                    <div class="col-md-6"><label class="form-label-dvd"><?= t('import_select') ?></label><input type="file" name="csv_file" class="form-control" accept=".csv" required></div>
                    <div class="col-auto d-flex gap-2">
                        <a href="<?= APP_URL ?>/assets/templates/import_users_template.csv" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem" download>
                            <i class="bi bi-download me-1"></i>Vzor CSV
                        </a>
                        <button type="submit" class="btn-dvd btn-dvd-sm"><?= t('import_run') ?></button>
                    </div>
                </div>
                <div class="mt-2" style="font-size:.7rem;color:var(--muted)"><?= t('format_users') ?></div>
            </form>
        </div>
    </div>

    <?php if (empty($users)): ?>
    <div style="text-align:center;padding:2rem;color:var(--muted);">
        <i class="bi bi-inbox" style="font-size:2rem;"></i>
        <p><?= t('no_users') ?> <?= ($fSearch || $fRole || $fActive || $fPass) ? t('matching_search') : '' ?></p>
    </div>
    <?php else: ?>
    <div class="tbl-wrap">
    <div class="overflow-x">
    <table class="tbl">
        <thead>
            <tr>
                <th><?= t('real_name') ?></th>
                <th><?= t('login') ?></th>
                <th style="width:110px;"><?= t('password_status') ?></th>
                <th style="width:120px;"><?= t('edit_title') ?></th>
                <th style="width:90px;"><?= t('active') ?></th>
                <th style="width:140px;"><?= t('created') ?></th>
                <th style="width:260px;"><?= t('action') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($users as $u): ?>
            <?php $displayName = trim((string)($u['skutecneJmeno'] ?? '')) !== '' ? (string)$u['skutecneJmeno'] : (string)$u['username']; ?>
            <?php $userRoles = sanitizeRoles(explode(',', (string)($u['roles_csv'] ?? $u['primary_role'] ?? 'read'))); ?>
            <tr>
                <td><strong><?= h($u['skutecneJmeno'] ?? '—') ?></strong></td>
                <td><span style="color:var(--muted);"><?= h($u['username']) ?></span></td>
                <td>
                    <?php if ($u['password_changed_at']): ?>
                    <span class="badge" style="background:rgba(74,222,128,.2);color:var(--success);font-size:.7rem;">
                        <i class="bi bi-check-circle" style="font-size:.7rem;margin-right:.3rem;"></i>Změněno
                    </span>
                    <?php else: ?>
                    <span class="badge" style="background:rgba(255,193,7,.2);color:var(--warn);font-size:.7rem;">
                        <i class="bi bi-exclamation-circle" style="font-size:.7rem;margin-right:.3rem;"></i>Iniciální
                    </span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display:flex;flex-wrap:wrap;gap:.25rem;">
                        <?php foreach ($userRoles as $roleItem): ?>
                        <span class="badge" style="background:<?= h($roleBadgeBackground($roleItem)) ?>;color:<?= h($roleBadgeColor($roleItem)) ?>;">
                            <?= h($roleLabel($roleItem)) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </td>
                <td>
                    <span class="badge" style="background:<?= $u['active']?'rgba(74,222,128,.2)':'rgba(255,107,53,.2)' ?>;color:<?= $u['active']?'var(--success)':'var(--danger)' ?>">
                        <?= $u['active']?t('active'):t('inactive') ?>
                    </span>
                </td>
                <td style="font-size:.8rem;color:var(--muted);">
                    <small><?= date('d.m.Y H:i', strtotime($u['created_at'])) ?></small>
                </td>
                <td style="font-size:.85rem;">
                    <button class="btn btn-sm" style="background:none;border:1px solid var(--border);color:var(--accent);padding:.2rem .4rem;border-radius:4px;cursor:pointer;" 
                            onclick="editUser(<?= h(json_encode(array_merge($u, ['roles' => $userRoles]), JSON_UNESCAPED_UNICODE)) ?>)">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button
                        type="button"
                        class="btn btn-sm"
                        style="background:none;border:1px solid var(--border);color:var(--accent);padding:.2rem .4rem;border-radius:4px;cursor:pointer;"
                        data-resend-user-id="<?= (int)$u['id'] ?>"
                        data-resend-username="<?= h($displayName) ?>"
                        onclick="openResendInitialMailModal(this)"
                        title="<?= t('resend_initial_mail') ?>"
                    >
                        <i class="bi bi-envelope"></i>
                    </button>
                    <?php if (!$u['password_changed_at']): ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('<?= t('confirm_reset_password') ?>')">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <button type="submit" class="btn btn-sm" style="background:none;border:1px solid var(--border);color:var(--warn);padding:.2rem .4rem;border-radius:4px;cursor:pointer;" title="<?= t('reset_password_to_initial') ?>">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </form>
                    <?php else: ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('<?= t('confirm_reset_password') ?>')">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                        <button type="submit" class="btn btn-sm" style="background:none;border:1px solid var(--border);color:var(--warn);padding:.2rem .4rem;border-radius:4px;cursor:pointer;" title="<?= t('reset_password_to_initial') ?>">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($u['id'] !== 1): ?>
                    <button
                        type="button"
                        class="btn btn-sm"
                        style="background:none;border:1px solid var(--border);color:var(--danger);padding:.2rem .4rem;border-radius:4px;cursor:pointer;"
                        data-delete-user-id="<?= (int)$u['id'] ?>"
                        data-delete-username="<?= h($displayName) ?>"
                        onclick="confirmDeleteUser(this)"
                    >
                            <i class="bi bi-trash"></i>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
    <div class="tbl-footer"><span><?= count($users) ?> <?= count($users)==1?t('user'):t('users') ?></span></div>
    <?php endif; ?>
</div>

<style>
.roles-chip-grid{display:flex;flex-wrap:wrap;gap:.45rem}
.role-chip-input{position:absolute;opacity:0;pointer-events:none}
.role-chip-label{display:inline-flex;align-items:center;gap:.35rem;padding:.38rem .68rem;border:1px solid var(--border);border-radius:999px;background:rgba(255,255,255,.03);color:var(--text);font-size:.78rem;cursor:pointer;user-select:none;transition:.15s ease}
.role-chip-label:hover{border-color:var(--accent);color:var(--accent)}
.role-chip-input:checked + .role-chip-label{border-color:var(--accent);background:rgba(0,229,176,.12);color:var(--accent)}
.role-chip-input:checked + .role-chip-label::before{content:'✓';font-weight:700;font-size:.72rem;line-height:1}
</style>

<div class="modal fade" id="resendInitialMailModal" tabindex="-1" style="--bs-modal-bg: var(--surface);--bs-modal-border-color: var(--border);">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background:var(--surface);border:1px solid var(--border);">
            <form method="post" id="resendInitialMailForm">
                <input type="hidden" name="action" value="resend_initial_mail">
                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                <input type="hidden" name="id" id="resendInitialMailUserId">
                <div class="modal-header" style="border-bottom:1px solid rgba(255,255,255,.05);">
                    <h5 class="modal-title"><?= t('resend_initial_mail_title') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1);"></button>
                </div>
                <div class="modal-body">
                    <div style="font-size:.82rem;color:var(--muted);margin-bottom:.75rem;">
                        <?= t('resend_initial_mail_hint') ?>
                    </div>
                    <div style="font-size:.82rem;color:var(--muted);margin-bottom:.75rem;">
                        <span><?= t('username') ?>:</span>
                        <strong id="resendInitialMailUserName"></strong>
                    </div>
                    <div class="mb-2">
                        <label class="form-label-dvd" for="resendInitialMailEmail"><?= t('target_email') ?> <span class="req">*</span></label>
                        <input type="email" class="form-control" id="resendInitialMailEmail" name="email" required placeholder="napr. uzivatel@firma.cz">
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid rgba(255,255,255,.05);">
                    <button type="button" class="btn" style="background:rgba(255,255,255,.05);border:1px solid var(--border);color:var(--text);padding:.4rem .8rem;border-radius:6px;cursor:pointer;" data-bs-dismiss="modal"><?= t('cancel') ?></button>
                    <button type="submit" class="btn-dvd"><i class="bi bi-envelope me-1"></i><?= t('send_initial_mail') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteUserModal" tabindex="-1" style="--bs-modal-bg: var(--surface);--bs-modal-border-color: var(--border);">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="background:var(--surface);border:1px solid var(--border);">
            <form method="post" id="deleteUserForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                <input type="hidden" name="id" id="deleteUserId">
                <div class="modal-header" style="border-bottom:1px solid rgba(255,255,255,.05);">
                    <h5 class="modal-title"><?= t('delete') ?> <?= t('username') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1);"></button>
                </div>
                <div class="modal-body">
                    <div style="font-size:.9rem;color:var(--text);margin-bottom:.35rem"><?= t('confirm_delete') ?></div>
                    <div style="font-size:.82rem;color:var(--muted);">
                        <span><?= t('username') ?>:</span>
                        <strong id="deleteUserName"></strong>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid rgba(255,255,255,.05);">
                    <button type="button" class="btn" style="background:rgba(255,255,255,.05);border:1px solid var(--border);color:var(--text);padding:.4rem .8rem;border-radius:6px;cursor:pointer;" data-bs-dismiss="modal"><?= t('cancel') ?></button>
                    <button type="submit" class="btn-dvd" style="background:var(--danger);border-color:var(--danger);"><?= t('delete') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="addModal" tabindex="-1" style="--bs-modal-bg: var(--surface);--bs-modal-border-color: var(--border);">
    <div class="modal-dialog">
        <div class="modal-content" style="background:var(--surface);border:1px solid var(--border);">
            <form method="post" id="userForm">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                <input type="hidden" name="id" id="userId">
                <div class="modal-header" style="border-bottom:1px solid rgba(255,255,255,.05);">
                    <h5 class="modal-title" id="modalTitle"><?= t('new') ?> <?= t('username') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1);"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label-dvd"><?= t('username') ?> <span class="req">*</span></label>
                        <input type="text" name="username" id="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-dvd"><?= t('real_name') ?></label>
                        <input type="text" name="skutecneJmeno" id="skutecneJmeno" class="form-control">
                    </div>
                    <div class="mb-3" id="emailGroup">
                        <label class="form-label-dvd"><?= t('email_optional') ?></label>
                        <input type="email" name="email" id="email" class="form-control" placeholder="napr. uzivatel@firma.cz">
                        <small style="color:var(--muted);"><?= t('initial_password_mail_hint') ?></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-dvd"><?= t('password') ?> <span class="req">*</span><small style="color:var(--muted);"> (<?= t('min_char') ?> <span id="passwordMinCharsHint">12</span> <?= t('characters') ?>)</small></label>
                        <div class="password-field">
                            <input type="password" name="password" id="password" class="form-control">
                            <button
                                type="button"
                                class="password-toggle-btn js-password-toggle"
                                data-target="password"
                                aria-label="<?= t('password') ?>"
                                title="<?= t('password') ?>"
                            ><i class="bi bi-eye"></i></button>
                        </div>
                        <small style="color:var(--muted);"><?= t('filled_only_when_changing') ?></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-dvd">Role <span class="req">*</span></label>
                        <div id="roles" class="roles-chip-grid">
                            <?php if (!$isTraceabilityManager): ?>
                            <div>
                                <input class="role-chip-input" type="checkbox" id="role_superadmin" name="roles[]" value="superadmin">
                                <label class="role-chip-label" for="role_superadmin"><?= t('role_label_superadmin') ?></label>
                            </div>
                            <div>
                                <input class="role-chip-input" type="checkbox" id="role_admin" name="roles[]" value="admin">
                                <label class="role-chip-label" for="role_admin">Admin (vše jako původní superadmin)</label>
                            </div>
                            <div>
                                <input class="role-chip-input" type="checkbox" id="role_write" name="roles[]" value="write">
                                <label class="role-chip-label" for="role_write"><?= t('role_label_write') ?></label>
                            </div>
                            <div>
                                <input class="role-chip-input" type="checkbox" id="role_traceability" name="roles[]" value="traceability">
                                <label class="role-chip-label" for="role_traceability"><?= t('role_label_traceability') ?></label>
                            </div>
                            <div>
                                <input class="role-chip-input" type="checkbox" id="role_rework" name="roles[]" value="rework">
                                <label class="role-chip-label" for="role_rework"><?= t('role_label_rework') ?></label>
                            </div>
                            <div>
                                <input class="role-chip-input" type="checkbox" id="role_rework_admin" name="roles[]" value="rework_admin">
                                <label class="role-chip-label" for="role_rework_admin"><?= t('role_label_rework_admin') ?></label>
                            </div>
                            <div>
                                <input class="role-chip-input" type="checkbox" id="role_serizovac" name="roles[]" value="serizovac">
                                <label class="role-chip-label" for="role_serizovac"><?= t('role_label_serizovac') ?></label>
                            </div>
                            <div>
                                <input class="role-chip-input" type="checkbox" id="role_serizovac_admin" name="roles[]" value="serizovac_admin">
                                <label class="role-chip-label" for="role_serizovac_admin"><?= t('role_label_serizovac_admin') ?></label>
                            </div>
                            <?php endif; ?>
                            <div>
                                <input class="role-chip-input" type="checkbox" id="role_writer" name="roles[]" value="writer">
                                <label class="role-chip-label" for="role_writer"><?= t('role_label_writer') ?></label>
                            </div>
                            <div>
                                <input class="role-chip-input" type="checkbox" id="role_read" name="roles[]" value="read">
                                <label class="role-chip-label" for="role_read"><?= t('role_label_read') ?></label>
                            </div>
                        </div>
                        <small style="color:var(--muted);">Kliknutím vyberete více rolí</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-dvd">
                            <input type="checkbox" name="active" id="active" value="1" checked>
                            <?= t('active') ?> <?= t('username') ?>
                        </label>
                    </div>

                    <div id="editActionsGroup" class="mb-2" style="display:none;border-top:1px solid rgba(255,255,255,.08);padding-top:.75rem;">
                        <div style="font-size:.78rem;color:var(--muted);margin-bottom:.45rem;"><?= t('action') ?></div>
                        <div class="d-flex flex-wrap gap-2">
                            <button
                                type="button"
                                id="modalResetPasswordBtn"
                                class="btn btn-sm"
                                style="background:none;border:1px solid var(--border);color:var(--warn);padding:.35rem .55rem;border-radius:6px;cursor:pointer;"
                                title="<?= t('reset_password_to_initial') ?>"
                            >
                                <i class="bi bi-arrow-clockwise me-1"></i><?= t('reset_password_to_initial') ?>
                            </button>
                            <button
                                type="button"
                                id="modalResendInitialMailBtn"
                                class="btn btn-sm"
                                style="background:none;border:1px solid var(--border);color:var(--accent);padding:.35rem .55rem;border-radius:6px;cursor:pointer;"
                                title="<?= t('resend_initial_mail') ?>"
                            >
                                <i class="bi bi-envelope me-1"></i><?= t('resend_initial_mail') ?>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid rgba(255,255,255,.05);">
                    <button type="button" class="btn" style="background:rgba(255,255,255,.05);border:1px solid var(--border);color:var(--text);padding:.4rem .8rem;border-radius:6px;cursor:pointer;" data-bs-dismiss="modal"><?= t('cancel') ?></button>
                    <button type="submit" class="btn-dvd"><?= t('save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="post" id="modalResetPasswordForm" style="display:none;">
    <input type="hidden" name="action" value="reset_password">
    <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
    <input type="hidden" name="id" id="modalResetPasswordUserId">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function initPasswordToggles() {
    const toggles = document.querySelectorAll('.js-password-toggle');
    toggles.forEach((toggle) => {
        const targetId = toggle.getAttribute('data-target');
        const input = targetId ? document.getElementById(targetId) : null;
        const icon = toggle.querySelector('i');
        if (!input || !icon || toggle.dataset.bound === '1') return;

        toggle.dataset.bound = '1';
        toggle.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            icon.classList.toggle('bi-eye', !show);
            icon.classList.toggle('bi-eye-slash', show);
        });
    });
}

function resetForm() {
    document.getElementById('userForm').reset();
    document.getElementById('userId').value = '';
    document.getElementById('username').value = '';
    document.getElementById('skutecneJmeno').value = '';
    document.getElementById('email').value = '';
    document.getElementById('emailGroup').style.display = '';
    document.getElementById('password').value = '';
    setSelectedRoles(['<?= $isTraceabilityManager ? 'writer' : 'write' ?>']);
    document.getElementById('active').checked = true;
    document.getElementById('password').required = true;
    document.getElementById('modalTitle').textContent = '<?= t('new') ?> <?= t('username') ?>';
    document.getElementById('editActionsGroup').style.display = 'none';
    document.getElementById('modalResetPasswordUserId').value = '';
    const passIcon = document.querySelector('.js-password-toggle[data-target="password"] i');
    if (passIcon) {
        passIcon.classList.add('bi-eye');
        passIcon.classList.remove('bi-eye-slash');
    }
    updatePasswordMinHint();
}

function editUser(user) {
    document.getElementById('userId').value = user.id;
    document.getElementById('username').value = user.username;
    document.getElementById('skutecneJmeno').value = user.skutecneJmeno || '';
    document.getElementById('email').value = '';
    document.getElementById('emailGroup').style.display = 'none';
    document.getElementById('password').value = '';
    document.getElementById('password').required = false;
    setSelectedRoles(Array.isArray(user.roles) && user.roles.length ? user.roles : [user.primary_role || 'read']);
    document.getElementById('active').checked = user.active == 1;
    const displayName = user.skutecneJmeno || user.username;
    document.getElementById('modalTitle').textContent = '<?= t('edit_title') ?>: ' + displayName;
    document.getElementById('editActionsGroup').style.display = '';
    document.getElementById('modalResetPasswordUserId').value = String(user.id || '');
    const passIcon = document.querySelector('.js-password-toggle[data-target="password"] i');
    if (passIcon) {
        passIcon.classList.add('bi-eye');
        passIcon.classList.remove('bi-eye-slash');
    }
    updatePasswordMinHint();
    const modal = new bootstrap.Modal(document.getElementById('addModal'));
    modal.show();
}

function updatePasswordMinHint() {
    const hintEl = document.getElementById('passwordMinCharsHint');
    if (!hintEl) return;
    const selectedRoles = getSelectedRoles();
    const hasShortPasswordRole = selectedRoles.some((role) => role === 'writer' || role === 'read' || role === 'rework' || role === 'rework_admin' || role === 'serizovac' || role === 'serizovac_admin');
    const minLen = hasShortPasswordRole ? 6 : 12;
    hintEl.textContent = String(minLen);
}

function setSelectedRoles(roles) {
    const roleInputs = document.querySelectorAll('input[name="roles[]"]');
    if (!roleInputs.length) return;
    const selected = new Set((roles || []).map((role) => String(role || '').toLowerCase()));
    roleInputs.forEach((input) => {
        input.checked = selected.has(String(input.value).toLowerCase());
    });
    if (getSelectedRoles().length === 0) {
        const firstInput = roleInputs[0];
        if (firstInput) firstInput.checked = true;
    }
}

function getSelectedRoles() {
    return Array.from(document.querySelectorAll('input[name="roles[]"]:checked')).map((input) => input.value);
}

function confirmDeleteUser(button) {
    document.getElementById('deleteUserId').value = button.dataset.deleteUserId || '';
    document.getElementById('deleteUserName').textContent = button.dataset.deleteUsername || '';
    const modal = new bootstrap.Modal(document.getElementById('deleteUserModal'));
    modal.show();
}

function openResendInitialMailModal(button) {
    document.getElementById('resendInitialMailUserId').value = button.dataset.resendUserId || '';
    document.getElementById('resendInitialMailUserName').textContent = button.dataset.resendUsername || '';
    document.getElementById('resendInitialMailEmail').value = '';
    const modal = new bootstrap.Modal(document.getElementById('resendInitialMailModal'));
    modal.show();
}

document.getElementById('modalResetPasswordBtn').addEventListener('click', () => {
    const userId = document.getElementById('userId').value;
    const username = document.getElementById('username').value;
    if (!userId) return;
    if (!confirm('<?= t('confirm_reset_password') ?>')) return;
    document.getElementById('modalResetPasswordUserId').value = userId;
    document.getElementById('modalResetPasswordForm').submit();
});

document.getElementById('modalResendInitialMailBtn').addEventListener('click', () => {
    const userId = document.getElementById('userId').value;
    const username = document.getElementById('username').value;
    if (!userId) return;

    document.getElementById('resendInitialMailUserId').value = userId;
    document.getElementById('resendInitialMailUserName').textContent = username || '';
    document.getElementById('resendInitialMailEmail').value = '';

    const editModalEl = document.getElementById('addModal');
    const editModal = bootstrap.Modal.getInstance(editModalEl);
    if (editModal) {
        editModal.hide();
    }

    const resendModal = new bootstrap.Modal(document.getElementById('resendInitialMailModal'));
    resendModal.show();
});

initPasswordToggles();
document.querySelectorAll('input[name="roles[]"]').forEach((input) => {
    input.addEventListener('change', updatePasswordMinHint);
});
updatePasswordMinHint();
</script>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>

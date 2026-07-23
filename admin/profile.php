<?php
// admin/profile.php – Profil přihlášeného uživatele
require_once dirname(__DIR__) . '/includes/config.php';
requireLogin();

// Načíst uživatele z databáze podle přihlášeného uživatele
$username = $_SESSION['admin_user'] ?? '';
if (!$username) {
    redirect(APP_URL . '/admin/login.php');
}

$user = db_row('SELECT id, username, skutecneJmeno, password_changed_at FROM users WHERE username=?', [$username]);
if (!$user) {
    redirect(APP_URL . '/admin/login.php');
}
$userId = (int)$user['id'];

$isFirstLogin = (int)get('first_login', 0) === 1 && !$user['password_changed_at'];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $skutecneJmeno = trim(post('skutecneJmeno'));
    $currentPassword = post('currentPassword');
    $newPassword = post('newPassword');
    $newPasswordConfirm = post('newPasswordConfirm');
    
    $errors = [];
    
    // Změna hesla
    $passwordChangeRequired = $isFirstLogin || !empty($newPassword);
    if ($passwordChangeRequired) {
        // Při prvním přihlášení není třeba poskytovat staré heslo
        if (!$isFirstLogin && empty($currentPassword)) {
            $errors[] = t('must_provide_current');
        } elseif (!$isFirstLogin) {
            $userCheck = db_row('SELECT pass_hash FROM users WHERE id=?', [$userId]);
            if (!password_verify($currentPassword, $userCheck['pass_hash'])) {
                $errors[] = t('wrong_password');
            }
        }
        
        // Validace nového hesla
        if (empty($newPassword)) {
            $errors[] = t('password_required');
        } elseif (strlen($newPassword) < 12) {
            $errors[] = t('min_password');
        }
        if ($newPassword !== $newPasswordConfirm) {
            $errors[] = t('passwords_dont_match');
        }
    } elseif (!$isFirstLogin) {
        // Pokud není první přihlášení a heslo se nemění, není nic k validaci
    }
    
    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    } else {
        // Uložení změn
        if ($passwordChangeRequired) {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            db()->prepare('UPDATE users SET skutecneJmeno=?, pass_hash=?, password_changed_at=NOW() WHERE id=?')
               ->execute([$skutecneJmeno, $hash, $userId]);
            logAction('PROFILE_UPDATE', 'users', $userId, 
                     ['skutecneJmeno' => $user['skutecneJmeno'] ?? '', 'password_changed' => true],
                     ['skutecneJmeno' => $skutecneJmeno], 
                     $isFirstLogin ? t('first_login_password_set') : t('profile_updated_with_password'));
            
            // Pokud bylo první přihlášení, přesměrovat na výchozí stránku
            if ($isFirstLogin) {
                $defaultPath = in_array(getRole(), ['rework', 'rework_admin'], true)
                    ? '/admin/rework_report.php'
                    : (in_array(getRole(), ['serizovac', 'serizovac_admin'], true)
                        ? '/admin/serizovac_checklist.php'
                        : (in_array(getRole(), ['admin', 'traceability', 'writer', 'read'], true) ? '/admin/traceability_terminal.php' : '/index.php'));
                redirect(APP_URL . $defaultPath);
            }
        } else {
            db()->prepare('UPDATE users SET skutecneJmeno=? WHERE id=?')
               ->execute([$skutecneJmeno, $userId]);
            logAction('PROFILE_UPDATE', 'users', $userId, 
                     ['skutecneJmeno' => $user['skutecneJmeno'] ?? ''],
                     ['skutecneJmeno' => $skutecneJmeno], 
                     t('profile_updated'));
        }
        
        $success = t('profile_save_success');
        $user['skutecneJmeno'] = $skutecneJmeno;
        $isFirstLogin = false;  // Reset флага po uložení
    }
}

$pageTitle = $isFirstLogin ? t('first_login_title') : t('profile');
$activePage = 'profile';
require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-person-circle me-2"></i><?= t('profile') ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content" style="max-width:600px;">
    <?php if ($success): ?>
    <div class="alert" style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--success);border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.85rem">
        ✓ <?= $success ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert" style="background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);color:var(--danger);border-radius:8px;padding:.75rem 1rem;margin-bottom:1rem;font-size:.85rem">
        ⚠ <?= $error ?>
    </div>
    <?php endif; ?>

    <div class="card-dvd">
        <div class="card-body-dvd">
            <form method="post" class="row g-3">
                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                <h5 class="text-muted mb-3"><i class="bi bi-info-circle me-2"></i><?= t('basic_info') ?></h5>
                
                <div class="col-12">
                    <label class="form-label-dvd"><?= t('username') ?></label>
                    <input type="text" class="form-control" value="<?= h((string)($user['username'] ?? '')) ?>" disabled style="opacity:0.6;">
                    <small style="color:var(--muted);">​<?= t('cannot_change_login') ?></small>
                </div>

                <div class="col-12">
                    <label class="form-label-dvd"><?= t('real_name') ?></label>
                    <input type="text" name="skutecneJmeno" class="form-control" value="<?= h($user['skutecneJmeno'] ?? '') ?>">
                </div>

                <div class="col-12">
                    <hr style="margin:1rem 0;opacity:0.3;">
                </div>

                <h5 class="text-muted col-12 mb-2"><i class="bi bi-shield-lock me-2"></i><?= t('security') ?></h5>
                
                <?php if ($isFirstLogin): ?>
                <div class="col-12">
                    <div style="background:rgba(255,193,7,.12);border:1px solid rgba(255,193,7,.3);color:var(--warn);border-radius:8px;padding:.75rem 1rem;font-size:.85rem;margin-bottom:1rem">
                        <i class="bi bi-exclamation-triangle me-2"></i><?= t('first_login_prompt') ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!$isFirstLogin): ?>
                <div class="col-12">
                    <label class="form-label-dvd"><?= t('current_pass') ?></label>
                    <div class="password-field">
                        <input type="password" name="currentPassword" id="currentPassword" class="form-control" autocomplete="current-password">
                        <button
                            type="button"
                            class="password-toggle-btn js-password-toggle"
                            data-target="currentPassword"
                            aria-label="<?= t('password') ?>"
                            title="<?= t('password') ?>"
                        ><i class="bi bi-eye"></i></button>
                    </div>
                    <small style="color:var(--muted);"><?= t('required_for_change') ?></small>
                </div>
                <?php endif; ?>

                <div class="col-12">
                    <label class="form-label-dvd"><?= t('new_pass') ?></label>
                    <div class="password-field">
                        <input type="password" name="newPassword" id="newPassword" class="form-control" autocomplete="new-password" <?= $isFirstLogin ? 'required' : '' ?>>
                        <button
                            type="button"
                            class="password-toggle-btn js-password-toggle"
                            data-target="newPassword"
                            aria-label="<?= t('password') ?>"
                            title="<?= t('password') ?>"
                        ><i class="bi bi-eye"></i></button>
                    </div>
                    <small style="color:var(--muted);">​<?= t('min_char') ?> 12 <?= t('characters') ?></small>
                </div>

                <div class="col-12">
                    <label class="form-label-dvd"><?= t('confirm_pass') ?></label>
                    <div class="password-field">
                        <input type="password" name="newPasswordConfirm" id="newPasswordConfirm" class="form-control" autocomplete="new-password" <?= $isFirstLogin ? 'required' : '' ?>>
                        <button
                            type="button"
                            class="password-toggle-btn js-password-toggle"
                            data-target="newPasswordConfirm"
                            aria-label="<?= t('password') ?>"
                            title="<?= t('password') ?>"
                        ><i class="bi bi-eye"></i></button>
                    </div>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn-dvd"><i class="bi bi-check-circle me-2"></i><?= t('save_changes') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(() => {
    const toggles = document.querySelectorAll('.js-password-toggle');
    toggles.forEach((toggle) => {
        const targetId = toggle.getAttribute('data-target');
        const input = targetId ? document.getElementById(targetId) : null;
        const icon = toggle.querySelector('i');
        if (!input || !icon) return;

        toggle.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            icon.classList.toggle('bi-eye', !show);
            icon.classList.toggle('bi-eye-slash', show);
        });
    });
})();
</script>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>

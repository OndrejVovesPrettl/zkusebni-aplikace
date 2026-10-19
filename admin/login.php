<?php
// admin/login.php
require_once dirname(__DIR__) . '/includes/config.php';

$roleDefaultPath = static function (string $role): string {
    return match ($role) {
        'read' => '/admin/zaznamy.php',
        'rework', 'rework_admin' => '/admin/rework_report.php',
        'serizovac', 'serizovac_admin' => '/admin/serizovac_checklist.php',
        'traceability', 'writer' => '/admin/traceability_records.php',
        default => '/index.php', // superadmin, admin, write
    };
};

if (isLoggedIn()) {
    redirect(APP_URL . $roleDefaultPath(getRole()));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken(post('_csrf'))) {
        $error = t('login_error');
    } elseif (!checkLoginRateLimit(post('username'))) {
        $error = t('login_error');
    } else {
        $user = db_row('SELECT * FROM users WHERE username = ? AND active = 1', [post('username')]);
        if ($user && password_verify(post('password'), $user['pass_hash'])) {
            clearLoginFails(post('username'));
            session_regenerate_id(true);
            $userRoles = getUserRolesByUserId((int)$user['id']);
            $primaryRole = getPrimaryRoleFromRoles($userRoles);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user']      = $user['username'];
            $_SESSION['admin_user_id']   = $user['id'];
            $_SESSION['admin_role']      = $primaryRole;
            $_SESSION['admin_roles']     = $userRoles;
            unset($_SESSION['admin_actual_roles'], $_SESSION['role_simulation_roles']);
            logAction('LOGIN', 'user', $user['id'], [], ['username' => $user['username'], 'role' => $primaryRole, 'roles' => implode(',', $userRoles)]);
            // Pokud je první přihlášení (password_changed_at je NULL), přesměrovat na profile.php
            if (!$user['password_changed_at']) {
                redirect(APP_URL . '/admin/profile.php?first_login=1');
            }
            redirect(APP_URL . $roleDefaultPath($primaryRole));
        }
        recordLoginFail(post('username'));
        logAction('LOGIN_FAILED', 'user', null, [], ['username' => post('username')], t('login_bad_credentials'));
        $error = t('login_error');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= $_SESSION['lang']??'cs' ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h(buildDocumentTitle(t('login_title'), t('app_name'))) ?></title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
<style>
body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg)}
.login-box{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:2.5rem 2rem;width:360px;max-width:95vw}
.login-logo{font-family:var(--mono);font-size:.75rem;letter-spacing:.15em;color:var(--accent);text-transform:uppercase;font-weight:600;margin-bottom:1.75rem;text-align:center}
.password-field{position:relative}
.password-field .form-control{padding-right:2.75rem}
.password-toggle-btn{position:absolute;right:.45rem;top:50%;transform:translateY(-50%);display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;border:0;background:transparent;color:var(--muted);border-radius:999px;z-index:3}
.password-toggle-btn:hover{color:var(--text);background:rgba(255,255,255,.06)}
.password-toggle-btn:focus-visible{outline:2px solid var(--accent);outline-offset:1px}
.password-field:focus-within .password-toggle-btn{color:var(--accent)}
</style>
</head>
<body>
<div class="login-box">
    <div class="login-logo"><?= t('login_logo') ?></div>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
        <?php if ($error): ?><div class="alert alert-danger py-2 mb-3" style="font-size:.8rem;background:rgba(248,113,113,.12);border:1px solid rgba(248,113,113,.3);color:var(--danger)"><?= h($error) ?></div><?php endif; ?>
        <div class="mb-3">
            <label class="form-label-dvd"><?= t('username') ?></label>
            <input type="text" name="username" class="form-control" autofocus autocomplete="username" required>
        </div>
        <div class="mb-4">
            <label class="form-label-dvd"><?= t('password') ?></label>
            <div class="password-field">
                <input type="password" name="password" id="loginPassword" class="form-control" autocomplete="current-password" required>
                <button
                    type="button"
                    class="password-toggle-btn js-password-toggle"
                    data-target="loginPassword"
                    aria-label="<?= t('password') ?>"
                    title="<?= t('password') ?>"
                ><i class="bi bi-eye"></i></button>
            </div>
        </div>
        <button type="submit" class="btn-dvd w-100"><?= t('login') ?></button>
        <div class="mt-3 text-center">
            <a href="<?= APP_URL ?>/index.php" style="font-size:.75rem;color:var(--muted);text-decoration:none">← <?= t('nav_form') ?></a>
        </div>
    </form>
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
</body>
</html>

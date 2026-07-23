<?php
require_once dirname(__DIR__) . '/includes/config.php';

if (isTraceabilityKioskLoggedIn()) {
    redirect(APP_URL . '/admin/traceability_kiosk_project.php');
}

$error = '';
$ok = '';
$themeOverride = '';
$loginTitle = (string)t('traceability_kiosk_login_title');
$loginTitleFirst = function_exists('mb_substr') ? (string)mb_substr($loginTitle, 0, 1, 'UTF-8') : (string)substr($loginTitle, 0, 1);
$loginTitleRest = function_exists('mb_substr') ? (string)mb_substr($loginTitle, 1, null, 'UTF-8') : (string)substr($loginTitle, 1);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken((string)post('_csrf'))) {
        $error = t('traceability_kiosk_bad_login');
    } else {
        $rawValue = trim((string)(post('kiosk_access_code') !== null && post('kiosk_access_code') !== '' ? post('kiosk_access_code') : post('kiosk_password')));
        $command = strtolower($rawValue);

        if (in_array($command, ['en', 'cz', 'ua'], true)) {
            $lang = match ($command) {
                'en' => 'en',
                'ua' => 'uk',
                default => 'cs',
            };
            LanguageLoader::reload($lang);
            $ok = t('traceability_kiosk_lang_switched');
        } elseif (in_array($command, ['light', 'dark'], true)) {
            $_SESSION['trace_kiosk_theme'] = $command;
            $themeOverride = $command;
            $ok = t('traceability_kiosk_theme_switched');
        } else {
            $user = loginTraceabilityKioskByPassword($rawValue);
            if ($user !== false) {
                redirect(APP_URL . '/admin/traceability_kiosk_project.php');
            }
            $error = t('traceability_kiosk_bad_login');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= h($_SESSION['lang'] ?? 'cs') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h(buildDocumentTitle(t('traceability_kiosk_login_title'))) ?></title>
<script>
(() => {
    try {
        const forcedTheme = '<?= h($themeOverride) ?>';
        const savedTheme = localStorage.getItem('dvd_theme');
        const sessionTheme = '<?= h((string)($_SESSION['trace_kiosk_theme'] ?? '')) ?>';
        const theme = (forcedTheme === 'light' || forcedTheme === 'dark')
            ? forcedTheme
            : ((savedTheme === 'light' || savedTheme === 'dark') ? savedTheme : ((sessionTheme === 'light' || sessionTheme === 'dark') ? sessionTheme : 'dark'));
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('dvd_theme', theme);
    } catch (e) {
        document.documentElement.setAttribute('data-theme', 'dark');
    }
})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css?v=<?= urlencode(APP_VERSION) ?>">
<style>
html,body{height:100%;margin:0;font-family:var(--font);background:var(--bg);color:var(--text)}
.kiosk-wrap{min-height:100%;display:flex;align-items:center;justify-content:center;padding:2rem}
.kiosk-card{width:min(780px,96vw);background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:3rem 2rem;text-align:center}
.kiosk-title{font-size:2.2rem;font-weight:700;letter-spacing:.02em;margin-bottom:1rem}
.kiosk-title-admin-link,.kiosk-title-admin-link:visited{color:inherit;text-decoration:none}
.kiosk-title-admin-link:hover,.kiosk-title-admin-link:focus{color:inherit;text-decoration:none;outline:none}
.kiosk-sub{font-size:1rem;color:var(--muted);margin-bottom:2rem}
.kiosk-input{font-size:1.8rem;text-align:center;padding:.8rem 1rem;border-radius:12px;border:1px solid var(--border2);background:var(--bg);color:var(--text);width:min(560px,92%)}
.kiosk-secret-input{-webkit-text-security:disc}
.kiosk-btn{margin-top:1rem;font-size:1.1rem;padding:.6rem 1.2rem;border-radius:10px;border:1px solid var(--border2);background:rgba(255,255,255,.03);color:var(--text)}
.alert-kiosk{max-width:560px;margin:0 auto 1rem auto;background:#3f1d1d;color:#fecaca;border:1px solid #7f1d1d;padding:.65rem .8rem;border-radius:10px}
.ok-kiosk{max-width:560px;margin:0 auto 1rem auto;background:#123123;color:#86efac;border:1px solid #166534;padding:.65rem .8rem;border-radius:10px}
</style>
</head>
<body>
<div class="kiosk-wrap">
    <div class="kiosk-card">
        <div class="kiosk-title"><a class="kiosk-title-admin-link" href="<?= APP_URL ?>/index.php"><?= h($loginTitleFirst) ?></a><?= h($loginTitleRest) ?></div>
        <div class="kiosk-sub"><?= h(t('traceability_kiosk_login_help')) ?></div>

        <?php if ($ok !== ''): ?>
            <div class="ok-kiosk">✓ <?= h($ok) ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert-kiosk"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off" data-lpignore="true" data-1p-ignore="true">
            <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
            <div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;opacity:0;pointer-events:none;">
                <input type="text" name="fake_username" autocomplete="username" tabindex="-1" aria-hidden="true">
            </div>
            <div style="display:flex;justify-content:center;">
                <div style="width:min(560px,92%);">
                    <input
                        type="text"
                        name="kiosk_access_code"
                        id="kioskAccessCode"
                        class="kiosk-input kiosk-secret-input"
                        autofocus
                        autocomplete="off"
                        inputmode="text"
                        autocapitalize="off"
                        autocorrect="off"
                        spellcheck="false"
                        data-lpignore="true"
                        data-1p-ignore="true"
                        data-form-type="other"
                        readonly
                        required
                    >
                </div>
            </div>
            <div>
                <button type="submit" class="kiosk-btn"><?= h(t('traceability_kiosk_continue')) ?></button>
            </div>
        </form>
    </div>
</div>
<script>
(() => {
    const input = document.querySelector('input[name="kiosk_access_code"]');
    if (!input) return;

    const unlockInput = () => {
        if (input.hasAttribute('readonly')) {
            input.removeAttribute('readonly');
        }
    };

    const refocusInput = () => {
        window.setTimeout(() => {
            unlockInput();
            input.focus({ preventScroll: true });
        }, 0);
    };

    input.addEventListener('focus', unlockInput, { once: true });

    input.addEventListener('blur', () => {
        // If focus falls back to body (accidental click outside controls), restore input focus.
        window.setTimeout(() => {
            if (document.activeElement === document.body) {
                input.focus({ preventScroll: true });
            }
        }, 80);
    });

    refocusInput();
})();
</script>
</body>
</html>

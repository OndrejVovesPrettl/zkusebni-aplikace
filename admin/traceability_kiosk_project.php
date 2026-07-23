<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireTraceabilityKioskLogin();

$kioskUser = getTraceabilityKioskUser();
$error = '';
$projectTitle = (string)t('traceability_kiosk_project_title');
$projectTitleFirst = function_exists('mb_substr') ? (string)mb_substr($projectTitle, 0, 1, 'UTF-8') : (string)substr($projectTitle, 0, 1);
$projectTitleRest = function_exists('mb_substr') ? (string)mb_substr($projectTitle, 1, null, 'UTF-8') : (string)substr($projectTitle, 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken((string)post('_csrf'))) {
        $error = t('traceability_kiosk_project_invalid');
    } else {
        if ((string)post('action') === 'logout') {
            clearTraceabilityKioskSession();
            redirect(APP_URL . '/admin/traceability_kiosk_login.php');
        }

        $project = strtolower(trim((string)post('project')));
        $project = traceabilityProjectByAlias($project);
        if (isTraceabilityProject($project)) {
            setTraceabilitySelectedProject($project);
            redirect(APP_URL . '/admin/traceability_kiosk_scan.php');
        }
        $error = t('traceability_kiosk_project_invalid');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= h($_SESSION['lang'] ?? 'cs') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h(buildDocumentTitle(t('traceability_kiosk_project_title'))) ?></title>
<script>
(() => {
    try {
        const savedTheme = localStorage.getItem('dvd_theme');
        const theme = (savedTheme === 'light' || savedTheme === 'dark') ? savedTheme : 'dark';
        document.documentElement.setAttribute('data-theme', theme);
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
.kiosk-card{width:min(860px,96vw);background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:3rem 2rem;text-align:center}
.kiosk-title{font-size:2.1rem;font-weight:700;margin-bottom:.8rem}
.kiosk-title-admin-link,.kiosk-title-admin-link:visited{color:inherit;text-decoration:none}
.kiosk-title-admin-link:hover,.kiosk-title-admin-link:focus{color:inherit;text-decoration:none;outline:none}
.kiosk-sub{font-size:1rem;color:var(--muted);margin-bottom:1.4rem}
.kiosk-meta{font-size:1rem;color:var(--text);margin-bottom:1.5rem}
.kiosk-input{font-size:2rem;text-transform:lowercase;text-align:center;padding:.8rem 1rem;border-radius:12px;border:1px solid var(--border2);background:var(--bg);color:var(--text);width:min(580px,92%)}
.kiosk-btn{margin-top:1rem;font-size:1.1rem;padding:.6rem 1.2rem;border-radius:10px;border:1px solid var(--border2);background:rgba(255,255,255,.03);color:var(--text)}
.kiosk-btn-secondary{margin-top:.8rem;font-size:1rem;padding:.5rem 1rem;border-radius:10px;border:1px solid var(--border2);background:transparent;color:var(--text)}
.alert-kiosk{max-width:580px;margin:0 auto 1rem auto;background:#3f1d1d;color:#fecaca;border:1px solid #7f1d1d;padding:.65rem .8rem;border-radius:10px}
</style>
</head>
<body>
<div class="kiosk-wrap">
    <div class="kiosk-card">
        <div class="kiosk-title"><a class="kiosk-title-admin-link" href="<?= APP_URL ?>/index.php"><?= h($projectTitleFirst) ?></a><?= h($projectTitleRest) ?></div>
        <div class="kiosk-sub"><?= h(t('traceability_kiosk_project_help')) ?></div>
        <div class="kiosk-meta">
            <?= h(t('user')) ?>: <strong><?= h($kioskUser['username']) ?></strong> | rights: <strong><?= (int)$kioskUser['rights'] ?></strong>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert-kiosk"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
            <input type="text" name="project" class="kiosk-input" autofocus autocomplete="off" required>
            <div>
                <button type="submit" class="kiosk-btn"><?= h(t('traceability_kiosk_continue')) ?></button>
            </div>
        </form>

        <form method="post">
            <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="kiosk-btn-secondary"><?= h(t('traceability_kiosk_logout')) ?></button>
        </form>
    </div>
</div>
<script>
(() => {
    const input = document.querySelector('input[name="project"]');
    if (!input) return;

    const refocusInput = () => {
        window.setTimeout(() => {
            input.focus({ preventScroll: true });
        }, 0);
    };

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

<?php
// includes/layout.php – Shared navigation + head
$adminPage ??= false;
$pageTitle ??= t('app_name');
$activePage ??= '';
$lang = $_SESSION['lang'] ?? 'cs';
$baseUrl = APP_URL;
$mainCssPath = __DIR__ . '/../assets/css/main.css';
$mainCssVersion = is_file($mainCssPath) ? (string)filemtime($mainCssPath) : APP_VERSION;
$sidebarSectionsState = isLoggedIn() ? getCurrentUserSidebarSectionsState() : [];
$appClientConfig = [
	'baseUrl' => $baseUrl,
	'csrfToken' => generateCsrfToken(),
	'sidebarSections' => $sidebarSectionsState,
];

// Automaticky detekovat, zda se jedná o admin stránku
if (!$adminPage && strpos($_SERVER['PHP_SELF'] ?? '', '/admin/') !== false) {
    $adminPage = true;
}

// Kontrola povinné změny hesla – pokud je uživatel přihlášen a je v admin sekci
if (isLoggedIn() && $adminPage) {
    requirePasswordChange();
}

// Helper for language switcher active state
$langClass = fn($l) => $lang === $l ? 'fw-bold text-decoration-underline' : 'text-decoration-none';
$langColor = fn($l) => $lang === $l ? 'var(--accent)' : 'var(--muted)';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h(buildDocumentTitle((string)$pageTitle, t('app_name'))) ?></title>
<script>
(() => {
	try {
		const savedTheme = localStorage.getItem('dvd_theme');
		const theme = (savedTheme === 'light' || savedTheme === 'dark') ? savedTheme : 'dark';
		document.documentElement.setAttribute('data-theme', theme);
		if (localStorage.getItem('dvd_sidebar_compact') === '1') {
			document.documentElement.classList.add('sidebar-compact');
		}
	} catch (e) {
		document.documentElement.setAttribute('data-theme', 'dark');
	}
})();
window.DVD_APP = Object.assign(window.DVD_APP || {}, <?= json_encode($appClientConfig, JSON_UNESCAPED_UNICODE) ?>);
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:ital,wght@0,400;0,600;1,400&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/main.css?v=<?= urlencode($mainCssVersion) ?>">
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
<div class="sidebar-brand" style="text-align:center;">
<div class="logo">⬡ DVD Production</div>
<div class="sub"><?= t('app_name') ?> v<?= APP_VERSION ?></div>
<?php if (isLoggedIn()): ?>
<?php
$role = getRole();
$currentDisplayName = (string)db_val(
    'SELECT COALESCE(NULLIF(skutecneJmeno, ""), username) FROM users WHERE id = ? LIMIT 1',
    [(int)($_SESSION['admin_user_id'] ?? 0)]
);
if ($currentDisplayName === '') {
    $currentDisplayName = (string)($_SESSION['admin_user'] ?? '');
}
?>
<div class="sub" style="margin-top:6px;font-size:.7rem;opacity:.8;">
<i class="bi bi-person-fill" style="font-size:.75rem;"></i> <?= h($currentDisplayName) ?> | <?php 
echo $role === 'superadmin' ? t('role_display_superadmin') : 
     ($role === 'admin' ? 'Admin' : 
     ($role === 'writer' ? t('role_display_writer') : 
     ($role === 'write' ? t('role_display_write') : 
     ($role === 'traceability' ? t('role_display_traceability') : 
	($role === 'serizovac_admin' ? t('role_display_serizovac_admin') :
	($role === 'serizovac' ? t('role_display_serizovac') :
	($role === 'rework_admin' ? t('role_display_rework_admin') : 
     ($role === 'rework' ? t('role_display_rework') : 
	t('role_display_read')))))))));
?>
</div>
<?php endif; ?>
</div>
<nav>
<?php if (isLoggedIn()): ?>
<?php if ((canWrite() || (hasAnyRole(['superadmin', 'admin']) && !hasOnlyRole('rework')))): ?>
<div class="nav-section">DVD</div>
<?php if (canWrite()): ?>
<div class="nav-item"><a href="<?= $baseUrl ?>/index.php" class="<?= $activePage==='form'?'active':'' ?>"><i class="bi bi-pencil-square"></i><?= t('nav_form') ?></a></div>
<?php endif; ?>
<?php if (hasAnyRole(['superadmin', 'admin']) && !hasOnlyRole('rework')): ?>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/zaznamy.php" class="<?= $activePage==='zaznamy'?'active':'' ?>"><i class="bi bi-table"></i><?= t('nav_records') ?></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/zaznamy_pracovnici.php" class="<?= $activePage==='zaznamy_pracovnici'?'active':'' ?>"><i class="bi bi-people"></i><?= t('nav_workers') ?></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/oee.php" class="<?= $activePage==='oee'?'active':'' ?>"><i class="bi bi-speedometer2"></i><?= t('nav_oee') ?></a></div>
<?php if (hasAnyRole(['superadmin', 'admin'])): ?>
<div class="nav-item nav-item-external-split d-flex align-items-stretch">
				<a href="<?= $baseUrl ?>/admin/kt_report_all.php" class="nav-main-link <?= $activePage==='kt_report_all'?'active':'' ?>" style="flex:1 1 auto;"><i class="bi bi-calendar-week"></i><?= t('nav_weekly_production') ?></a>
<a href="<?= $baseUrl ?>/admin/kt_report_all_tv.php" class="nav-external-trigger" target="_blank" rel="noopener noreferrer" title="TV mode"><i class="bi bi-box-arrow-up-right nav-external-indicator" aria-hidden="true"></i></a>
</div>
<?php endif; ?>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/tv.php" class="nav-external-link <?= $activePage==='tv'?'active':'' ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-display"></i><?= t('nav_tv') ?><i class="bi bi-box-arrow-up-right nav-external-indicator" aria-hidden="true"></i></a></div>
<?php endif; ?>
<?php endif; ?>
<?php if (hasAnyRole(['superadmin', 'admin', 'traceability', 'writer', 'read']) && !hasOnlyRole('rework')): ?>
<div class="nav-section">Traceability</div>
<?php if (hasAnyRole(['superadmin', 'admin', 'writer', 'traceability'])): ?>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/traceability_terminal.php" class="<?= $activePage==='traceability_terminal'?'active':'' ?>"><i class="bi bi-upc"></i><?= t('nav_traceability_terminal') ?></a></div>
<?php endif; ?>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/traceability_kiosk_login.php" class="nav-external-link <?= $activePage==='traceability_kiosk'?'active':'' ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-fullscreen"></i><?= t('nav_traceability_kiosk') ?><i class="bi bi-box-arrow-up-right nav-external-indicator" aria-hidden="true"></i></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/traceability.php" class="<?= $activePage==='traceability'?'active':'' ?>"><i class="bi bi-upc-scan"></i><?= t('nav_traceability') ?></a></div>
<?php if (hasAnyRole(['superadmin', 'admin', 'writer', 'traceability'])): ?>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/traceability_records.php" class="<?= $activePage==='traceability_records'?'active':'' ?>"><i class="bi bi-table"></i><?= t('nav_traceability_records') ?></a></div>
<?php endif; ?>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/traceability_shift.php" class="<?= $activePage==='traceability_shift'?'active':'' ?>"><i class="bi bi-clock-history"></i><?= t('nav_traceability_shift') ?></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/traceability_tv.php" class="nav-external-link <?= $activePage==='traceability_tv'?'active':'' ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-tv"></i><?= t('nav_traceability_tv') ?><i class="bi bi-box-arrow-up-right nav-external-indicator" aria-hidden="true"></i></a></div>
<?php endif; ?>
<?php if (hasAnyRole(['superadmin', 'admin', 'rework', 'rework_admin'])): ?>
<div class="nav-section"><?= t('nav_repairs') ?></div>
<?php if (hasAnyRole(['superadmin', 'admin', 'rework_admin'])): ?>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/rework_terminal.php" class="<?= $activePage==='rework_terminal'?'active':'' ?>"><i class="bi bi-upc"></i><?= t('nav_rework_terminal') ?></a></div>
<?php endif; ?>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/rework_kiosk_login.php" class="nav-external-link <?= $activePage==='rework_kiosk'?'active':'' ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-fullscreen"></i><?= t('nav_rework_kiosk') ?><i class="bi bi-box-arrow-up-right nav-external-indicator" aria-hidden="true"></i></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/rework_report.php" class="<?= $activePage==='rework_report'?'active':'' ?>"><i class="bi bi-table"></i><?= t('nav_rework_report') ?></a></div>
<?php if (hasRole('rework_admin')): ?>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/rework_projects.php" class="<?= $activePage==='rework_projects'?'active':'' ?>"><i class="bi bi-diagram-3"></i><?= t('nav_rework_projects') ?></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/rework_reasons.php" class="<?= $activePage==='rework_reasons'?'active':'' ?>"><i class="bi bi-tag"></i><?= t('nav_rework_reasons') ?></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/rework_actions.php" class="<?= $activePage==='rework_actions'?'active':'' ?>"><i class="bi bi-gear"></i><?= t('nav_rework_actions') ?></a></div>
<?php endif; ?>
<?php endif; ?>
<?php if (hasAnyRole(['superadmin', 'admin', 'serizovac', 'serizovac_admin'])): ?>
<div class="nav-section"><?= t('nav_serizovaci') ?></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/serizovac_checklist.php" class="<?= $activePage==='serizovac_checklist'?'active':'' ?>"><i class="bi bi-clipboard-check"></i><?= t('nav_serizovac_checklist') ?></a></div>
<?php if (hasAnyRole(['superadmin', 'admin', 'serizovac_admin'])): ?>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/serizovac_editor.php" class="<?= $activePage==='serizovac_editor'?'active':'' ?>"><i class="bi bi-table"></i><?= t('nav_serizovac_editor') ?></a></div>
<?php endif; ?>
<?php endif; ?>
<?php endif; // isLoggedIn ?>
<?php if (!isLoggedIn() || hasAnyRole(['superadmin', 'admin', 'write', 'read'])): ?>
<div class="nav-section"><?= t('nav_tv_modes') ?></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/tv.php" class="nav-external-link <?= $activePage==='tv'?'active':'' ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-display"></i><?= t('nav_tv') ?><i class="bi bi-box-arrow-up-right nav-external-indicator" aria-hidden="true"></i></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/traceability_tv.php" class="nav-external-link <?= $activePage==='traceability_tv'?'active':'' ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-tv"></i><?= t('nav_traceability_tv') ?><i class="bi bi-box-arrow-up-right nav-external-indicator" aria-hidden="true"></i></a></div>
<?php endif; ?>
<?php if (!isLoggedIn()): ?>
<div class="nav-section">Traceability</div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/traceability_kiosk_login.php" class="nav-external-link <?= $activePage==='traceability_kiosk'?'active':'' ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-fullscreen"></i><?= t('nav_traceability_kiosk') ?><i class="bi bi-box-arrow-up-right nav-external-indicator" aria-hidden="true"></i></a></div>
<div class="nav-section"><?= t('nav_repairs') ?></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/rework_kiosk_login.php" class="nav-external-link <?= $activePage==='rework_kiosk'?'active':'' ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-fullscreen"></i><?= t('nav_rework_kiosk') ?><i class="bi bi-box-arrow-up-right nav-external-indicator" aria-hidden="true"></i></a></div>
<?php endif; ?>
<?php if (isLoggedIn()): ?>
<?php if (canAdmin() || hasRole('serizovac_admin')): ?>
<div class="nav-section">Správa</div>
<?php if (canAdmin()): ?>
<div class="nav-subsection">DVD</div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/pracovisite.php" class="<?= $activePage==='pracovisite'?'active':'' ?>"><i class="bi bi-building"></i><?= t('nav_pracovisite') ?></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/pracovnici.php" class="<?= $activePage==='pracovnici'?'active':'' ?>"><i class="bi bi-people"></i><?= t('nav_workers') ?></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/smeny.php" class="<?= $activePage==='smeny'?'active':'' ?>"><i class="bi bi-clock"></i><?= t('nav_smeny') ?></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/nok_kody.php" class="<?= $activePage==='nok'?'active':'' ?>"><i class="bi bi-x-octagon"></i><?= t('nav_nok') ?></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/prostoj_typy.php" class="<?= $activePage==='prostoj_typy'?'active':'' ?>"><i class="bi bi-tools"></i><?= t('nav_prostoje') ?></a></div>
<?php endif; ?>
<?php if (hasAnyRole(['superadmin', 'admin', 'traceability'])): ?>
<div class="nav-subsection">Traceability</div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/users.php?scope=traceability" class="<?= ($activePage==='writer_users' || ($activePage==='users' && ((string)get('scope', '') === 'traceability' || hasRole('traceability'))) ? 'active' : '') ?>"><i class="bi bi-people"></i><?= t('nav_writer_users') ?></a></div>
<?php endif; ?>
<?php if (canAdmin()): ?>
<div class="nav-subsection">Rework</div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/rework_projects.php" class="<?= $activePage==='rework_projects'?'active':'' ?>"><i class="bi bi-diagram-3"></i><?= t('nav_rework_projects') ?></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/rework_reasons.php" class="<?= $activePage==='rework_reasons'?'active':'' ?>"><i class="bi bi-tag"></i><?= t('nav_rework_reasons') ?></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/rework_actions.php" class="<?= $activePage==='rework_actions'?'active':'' ?>"><i class="bi bi-gear"></i><?= t('nav_rework_actions') ?></a></div>

<?php if (canAdmin() || hasRole('serizovac_admin')): ?>
<div class="nav-subsection"><?= t('nav_serizovaci') ?></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/serizovac_checklist_catalog.php" class="<?= $activePage==='serizovac_checklist_catalog'?'active':'' ?>"><i class="bi bi-list-check"></i><?= t('nav_serizovac_catalog') ?></a></div>
<?php endif; ?>

<div class="nav-subsection">Dashboard</div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/users.php" class="<?= $activePage==='users'?'active':'' ?>"><i class="bi bi-shield-lock"></i><?= t('nav_users') ?></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/import.php" class="<?= $activePage==='import'?'active':'' ?>"><i class="bi bi-file-earmark-arrow-up"></i><?= t('nav_import') ?></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/translations.php" class="<?= $activePage==='translations'?'active':'' ?>"><i class="bi bi-translate"></i><?= t('manage_translations') ?></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/audit_log.php" class="<?= $activePage==='audit_log'?'active':'' ?>"><i class="bi bi-clock-history"></i><?= t('audit_log') ?></a></div>
<?php if (isSuperAdmin()): ?>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/scripts.php" class="<?= $activePage==='scripts'?'active':'' ?>"><i class="bi bi-terminal"></i><?= t('nav_scripts') ?></a></div>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<div class="nav-section"><?= t('account') ?></div>
			<div class="nav-item"><a href="<?= $baseUrl ?>/admin/manual.php" class="<?= $activePage==='manual'?'active':'' ?>"><i class="bi bi-journal-text"></i><?= t('nav_manual') ?></a></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/profile.php" class="<?= $activePage==='profile'?'active':'' ?>"><i class="bi bi-person-circle"></i><?= t('profile') ?></a></div>
<?php else: ?>
<div class="nav-section"><?= t('admin') ?></div>
<div class="nav-item"><a href="<?= $baseUrl ?>/admin/login.php" class="<?= $activePage==='login'?'active':'' ?>"><i class="bi bi-lock"></i><?= t('login') ?></a></div>
<?php endif; ?>
</nav>

<?php
// Build language switcher URLs that preserve other query params
$langUrl = fn($l) => '?' . http_build_query(array_merge($_GET, ['lang' => $l]));
?>
<!-- Language Switcher -->
<div class="sidebar-footer">
<div id="clock-sb" style="font-family:var(--mono);color:var(--accent);font-size:.75rem; margin-bottom: 0.5rem;"></div>
<div class="theme-switch-wrap" title="<?= h(t('theme_light')) ?> / <?= h(t('theme_dark')) ?>">
	<label class="theme-pill-switch" for="theme-toggle-checkbox">
		<input type="checkbox" id="theme-toggle-checkbox" aria-label="<?= h(t('theme_light')) ?> / <?= h(t('theme_dark')) ?>">
		<span class="theme-pill-track">
			<span class="theme-pill-icon theme-pill-icon-sun" aria-hidden="true"><i class="bi bi-brightness-high"></i></span>
			<span class="theme-pill-icon theme-pill-icon-moon" aria-hidden="true"><i class="bi bi-moon"></i></span>
			<span class="theme-pill-thumb" id="theme-pill-thumb" aria-hidden="true"></span>
		</span>
	</label>
</div>
<div class="sidebar-compact-wrap" title="<?= h(t('menu_icons_only')) ?>">
	<label class="theme-pill-switch compact-pill-switch" for="sidebar-compact-toggle">
		<input type="checkbox" id="sidebar-compact-toggle" aria-label="<?= h(t('menu_icons_only')) ?>" onchange="if(window.applySidebarCompact){window.applySidebarCompact(this.checked);}">
		<span class="theme-pill-track compact-pill-track">
			<span class="theme-pill-icon compact-pill-icon-left" aria-hidden="true"><i class="bi bi-layout-sidebar"></i></span>
			<span class="theme-pill-icon compact-pill-icon-right" aria-hidden="true"><i class="bi bi-layout-sidebar-inset"></i></span>
			<span class="theme-pill-thumb compact-pill-thumb" id="compact-pill-thumb" aria-hidden="true"><i class="bi bi-layout-sidebar"></i></span>
		</span>
	</label>
	<div class="compact-switch-label"><?= t('menu_icons_only') ?></div>
</div>
<div class="mt-1 d-flex gap-3 justify-content-center align-items-center language-switcher" style="font-size:1.2rem;">
<a href="<?= $langUrl('cs') ?>" title="Čeština" style="text-decoration:none;opacity:<?= $lang === 'cs' ? '1' : '0.4' ?>;transition:opacity .2s;cursor:pointer;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='<?= $lang === 'cs' ? '1' : '0.4' ?>'">🇨🇿</a>
<a href="<?= $langUrl('uk') ?>" title="Українська" style="text-decoration:none;opacity:<?= $lang === 'uk' ? '1' : '0.4' ?>;transition:opacity .2s;cursor:pointer;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='<?= $lang === 'uk' ? '1' : '0.4' ?>'">🇺🇦</a>
<a href="<?= $langUrl('en') ?>" title="English" style="text-decoration:none;opacity:<?= $lang === 'en' ? '1' : '0.4' ?>;transition:opacity .2s;cursor:pointer;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='<?= $lang === 'en' ? '1' : '0.4' ?>'">🇬🇧</a>
</div>
<?php if (isLoggedIn()): ?>
<div class="mt-2 d-flex justify-content-center">
<a href="<?= $baseUrl ?>/admin/logout.php" class="btn-outline sidebar-logout-link" style="padding:.42rem .78rem;font-size:.76rem;font-weight:600;white-space:nowrap"><i class="bi bi-box-arrow-left logout-icon"></i><span class="logout-label"><?= t('nav_logout') ?></span></a>
</div>
<?php endif; ?>
</div>
</aside>

<div class="main" id="main-content">
<!-- Clock script -->
<script>
(()=>{
const locale='<?= $lang === 'uk' ? 'uk-UA' : ($lang === 'en' ? 'en-GB' : 'cs-CZ') ?>';
const el=document.getElementById('clock-sb');
if(!el) return;

// Function to calculate ISO week number
const getISOWeek=(date)=>{
const d=new Date(Date.UTC(date.getFullYear(),date.getMonth(),date.getDate()));
const dayNum=d.getUTCDay()||7;
d.setUTCDate(d.getUTCDate()+4-dayNum);
const yearStart=new Date(Date.UTC(d.getUTCFullYear(),0,1));
return Math.ceil((((d-yearStart)/86400000)+1)/7);
};

el.innerHTML='<span id="clock-display-sb"></span>';
const displayEl=document.getElementById('clock-display-sb');
let lastDisplay='';

const render=()=>{
const now=new Date();
const day=String(now.getDate()).padStart(2,'0');
const month=String(now.getMonth()+1).padStart(2,'0');
const year=now.getFullYear();
const d=day+'.'+month+'.'+year;
const t=now.toLocaleTimeString(locale,{hour:'2-digit',minute:'2-digit',second:'2-digit'});
const cw=getISOWeek(now);
const displayText=t+' '+d+' KT'+cw;

if(displayEl && displayText!==lastDisplay){
displayEl.textContent=displayText;
lastDisplay=displayText;
}
};

render();
setInterval(render,1000);
})();
</script>

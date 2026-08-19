<?php
// ============================================================
// includes/config.php
// ============================================================

$localSecrets = [];
$localSecretsFile = __DIR__ . '/config.local.php';
if (is_file($localSecretsFile)) {
    $loadedSecrets = require $localSecretsFile;
    if (is_array($loadedSecrets)) {
        $localSecrets = $loadedSecrets;
    }
}

$dbPass = getenv('DVD_DB_PASS');
if (($dbPass === false || $dbPass === '') && isset($localSecrets['DVD_DB_PASS'])) {
    $dbPass = (string)$localSecrets['DVD_DB_PASS'];
}
if ($dbPass === false || $dbPass === '') {
    http_response_code(500);
    die('<pre style="color:red">Missing DB password configuration. Set DVD_DB_PASS environment variable or includes/config.local.php.</pre>');
}

// ── Databáze ─────────────────────────────────────────────────
define('DB_HOST', getenv('DVD_DB_HOST') ?: ($localSecrets['DVD_DB_HOST'] ?? 'localhost'));
define('DB_NAME', getenv('DVD_DB_NAME') ?: ($localSecrets['DVD_DB_NAME'] ?? 'dvd_vyroba'));
define('DB_USER', getenv('DVD_DB_USER') ?: ($localSecrets['DVD_DB_USER'] ?? 'root'));
define('DB_PASS', $dbPass);
define('DB_CHARSET', 'utf8mb4');
define('TRACEABILITY_LOCAL_DB', getenv('TRACEABILITY_LOCAL_DB') ?: ($localSecrets['TRACEABILITY_LOCAL_DB'] ?? 'traceability2'));

// ── Aplikace ──────────────────────────────────────────────────
define('APP_NAME', 'DVD Production Tracking');
define('APP_VERSION', '5.3.3');
define('APP_ROOT', dirname(__DIR__));
define('APP_URL', 'http://10.67.100.69/pisek/v5.3');

// ── Mail / SMTP ─────────────────────────────────────────────
define('MAIL_SMTP_HOST', getenv('DVD_MAIL_SMTP_HOST') ?: ($localSecrets['DVD_MAIL_SMTP_HOST'] ?? ''));
define('MAIL_SMTP_PORT', (int)(getenv('DVD_MAIL_SMTP_PORT') ?: ($localSecrets['DVD_MAIL_SMTP_PORT'] ?? 587)));
define('MAIL_SMTP_USER', getenv('DVD_MAIL_SMTP_USER') ?: ($localSecrets['DVD_MAIL_SMTP_USER'] ?? ''));
define('MAIL_SMTP_PASS', getenv('DVD_MAIL_SMTP_PASS') ?: ($localSecrets['DVD_MAIL_SMTP_PASS'] ?? ''));
define('MAIL_SMTP_ENCRYPTION', strtolower((string)(getenv('DVD_MAIL_SMTP_ENCRYPTION') ?: ($localSecrets['DVD_MAIL_SMTP_ENCRYPTION'] ?? 'tls'))));
define('MAIL_FROM_ADDRESS', getenv('DVD_MAIL_FROM_ADDRESS') ?: ($localSecrets['DVD_MAIL_FROM_ADDRESS'] ?? ''));
define('MAIL_FROM_NAME', getenv('DVD_MAIL_FROM_NAME') ?: ($localSecrets['DVD_MAIL_FROM_NAME'] ?? APP_NAME));
define('MAIL_TEST_TO', getenv('DVD_MAIL_TEST_TO') ?: ($localSecrets['DVD_MAIL_TEST_TO'] ?? ''));
define('MAIL_SMTP_DEBUG', (int)(getenv('DVD_MAIL_SMTP_DEBUG') ?: ($localSecrets['DVD_MAIL_SMTP_DEBUG'] ?? 0)));

// ── Session / Auth ────────────────────────────────────────────
define('SESSION_NAME', 'dvd_session');
define('ADMIN_PASS_HASH', password_hash('admin123', PASSWORD_DEFAULT));

// ── Upload ────────────────────────────────────────────────────
define('UPLOAD_DIR', APP_ROOT . '/uploads/tmp/');

// ── Timezone ─────────────────────────────────────────────────
date_default_timezone_set('Europe/Prague');

// ── Session start ────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,   // true pokud HTTPS
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// ── HTTP Security Headers ─────────────────────────────────────
if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self'");
}

// ── Jazyk ────────────────────────────────────────────────────
// Podpora 3 jazyků: cs, uk, en
$_SESSION['lang'] ??= 'cs';
if (isset($_GET['lang']) && in_array($_GET['lang'], ['cs', 'uk', 'en'])) {
    $_SESSION['lang'] = $_GET['lang'];
    // Zachování query string při přepínání jazyka (volitelné)
    $redirect = strtok($_SERVER['REQUEST_URI'], '?');
    if (!empty($_SERVER['QUERY_STRING'])) {
        parse_str($_SERVER['QUERY_STRING'], $params);
        unset($params['lang']);
        if (!empty($params)) {
            $redirect .= '?' . http_build_query($params);
        }
    }
    header('Location: ' . $redirect);
    exit;
}

// ── PDO singleton ─────────────────────────────────────────────
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $opt = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try { 
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opt); 
        } catch (PDOException $e) {
            http_response_code(503);
            die('<pre style="color:red">DB chyba: '.htmlspecialchars($e->getMessage()).'</pre>');
        }
    }
    return $pdo;
}

function trace_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, TRACEABILITY_LOCAL_DB, DB_CHARSET);
        $opt = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opt);
        } catch (PDOException $e) {
            http_response_code(503);
            die('<pre style="color:red">Traceability DB chyba: '.htmlspecialchars($e->getMessage()).'</pre>');
        }
    }
    return $pdo;
}

// ── Language Loader ───────────────────────────────────────────
require_once APP_ROOT . '/includes/lang_loader.php';
LanguageLoader::init();

// ── Překlad ───────────────────────────────────────────────────
function t(string $key): string {
    return LanguageLoader::get($key);
}

function isPisekTestInstance(): bool {
    return str_contains(strtolower((string)APP_URL), '/pisek/v5.3');
}

function pageTitlePrefix(): string {
    return isPisekTestInstance() ? '• ' : '';
}

function buildDocumentTitle(string $title, ?string $suffix = null, string $separator = ' - '): string {
    $full = pageTitlePrefix() . trim($title);
    if ($suffix !== null && trim($suffix) !== '') {
        $full .= $separator . trim($suffix);
    }
    return $full;
}

function localizedWeekdayNames(): array {
    return [
        1 => t('weekday_monday'),
        2 => t('weekday_tuesday'),
        3 => t('weekday_wednesday'),
        4 => t('weekday_thursday'),
        5 => t('weekday_friday'),
        6 => t('weekday_saturday'),
        7 => t('weekday_sunday'),
    ];
}

// ── Auth helpers ─────────────────────────────────────────────
function isLoggedIn(): bool { return !empty($_SESSION['admin_logged_in']); }
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/admin/login.php');
        exit;
    }
}

const APP_ALLOWED_USER_ROLES = ['superadmin', 'admin', 'write', 'read', 'traceability', 'writer', 'rework', 'rework_admin', 'serizovac', 'serizovac_admin'];
const APP_ROLE_PRIORITY = ['superadmin', 'admin', 'traceability', 'writer', 'write', 'rework_admin', 'rework', 'serizovac_admin', 'serizovac', 'read'];

function sanitizeRoles(array $roles): array {
    $normalized = [];
    foreach ($roles as $role) {
        $value = strtolower(trim((string)$role));
        if ($value !== '' && in_array($value, APP_ALLOWED_USER_ROLES, true)) {
            $normalized[$value] = true;
        }
    }

    $result = array_keys($normalized);
    usort($result, static function (string $a, string $b): int {
        $pa = array_search($a, APP_ROLE_PRIORITY, true);
        $pb = array_search($b, APP_ROLE_PRIORITY, true);
        $pa = $pa === false ? 999 : $pa;
        $pb = $pb === false ? 999 : $pb;
        return $pa <=> $pb;
    });

    return $result;
}

function getPrimaryRoleFromRoles(array $roles): string {
    $normalized = sanitizeRoles($roles);
    return $normalized[0] ?? 'read';
}

function ensureUserRolesTable(): bool {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    try {
        $usersIdType = (string)db_val(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['users', 'id']
        );
        if (!preg_match('/^[a-z0-9_(),\s]+$/i', $usersIdType)) {
            $usersIdType = 'INT';
        }
        if ($usersIdType === '') {
            $usersIdType = 'INT';
        }

        db()->exec(
            'CREATE TABLE IF NOT EXISTS user_roles (
                user_id ' . $usersIdType . ' NOT NULL,
                role VARCHAR(32) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, role),
                INDEX idx_user_roles_role (role),
                CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        db()->exec(
            "INSERT IGNORE INTO user_roles (user_id, role)
             SELECT id, role FROM users WHERE role IS NOT NULL AND role <> ''"
        );

        $checked = true;
    } catch (Throwable $e) {
        error_log('User roles table check failed: ' . $e->getMessage());
        $checked = false;
    }

    return $checked;
}

function getUserRolesByUserId(int $userId): array {
    if ($userId <= 0) {
        return ['read'];
    }

    if (ensureUserRolesTable()) {
        $rows = db_all('SELECT role FROM user_roles WHERE user_id = ?', [$userId]);
        $roles = array_map(static fn(array $row): string => (string)($row['role'] ?? ''), $rows);
        $roles = sanitizeRoles($roles);
        if ($roles !== []) {
            return $roles;
        }
    }

    $legacy = (string)db_val('SELECT role FROM users WHERE id = ? LIMIT 1', [$userId]);
    return sanitizeRoles([$legacy ?: 'read']) ?: ['read'];
}

function saveUserRoles(int $userId, array $roles): void {
    if ($userId <= 0) {
        return;
    }
    $normalized = sanitizeRoles($roles);
    if ($normalized === []) {
        $normalized = ['read'];
    }

    $primaryRole = getPrimaryRoleFromRoles($normalized);
    db()->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$primaryRole, $userId]);

    if (!ensureUserRolesTable()) {
        return;
    }

    db()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$userId]);
    $stmt = db()->prepare('INSERT INTO user_roles (user_id, role) VALUES (?, ?)');
    foreach ($normalized as $role) {
        $stmt->execute([$userId, $role]);
    }
}

function getRoles(): array {
    $roles = $_SESSION['admin_roles'] ?? [];
    if (!is_array($roles) || $roles === []) {
        $legacyRole = (string)($_SESSION['admin_role'] ?? 'read');
        $roles = sanitizeRoles([$legacyRole]);
    }
    if ($roles === []) {
        $roles = ['read'];
    }
    return $roles;
}

function getRole(): string { return getPrimaryRoleFromRoles(getRoles()); }
function hasRole(string $role): bool { return in_array($role, getRoles(), true); }
function hasOnlyRole(string $role): bool {
    $roles = getRoles();
    return count($roles) === 1 && in_array($role, $roles, true);
}
function hasAnyRole(array $roles): bool {
    $wanted = sanitizeRoles($roles);
    if ($wanted === []) {
        return false;
    }
    return array_intersect(getRoles(), $wanted) !== [];
}
function isSuperAdmin(): bool { return hasRole('superadmin'); }

function renderForbiddenPage(string $message): void {
    if (headers_sent() === false) {
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
    }

    $title = t('forbidden_http_title');
    $lang = $_SESSION['lang'] ?? 'cs';
    $locale = $lang === 'uk' ? 'uk-UA' : ($lang === 'en' ? 'en-GB' : 'cs-CZ');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $loginUrl = APP_URL . '/admin/login.php';
    $homeUrl = APP_URL . '/';
    $cssHref = APP_URL . '/assets/css/main.css?v=' . rawurlencode((string)APP_VERSION);
    $langLabel = strtoupper($lang === 'uk' ? 'UA' : $lang);

    $uriPath = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $qsRaw = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY);
    $qs = [];
    if ($qsRaw !== '') {
        parse_str($qsRaw, $qs);
    }
    $langUrl = static function (string $code) use ($uriPath, $qs): string {
        $params = $qs;
        $params['lang'] = $code;
        $query = http_build_query($params);
        return $uriPath . ($query !== '' ? ('?' . $query) : '');
    };

    echo '<!doctype html>';
    echo '<html lang="' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . htmlspecialchars(buildDocumentTitle($title, APP_NAME, ' | '), ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<script>'; 
    echo '(() => { try { const stored = localStorage.getItem("dvd_theme"); const theme = (stored === "light" || stored === "dark") ? stored : "dark"; document.documentElement.setAttribute("data-theme", theme); localStorage.setItem("dvd_theme", theme);} catch (e) { document.documentElement.setAttribute("data-theme", "dark"); } })();';
    echo '</script>';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">';
    echo '<link rel="stylesheet" href="' . htmlspecialchars($cssHref, ENT_QUOTES, 'UTF-8') . '">';
    echo '<style>';
    echo ':root{--bg:#060810;--s:#0d1018;--b:#1a2030;--a:#00e5b0;--a2:#ff6b35;--t:#e8eeff;--m:#4a5570;--f:"IBM Plex Sans",sans-serif;--mo:"IBM Plex Mono",monospace}';
    echo 'html[data-theme="light"]{--bg:#f4f7fb;--s:#ffffff;--b:#d7e0ed;--t:#1a2433;--m:#5d6f88;--a:#007f67;--a2:#d14b1f}';
    echo 'body{min-height:100vh;background:var(--bg);color:var(--t);margin:0;padding:12px;font-family:var(--f);}';
    echo '.forbidden-wrap{max-width:1100px;margin:0 auto;}';
    echo '.topbar{background:var(--s);border:1px solid var(--b);border-radius:12px;padding:8px 14px;display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;}';
    echo '.tv-title{font-size:1.3rem;font-weight:700}';
    echo '.tv-meta{font-size:.82rem;color:var(--m)}';
    echo '.tv-header-right{display:flex;align-items:center;gap:10px;flex-wrap:wrap}';
    echo '.clock-tv{font-family:var(--mo);color:var(--a);font-size:.98rem;white-space:nowrap}';
    echo '.kiosk-tools{display:flex;align-items:center;gap:8px}';
    echo '.kiosk-tools .theme-switch-wrap{margin-bottom:0}';
    echo '.lang-link{color:var(--m);text-decoration:none;font-size:.88rem;line-height:1}';
    echo '.lang-link.active{color:var(--a)}';
    echo '.forbidden-card{max-width:760px;width:100%;background:var(--s);border:1px solid var(--b);border-radius:16px;box-shadow:0 10px 35px rgba(13,31,79,.12);padding:28px;margin:0 auto;}';
    echo '.forbidden-code{display:inline-block;background:var(--a2);color:#fff;font-weight:700;border-radius:999px;padding:6px 12px;margin-bottom:10px;}';
    echo '.forbidden-title{margin:0 0 8px 0;font-size:1.8rem;}';
    echo '.forbidden-text{margin:0 0 18px 0;color:var(--m);}';
    echo '.forbidden-actions{display:flex;gap:10px;flex-wrap:wrap;}';
    echo '.forbidden-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:10px;text-decoration:none;font-weight:600;border:1px solid transparent;}';
    echo '.forbidden-btn-primary{background:var(--a);color:#052822;}';
    echo '.forbidden-btn-secondary{background:transparent;border-color:var(--b);color:var(--t);}';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    echo '<div class="forbidden-wrap">';
    echo '<div class="topbar">';
    echo '<div>';
    echo '<div class="tv-title">' . htmlspecialchars(t('forbidden_http_title'), ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<div class="tv-meta">' . htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($langLabel, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '</div>';
    echo '<div class="tv-header-right">';
    echo '<div class="clock-tv"><span id="clock-time-tv"></span> <span id="clock-date-tv"></span></div>';
    echo '<div class="kiosk-tools">';
    echo '<div class="theme-switch-wrap" title="' . htmlspecialchars(t('theme_light') . ' / ' . t('theme_dark'), ENT_QUOTES, 'UTF-8') . '">';
    echo '<label class="theme-pill-switch" for="theme-toggle-checkbox">';
    echo '<input type="checkbox" id="theme-toggle-checkbox" aria-label="' . htmlspecialchars(t('theme_light') . ' / ' . t('theme_dark'), ENT_QUOTES, 'UTF-8') . '">';
    echo '<span class="theme-pill-track">';
    echo '<span class="theme-pill-icon theme-pill-icon-sun" aria-hidden="true"><i class="bi bi-brightness-high"></i></span>';
    echo '<span class="theme-pill-icon theme-pill-icon-moon" aria-hidden="true"><i class="bi bi-moon"></i></span>';
    echo '<span class="theme-pill-thumb" id="theme-pill-thumb" aria-hidden="true"></span>';
    echo '</span>';
    echo '</label>';
    echo '</div>';
    echo '<a class="lang-link ' . ($lang === 'cs' ? 'active' : '') . '" href="' . htmlspecialchars($langUrl('cs'), ENT_QUOTES, 'UTF-8') . '">CZ</a>';
    echo '<a class="lang-link ' . ($lang === 'en' ? 'active' : '') . '" href="' . htmlspecialchars($langUrl('en'), ENT_QUOTES, 'UTF-8') . '">EN</a>';
    echo '<a class="lang-link ' . ($lang === 'uk' ? 'active' : '') . '" href="' . htmlspecialchars($langUrl('uk'), ENT_QUOTES, 'UTF-8') . '">UA</a>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    echo '<main class="forbidden-card">';
    echo '<span class="forbidden-code">403</span>';
    echo '<h1 class="forbidden-title">' . htmlspecialchars(t('forbidden_title'), ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p class="forbidden-text">' . $safeMessage . '</p>';
    echo '<div class="forbidden-actions">';
    echo '<a class="forbidden-btn forbidden-btn-primary" href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(t('forbidden_go_login'), ENT_QUOTES, 'UTF-8') . '</a>';
    echo '<a class="forbidden-btn forbidden-btn-secondary" href="' . htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(t('forbidden_go_home'), ENT_QUOTES, 'UTF-8') . '</a>';
    echo '</div>';
    echo '</main>';
    echo '</div>';
    echo '<script>';
    echo '(() => { const LOCALE = ' . json_encode($locale) . '; const timeEl = document.getElementById("clock-time-tv"); const dateEl = document.getElementById("clock-date-tv"); const renderClock = () => { const n = new Date(); if (timeEl) timeEl.textContent = n.toLocaleTimeString(LOCALE, {hour:"2-digit", minute:"2-digit", second:"2-digit"}); if (dateEl) dateEl.textContent = n.toLocaleDateString(LOCALE, {day:"2-digit", month:"2-digit", year:"numeric"}); }; renderClock(); setInterval(renderClock, 1000); const applyTheme = (theme) => { document.documentElement.setAttribute("data-theme", theme); localStorage.setItem("dvd_theme", theme); const toggle = document.getElementById("theme-toggle-checkbox"); if (toggle) toggle.checked = (theme === "light"); }; applyTheme(document.documentElement.getAttribute("data-theme") || "dark"); const toggle = document.getElementById("theme-toggle-checkbox"); if (toggle) toggle.addEventListener("change", () => applyTheme(toggle.checked ? "light" : "dark")); })();';
    echo '</script>';
    echo '</body>';
    echo '</html>';
    exit;
}

function requireRole(string $role): void {
    if ($role === 'superadmin') {
        requireAnyRole(['superadmin', 'admin']);
        return;
    }
    if (!isLoggedIn() || !hasRole($role)) {
        renderForbiddenPage(t('forbidden_role_message') . ': ' . $role);
    }
}

function requireAnyRole(array $roles): void {
    if (!isLoggedIn() || !hasAnyRole($roles)) {
        renderForbiddenPage(t('insufficient_role_permission') . ': ' . implode(', ', $roles));
    }
}

function requireSuperAdmin(): void {
    if (!isLoggedIn() || !isSuperAdmin()) {
        renderForbiddenPage(t('superadmin_only'));
    }
}

function canWrite(): bool { return hasAnyRole(['superadmin', 'admin', 'write']); }
function canAdmin(): bool { return hasAnyRole(['superadmin', 'admin']); }
function canTraceabilityEdit(): bool { return hasAnyRole(['superadmin', 'admin', 'writer', 'traceability']); }
function canReworkAccess(): bool { return hasAnyRole(['superadmin', 'admin', 'rework', 'rework_admin']); }
function requireReworkAccess(): void { requireAnyRole(['superadmin', 'admin', 'rework', 'rework_admin']); }
function canSerizovacAccess(): bool { return hasAnyRole(['superadmin', 'admin', 'serizovac', 'serizovac_admin']); }
function canSerizovacEdit(): bool { return hasAnyRole(['superadmin', 'admin', 'serizovac_admin']); }
function requireSerizovacAccess(): void { requireAnyRole(['superadmin', 'admin', 'serizovac', 'serizovac_admin']); }
function requireSerizovacEdit(): void { requireAnyRole(['superadmin', 'admin', 'serizovac_admin']); }

const REWORK_PROJECT_SEED = [
    ['code' => '48W', 'label' => '48W', 'sort_order' => 10],
    ['code' => 'Gen3', 'label' => 'Gen3', 'sort_order' => 20],
];

function getReworkReasonSeed(): array {
    $rows = json_decode('[{"code":"01","label":"demont\u00e1\u017e PCB","sort_order":10},{"code":"02","label":"V\u00fdm\u011bna krytu (ne\u017e zaschne lepidlo)","sort_order":20},{"code":"03","label":"Nedo\u0161roubovan\u00fd","sort_order":30},{"code":"04","label":"Kontaminace lepidlo","sort_order":40},{"code":"05","label":"Rozst\u0159ik","sort_order":50},{"code":"06","label":"P\u0159elepen\u00ed QR","sort_order":60},{"code":"07","label":"V\u00fdm\u011bna komponentu","sort_order":70},{"code":"08","label":"Hot staking","sort_order":80},{"code":"09","label":"Ground plate pozice","sort_order":90},{"code":"10","label":"Lepidlo na kondenz\u00e1toru","sort_order":100},{"code":"11","label":"Termoplo\u0161ka","sort_order":110},{"code":"12","label":"Vrypy","sort_order":120},{"code":"13","label":"Pozice z\u00e1vitu NOK","sort_order":130},{"code":"14","label":"Perli\u010dka na sv\u00e1ru","sort_order":140},{"code":"15","label":"D\u00edra ve sv\u00e1ru","sort_order":150},{"code":"16","label":"DMC k\u00f3d chyb\u00ed/dvojit\u00fd","sort_order":160},{"code":"17","label":"Po\u0161kozen\u00fd 4pin","sort_order":170},{"code":"18","label":"Po\u0161kozen\u00fd d\u00edl","sort_order":180}]', true);
    return is_array($rows) ? $rows : [];
}

function getReworkActionSeed(): array {
    $rows = json_decode('[{"code":"1","label":"Oprava","sort_order":10},{"code":"2","label":"\u0160rot","sort_order":20},{"code":"3","label":"OK","sort_order":30}]', true);
    return is_array($rows) ? $rows : [];
}

function ensureUsersRoleSupportsRework(): bool {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    try {
        $columnType = (string)db_val(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['users', 'role']
        );

        if (
            stripos($columnType, 'enum(') === 0 && (
                stripos($columnType, "'rework'") === false ||
                stripos($columnType, "'rework_admin'") === false ||
                stripos($columnType, "'serizovac'") === false ||
                stripos($columnType, "'serizovac_admin'") === false
            )
        ) {
            db()->exec("ALTER TABLE users MODIFY role ENUM('superadmin','admin','write','read','traceability','writer','rework','rework_admin','serizovac','serizovac_admin') DEFAULT 'read'");
        }

        $checked = true;
    } catch (Throwable $e) {
        error_log('Users role enum check failed: ' . $e->getMessage());
        $checked = false;
    }

    return $checked;
}

function ensureReworkTables(): bool {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    try {
        if (!ensureUsersRoleSupportsRework()) {
            $checked = false;
            return $checked;
        }

        db()->exec(
            'CREATE TABLE IF NOT EXISTS rework_projects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(32) NOT NULL UNIQUE,
                label VARCHAR(150) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        db()->exec(
            'CREATE TABLE IF NOT EXISTS rework_reasons (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(32) NOT NULL UNIQUE,
                label VARCHAR(190) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        db()->exec(
            'CREATE TABLE IF NOT EXISTS rework_actions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(32) NOT NULL UNIQUE,
                label VARCHAR(120) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        db()->exec(
            'CREATE TABLE IF NOT EXISTS rework_entries (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                dmc VARCHAR(80) NOT NULL,
                reason_id INT NOT NULL,
                action_id INT NOT NULL,
                replacement_dmc VARCHAR(80) NULL,
                note TEXT NULL,
                recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                recorded_by_user_id TINYINT UNSIGNED NULL,
                recorded_by_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_rework_entries_recorded_at (recorded_at),
                INDEX idx_rework_entries_dmc (dmc),
                INDEX idx_rework_entries_project (project_id),
                INDEX idx_rework_entries_reason (reason_id),
                INDEX idx_rework_entries_action (action_id),
                INDEX idx_rework_entries_user (recorded_by_user_id),
                CONSTRAINT fk_rework_entries_project FOREIGN KEY (project_id) REFERENCES rework_projects(id),
                CONSTRAINT fk_rework_entries_reason FOREIGN KEY (reason_id) REFERENCES rework_reasons(id),
                CONSTRAINT fk_rework_entries_action FOREIGN KEY (action_id) REFERENCES rework_actions(id),
                CONSTRAINT fk_rework_entries_user FOREIGN KEY (recorded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $seedLookup = static function (string $table, array $rows): void {
            $stmt = db()->prepare(
                'INSERT INTO ' . $table . ' (code, label, sort_order, active) VALUES (?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE label = VALUES(label), sort_order = VALUES(sort_order), active = VALUES(active)'
            );
            foreach ($rows as $row) {
                $stmt->execute([
                    (string)$row['code'],
                    (string)$row['label'],
                    (int)$row['sort_order'],
                ]);
            }
        };

        $seedLookup('rework_projects', REWORK_PROJECT_SEED);
        $seedLookup('rework_reasons', getReworkReasonSeed());
        $seedLookup('rework_actions', getReworkActionSeed());

        $checked = true;
    } catch (Throwable $e) {
        error_log('Rework tables check failed: ' . $e->getMessage());
        $checked = false;
    }

    return $checked;
}

function getReworkProjects(bool $onlyActive = true): array {
    if (!ensureReworkTables()) {
        return [];
    }

    $sql = 'SELECT id, code, label, sort_order, active FROM rework_projects';
    if ($onlyActive) {
        $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY sort_order, code';

    return db_all($sql);
}

function getReworkReasons(bool $onlyActive = true): array {
    if (!ensureReworkTables()) {
        return [];
    }

    $sql = 'SELECT id, code, label, sort_order, active FROM rework_reasons';
    if ($onlyActive) {
        $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY sort_order, code';

    return db_all($sql);
}

function getReworkActions(bool $onlyActive = true): array {
    if (!ensureReworkTables()) {
        return [];
    }

    $sql = 'SELECT id, code, label, sort_order, active FROM rework_actions';
    if ($onlyActive) {
        $sql .= ' WHERE active = 1';
    }
    $sql .= ' ORDER BY sort_order, code';

    return db_all($sql);
}

function createReworkEntry(array $data, ?int $userId = null): int {
    if (!ensureReworkTables()) {
        throw new RuntimeException('Rework storage is not available.');
    }

    $recordedAt = trim((string)($data['recorded_at'] ?? ''));
    if ($recordedAt === '' || DateTime::createFromFormat('Y-m-d H:i:s', $recordedAt) === false) {
        $recordedAt = date('Y-m-d H:i:s');
    }

    $replacementDmc = trim((string)($data['replacement_dmc'] ?? ''));
    $replacementDmc = $replacementDmc !== '' ? $replacementDmc : null;

    $note = trim((string)($data['note'] ?? ''));
    $note = $note !== '' ? $note : null;

    $recordedByUserId = $userId ?: (int)($_SESSION['admin_user_id'] ?? 0);
    $recordedByName = trim((string)db_val(
        'SELECT COALESCE(NULLIF(skutecneJmeno, ""), username) FROM users WHERE id = ? LIMIT 1',
        [$recordedByUserId]
    ));
    if ($recordedByName === '') {
        $recordedByName = (string)($_SESSION['admin_user'] ?? 'unknown');
    }

    $stmt = db()->prepare(
        'INSERT INTO rework_entries (
            project_id, dmc, reason_id, action_id, replacement_dmc, note, recorded_at, recorded_by_user_id, recorded_by_name
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$data['project_id'],
        trim((string)$data['dmc']),
        (int)$data['reason_id'],
        (int)$data['action_id'],
        $replacementDmc,
        $note,
        $recordedAt,
        $recordedByUserId > 0 ? $recordedByUserId : null,
        $recordedByName,
    ]);

    return (int)db()->lastInsertId();
}

function getRecentReworkEntries(int $limit = 20): array {
    if (!ensureReworkTables()) {
        return [];
    }

    $limit = max(1, min(100, $limit));
    return db_all(
        'SELECT re.id,
                rp.code AS project_code,
                rr.code AS reason_code,
                rr.label AS reason_label,
                ra.code AS action_code,
                ra.label AS action_label,
                re.dmc,
                re.replacement_dmc,
                re.note,
                re.recorded_at,
                COALESCE(NULLIF(re.recorded_by_name, ""), NULLIF(u.skutecneJmeno, ""), u.username, "-") AS recorded_by
         FROM rework_entries re
         INNER JOIN rework_projects rp ON rp.id = re.project_id
         INNER JOIN rework_reasons rr ON rr.id = re.reason_id
         INNER JOIN rework_actions ra ON ra.id = re.action_id
         LEFT JOIN users u ON u.id = re.recorded_by_user_id
         ORDER BY re.recorded_at DESC, re.id DESC
         LIMIT ' . $limit
    );
}

function getReworkHourlyStats(int $hours = 9): array {
    if (!ensureReworkTables()) {
        return [];
    }
    $rows = db_all(
        'SELECT HOUR(recorded_at) AS h, COUNT(*) AS cnt
         FROM rework_entries
         WHERE DATE(recorded_at) = CURDATE()
         GROUP BY HOUR(recorded_at)
         ORDER BY HOUR(recorded_at) DESC'
    );
    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['h']] = (int)$r['cnt'];
    }
    $result = [];
    $currentHour = (int)date('H');
    for ($i = 0; $i < $hours; $i++) {
        $h = $currentHour - $i;
        if ($h < 0) {
            break;
        }
        $label = str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':00';
        $result[] = ['label' => $label, 'count' => $map[$h] ?? 0];
    }
    return $result;
}

function getReworkTodayStats(): array {
    if (!ensureReworkTables()) {
        return ['total' => 0, 'by_action' => []];
    }
    $total = (int)db_val('SELECT COUNT(*) FROM rework_entries WHERE DATE(recorded_at) = CURDATE()');
    $byAction = db_all(
        'SELECT ra.code, ra.label, COUNT(re.id) AS cnt
         FROM rework_entries re
         INNER JOIN rework_actions ra ON ra.id = re.action_id
         WHERE DATE(re.recorded_at) = CURDATE()
         GROUP BY ra.id, ra.code, ra.label
         ORDER BY ra.sort_order, ra.code'
    );
    return ['total' => $total, 'by_action' => $byAction];
}

// ── Rework kiosk session helpers ─────────────────────────────────────────────

function isReworkKioskLoggedIn(): bool {
    return !empty($_SESSION['rework_kiosk_user_id']);
}

function getReworkKioskUser(): array {
    return [
        'id'       => (int)($_SESSION['rework_kiosk_user_id'] ?? 0),
        'username' => (string)($_SESSION['rework_kiosk_username'] ?? ''),
    ];
}

function requireReworkKioskLogin(): void {
    if (!isReworkKioskLoggedIn()) {
        redirect(APP_URL . '/admin/rework_kiosk_login.php');
    }
}

function clearReworkKioskSession(): void {
    unset($_SESSION['rework_kiosk_user_id'], $_SESSION['rework_kiosk_username']);
}

function loginReworkKioskByCardCode(string $code): array|false {
    $code = trim($code);
    if ($code === '') {
        return false;
    }
    ensureUsersTraceabilityColumns();
    $user = db_row(
        'SELECT id, username, skutecneJmeno, role, active
         FROM users
         WHERE active = 1 AND traceability_card_code = ?
         LIMIT 1',
        [$code]
    );
    if (!$user) {
        return false;
    }
    $roles = getUserRolesByUserId((int)$user['id']);
    if (array_intersect($roles, ['superadmin', 'admin', 'rework', 'rework_admin']) === []) {
        return false;
    }
    $displayName = trim((string)($user['skutecneJmeno'] ?? ''));
    if ($displayName === '') {
        $displayName = (string)$user['username'];
    }
    $_SESSION['rework_kiosk_user_id'] = (int)$user['id'];
    $_SESSION['rework_kiosk_username'] = $displayName;
    return ['id' => (int)$user['id'], 'username' => $displayName];
}

function mapTraceabilityRightsToRole(int $rights): string {
    return match ($rights) {
        100 => 'superadmin',
        90 => 'writer',
        1 => 'read',
        default => 'read',
    };
}

function mapRoleToTraceabilityRights(string $role): int {
    return match ($role) {
        'superadmin', 'admin' => 100,
        'writer', 'traceability' => 90,
        'read', 'rework', 'rework_admin', 'serizovac', 'serizovac_admin' => 1,
        default => 1,
    };
}

function ensureUsersTraceabilityColumns(): bool {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    try {
        $needed = [
            'traceability_user_id' => 'INT NULL',
            'traceability_rights' => 'TINYINT NULL',
            'traceability_card_code' => 'VARCHAR(64) NULL',
        ];

        foreach ($needed as $column => $definition) {
            $exists = (int)db_val(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['users', $column]
            );
            if ($exists === 0) {
                db()->exec('ALTER TABLE users ADD COLUMN ' . $column . ' ' . $definition);
            }
        }

        $checked = true;
    } catch (Throwable $e) {
        error_log('Traceability users columns check failed: ' . $e->getMessage());
        $checked = false;
    }

    return $checked;
}

function getUnifiedTraceabilityUserOptions(bool $onlyActive = true): array {
    if (!ensureUsersTraceabilityColumns()) {
        return [];
    }

    $sql = 'SELECT traceability_user_id AS id,
                   COALESCE(NULLIF(skutecneJmeno, ""), username) AS username
            FROM users
            WHERE traceability_user_id IS NOT NULL AND traceability_user_id > 0';
    if ($onlyActive) {
        $sql .= ' AND active = 1';
    }
    $sql .= ' ORDER BY COALESCE(NULLIF(skutecneJmeno, ""), username)';

    return db_all($sql);
}

function getTraceabilityUsernamesByIds(array $userIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }

    $map = [];

    // First try to resolve from traceability2.user (primary source for traceability IDs)
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $rows = trace_db_all(
        'SELECT id, username FROM user WHERE id IN (' . $placeholders . ')',
        $ids
    );
    
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        $username = trim((string)($row['username'] ?? ''));
        if ($id > 0 && $username !== '') {
            $map[$id] = $username;
        }
    }

    // For remaining unmapped IDs, try to resolve from dvd_vyroba.users (fallback)
    $unmapped = array_diff($ids, array_keys($map));
    if ($unmapped !== [] && ensureUsersTraceabilityColumns()) {
        $unmappedPlaceholders = implode(',', array_fill(0, count($unmapped), '?'));
        $fallbackRows = db_all(
            'SELECT id, COALESCE(NULLIF(skutecneJmeno, ""), username) AS username
             FROM users
             WHERE id IN (' . $unmappedPlaceholders . ')',
            array_values($unmapped)
        );
        
        foreach ($fallbackRows as $row) {
            $id = (int)($row['id'] ?? 0);
            $username = trim((string)($row['username'] ?? ''));
            if ($id > 0 && $username !== '' && !isset($map[$id])) {
                $map[$id] = $username;
            }
        }
    }

    return $map;
}

function applyTraceabilityUsernames(array $rows, string $idKey = 'userId', string $targetKey = 'username'): array {
    if ($rows === []) {
        return [];
    }

    $ids = array_map(static fn(array $row): int => (int)($row[$idKey] ?? 0), $rows);
    $usernameMap = getTraceabilityUsernamesByIds($ids);

    foreach ($rows as &$row) {
        $uid = (int)($row[$idKey] ?? 0);
        $row[$targetKey] = $usernameMap[$uid] ?? ('#' . $uid);
    }
    unset($row);

    return $rows;
}

/**
 * Display-name overrides for traceability projects.
 * Key = DB table name, value = human-readable label shown in UI.
 * The DB table is NOT renamed; only the visual label changes.
 */
const TRACEABILITY_PROJECT_LABELS = [
    '48w200'   => 'CSL',
    '48w200_2' => 'CSL 2',
];

/**
 * Returns the display label for a project (falls back to the raw DB table name).
 */
function traceabilityProjectLabel(string $project): string {
    return TRACEABILITY_PROJECT_LABELS[$project] ?? $project;
}

/**
 * Reverse-lookup: given a user-typed alias (e.g. "csl") returns the DB table name
 * (e.g. "48w200"). Returns the input unchanged if no alias matches.
 */
function traceabilityProjectByAlias(string $input): string {
    $normalised = strtolower(trim($input));
    foreach (TRACEABILITY_PROJECT_LABELS as $dbName => $label) {
        if (strtolower($label) === $normalised) {
            return $dbName;
        }
    }
    return $input;
}

function getTraceabilityProjects(): array {
    $rows = trace_db_all('SHOW TABLES');
    if (!$rows) {
        return [];
    }

    $firstKey = array_key_first($rows[0]);
    $excluded = ['user', 'rights', 'limits', 'norma'];
    $projects = [];

    foreach ($rows as $row) {
        $tableName = (string)($row[$firstKey] ?? '');
        if ($tableName === '' || in_array($tableName, $excluded, true)) {
            continue;
        }
        $projects[] = $tableName;
    }

    sort($projects);
    return $projects;
}

function isTraceabilityProject(string $project): bool {
    return in_array($project, getTraceabilityProjects(), true);
}

function getTraceabilitySelectedProject(): string {
    $project = trim((string)($_SESSION['traceability_project'] ?? ''));
    if ($project !== '' && isTraceabilityProject($project)) {
        return $project;
    }

    $projects = getTraceabilityProjects();
    return $projects[0] ?? '';
}

function setTraceabilitySelectedProject(string $project): void {
    if (isTraceabilityProject($project)) {
        $_SESSION['traceability_project'] = $project;
    }
}

function getTraceabilityProjectLimits(string $project): array {
    $row = trace_db_row('SELECT project, `limit`, counterOnPallete, sPauzou, bezPauzy FROM limits WHERE project = ? LIMIT 1', [$project]);
    return [
        'project' => (string)($row['project'] ?? $project),
        'limit' => (int)($row['limit'] ?? 0),
        'counter' => (int)($row['counterOnPallete'] ?? 0),
        'with_break' => (string)($row['sPauzou'] ?? ''),
        'without_break' => (string)($row['bezPauzy'] ?? ''),
    ];
}

function setTraceabilityProjectCounter(string $project, int $counter): void {
    $stmt = trace_db()->prepare('UPDATE limits SET counterOnPallete = ? WHERE project = ?');
    $stmt->execute([$counter, $project]);
}

function getTraceabilityUserIdByUsername(string $username): int {
    $username = trim($username);
    if ($username === '') {
        return 0;
    }

    if (!ensureUsersTraceabilityColumns()) {
        return 0;
    }

    $user = db_row(
        'SELECT id, traceability_user_id
         FROM users
         WHERE username = ? OR skutecneJmeno = ?
         ORDER BY CASE WHEN username = ? THEN 0 ELSE 1 END, id ASC
         LIMIT 1',
        [$username, $username, $username]
    );
    if (!$user) {
        return 0;
    }

    $mapped = (int)($user['traceability_user_id'] ?? 0);
    if ($mapped > 0) {
        return $mapped;
    }

    $mapped = (int)($user['id'] ?? 0);
    if ($mapped > 0) {
        db()->prepare('UPDATE users SET traceability_user_id = ? WHERE id = ?')->execute([$mapped, (int)$user['id']]);
    }

    return $mapped;
}

function getTraceabilityFallbackUserIdForSuperAdmin(string $preferredUsername = ''): int {
    $preferredUsername = trim($preferredUsername);
    if (!ensureUsersTraceabilityColumns()) {
        return 0;
    }

    $row = db_row(
        'SELECT id, traceability_user_id
         FROM users
         WHERE active = 1
         ORDER BY CASE
             WHEN username = ? THEN 0
             WHEN username = ? THEN 1
             ELSE 2
         END, id ASC
         LIMIT 1',
        [$preferredUsername, 'admin']
    );

    if (!$row) {
        return 0;
    }

    $mapped = (int)($row['traceability_user_id'] ?? 0);
    if ($mapped > 0) {
        return $mapped;
    }

    $mapped = (int)($row['id'] ?? 0);
    if ($mapped > 0) {
        db()->prepare('UPDATE users SET traceability_user_id = ? WHERE id = ?')->execute([$mapped, (int)$row['id']]);
    }

    return $mapped;
}

function ensureTraceabilityUserForSession(): int {
    $currentUserId = (int)($_SESSION['admin_user_id'] ?? 0);
    if ($currentUserId <= 0 || !ensureUsersTraceabilityColumns()) {
        return 0;
    }

    $userRow = db_row(
        'SELECT id, username, skutecneJmeno, role, traceability_user_id FROM users WHERE id = ? LIMIT 1',
        [$currentUserId]
    );
    if (!$userRow) {
        return 0;
    }

    // If already mapped and exists in traceability DB, return it
    $mapped = (int)($userRow['traceability_user_id'] ?? 0);
    if ($mapped > 0 && traceabilityUserExists($mapped)) {
        return $mapped;
    }

    // Try to find by username or display name in the traceability DB
    $username = trim((string)($userRow['username'] ?? ''));
    $displayName = trim((string)($userRow['skutecneJmeno'] ?? ''));

    foreach (array_filter([$displayName, $username]) as $tryName) {
        $found = (int)trace_db_val(
            'SELECT id FROM user WHERE username = ? LIMIT 1',
            [$tryName]
        );
        if ($found > 0) {
            db()->prepare('UPDATE users SET traceability_user_id = ? WHERE id = ?')->execute([$found, $currentUserId]);
            return $found;
        }
    }

    // Auto-create the user in the traceability DB so admin can always scan
    $label = $displayName !== '' ? $displayName : $username;
    $rights = mapRoleToTraceabilityRights((string)($userRow['role'] ?? 'admin'));
    try {
        $stmt = trace_db()->prepare('INSERT INTO user (username, password, rights) VALUES (?, ?, ?)');
        $stmt->execute([$label, md5(''), $rights]);
        $newId = (int)trace_db()->lastInsertId();
        if ($newId > 0) {
            db()->prepare('UPDATE users SET traceability_user_id = ? WHERE id = ?')->execute([$newId, $currentUserId]);
            return $newId;
        }
    } catch (\Throwable $e) {
        error_log('ensureTraceabilityUserForSession failed: ' . $e->getMessage());
    }

    return 0;
}

function getCurrentTraceabilityUserId(): int {
    $currentUserId = (int)($_SESSION['admin_user_id'] ?? 0);
    if ($currentUserId > 0) {
        // This also auto-creates the user in the traceability DB if needed
        $traceId = ensureTraceabilityUserForSession();
        if ($traceId > 0) {
            return $traceId;
        }
    }

    $currentUsername = (string)($_SESSION['admin_user'] ?? '');
    $traceUserId = getTraceabilityUserIdByUsername($currentUsername);
    if ($traceUserId > 0 && traceabilityUserExists($traceUserId)) {
        return $traceUserId;
    }

    return 0;
}

function getTraceabilityHourlyProduction(string $project, int $hours = 9): array {
    $items = [];
    $tz = new DateTimeZone('Europe/Prague');
    $now = new DateTimeImmutable('now', $tz);

    for ($offset = $hours - 1; $offset >= 0; $offset--) {
        $point = $now->modify('-' . $offset . ' hours');
        $hourStart = $point->setTime((int)$point->format('H'), 0, 0);
        $hourEnd = $point->setTime((int)$point->format('H'), 59, 59);
        $hour = $hourStart->format('H');
        $nextHour = $hourStart->modify('+1 hour')->format('H');

        $start = $hourStart->format('Y-m-d H:i:s');
        $end = $hourEnd->format('Y-m-d H:i:s');
        $count = (int)trace_db_val('SELECT COUNT(*) FROM `' . $project . '` WHERE `timestamp` BETWEEN ? AND ? AND snDil <> ?', [$start, $end, 'ResetPalety']);

        $items[] = [
            'hour' => $hour,
            'label' => $hour . ':00 - ' . $nextHour . ':00',
            'count' => $count,
        ];
    }

    return $items;
}

function getTraceabilityProjectRecentRows(string $project, int $limit = 15): array {
    $rows = trace_db_all(
        'SELECT p.id, p.snDil, p.`timestamp`, p.userId
         FROM `' . $project . '` p
         ORDER BY p.id DESC
         LIMIT ' . max(1, (int)$limit)
    );

    return applyTraceabilityUsernames($rows);
}

function getTraceabilityProjectActiveWorker(string $project, int $maxAgeSeconds = 3600): ?string {
    if (!isTraceabilityProject($project)) {
        return null;
    }

    $row = trace_db_row(
        'SELECT p.`timestamp`, p.userId
         FROM `' . $project . '` p
         ORDER BY p.id DESC
         LIMIT 1'
    );

    if (!$row) {
        return null;
    }

    $mappedRows = applyTraceabilityUsernames([$row]);
    $username = trim((string)($mappedRows[0]['username'] ?? ''));
    $timestamp = trim((string)($row['timestamp'] ?? ''));
    if ($username === '' || $timestamp === '') {
        return null;
    }

    $tz = new DateTimeZone('Europe/Prague');
    $last = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $timestamp, $tz);
    if (!$last) {
        return null;
    }

    $now = new DateTimeImmutable('now', $tz);
    if (($now->getTimestamp() - $last->getTimestamp()) > max(0, $maxAgeSeconds)) {
        return null;
    }

    return $username;
}

function traceabilityProjectHasRecentActivity(string $project, int $hours = 12): bool {
    if (!isTraceabilityProject($project)) {
        return false;
    }

    $hours = max(1, $hours);
    $cnt = trace_db_val(
        'SELECT COUNT(*) FROM `' . $project . '` WHERE `timestamp` >= DATE_SUB(NOW(), INTERVAL ' . $hours . ' HOUR)'
    );
    return (int)$cnt > 0;
}

function getTraceabilityShiftOverview(array $projects = ['gen3', '48w', 'gen3manual']): array {
    $overview = [];
    foreach ($projects as $project) {
        if (!isTraceabilityProject($project)) {
            continue;
        }
        $overview[$project] = getTraceabilityHourlyProduction($project, 9);
    }
    return $overview;
}

function traceabilityProjectHasSerial(string $project, string $serial): bool {
    return (int)trace_db_val('SELECT COUNT(*) FROM `' . $project . '` WHERE snDil = ?', [$serial]) > 0;
}

function traceabilityUserExists(int $userId): bool {
    if ($userId <= 0) {
        return false;
    }
    $exists = trace_db_val('SELECT 1 FROM user WHERE id = ? LIMIT 1', [$userId]);
    return $exists !== false;
}

function insertTraceabilityScan(string $project, string $serial, int $traceUserId): void {
    $stmt = trace_db()->prepare('INSERT INTO `' . $project . '` (snDil, `timestamp`, userId) VALUES (?, NOW(), ?)');
    $stmt->execute([$serial, $traceUserId]);
}

function getTraceabilityLastResetId(string $project): int
{
    $lastResetId = trace_db_val(
        'SELECT id FROM `' . $project . '` WHERE snDil = ? ORDER BY id DESC LIMIT 1',
        ['ResetPalety']
    );
    return $lastResetId === false ? 0 : (int)$lastResetId;
}

function getTraceabilityShiftProduction(string $project, string $mode = '8h'): int {
    $tz = new DateTimeZone('Europe/Prague');
    $now = new DateTimeImmutable('now', $tz);
    $hour = (int)$now->format('G');

    if ($mode === '12h') {
        // 12h shifts: 06:00-17:59 and 18:00-05:59
        if ($hour >= 6 && $hour < 18) {
            $shiftStart = $now->setTime(6, 0, 0);
            $shiftEnd   = $now->setTime(17, 59, 59);
        } elseif ($hour >= 18) {
            $shiftStart = $now->setTime(18, 0, 0);
            $shiftEnd   = $now->modify('+1 day')->setTime(5, 59, 59);
        } else {
            // hours 0-5: shift started yesterday at 18:00
            $shiftStart = $now->modify('-1 day')->setTime(18, 0, 0);
            $shiftEnd   = $now->setTime(5, 59, 59);
        }
    } else {
        // 8h shifts: 06:00-13:59, 14:00-21:59, 22:00-05:59
        if ($hour >= 6 && $hour < 14) {
            $shiftStart = $now->setTime(6, 0, 0);
            $shiftEnd   = $now->setTime(13, 59, 59);
        } elseif ($hour >= 14 && $hour < 22) {
            $shiftStart = $now->setTime(14, 0, 0);
            $shiftEnd   = $now->setTime(21, 59, 59);
        } elseif ($hour >= 22) {
            $shiftStart = $now->setTime(22, 0, 0);
            $shiftEnd   = $now->modify('+1 day')->setTime(5, 59, 59);
        } else {
            // hours 0-5: shift started yesterday at 22:00
            $shiftStart = $now->modify('-1 day')->setTime(22, 0, 0);
            $shiftEnd   = $now->setTime(5, 59, 59);
        }
    }

    return (int)trace_db_val(
        'SELECT COUNT(*) FROM `' . $project . '` WHERE `timestamp` BETWEEN ? AND ? AND snDil <> ?',
        [$shiftStart->format('Y-m-d H:i:s'), $shiftEnd->format('Y-m-d H:i:s'), 'ResetPalety']
    );
}

function getTraceabilityActivePaletteCount(string $project): int
{
    $lastResetId = getTraceabilityLastResetId($project);
    if ($lastResetId > 0) {
        return (int)trace_db_val(
            'SELECT COUNT(*) FROM `' . $project . '` WHERE id > ? AND snDil <> ?',
            [$lastResetId, 'ResetPalety']
        );
    }

    return (int)trace_db_val(
        'SELECT COUNT(*) FROM `' . $project . '` WHERE snDil <> ?',
        ['ResetPalety']
    );
}

function deleteTraceabilityRow(string $project, int $id): void {
    $row = trace_db_row('SELECT id, snDil FROM `' . $project . '` WHERE id = ? LIMIT 1', [$id]);
    if (!$row) {
        return;
    }

    $deletedId = (int)($row['id'] ?? 0);
    $deletedSerial = trim((string)($row['snDil'] ?? ''));
    $lastResetId = getTraceabilityLastResetId($project);
    $isDeletedFromActivePalette = (
        $deletedSerial !== ''
        && strcasecmp($deletedSerial, 'ResetPalety') !== 0
        && ($lastResetId === 0 || $deletedId > $lastResetId)
    );

    $stmt = trace_db()->prepare('DELETE FROM `' . $project . '` WHERE id = ?');
    $stmt->execute([$id]);

    if ($isDeletedFromActivePalette) {
        setTraceabilityProjectCounter($project, max(0, getTraceabilityActivePaletteCount($project)));
    }
}

/**
 * Saves current palette SNs to a timestamped log file on the network share and
 * silently prints it to the system default printer.
 * Called just before a palette reset so the old SNs are captured first.
 */
function saveAndPrintPaletteLog(string $project, array $limits): void {
    $paletteSize = (int)($limits['limit'] ?? 0);
    if ($paletteSize <= 0) {
        $paletteSize = 300; // safety fallback
    }

    // Collect SNs on the current palette (since last ResetPalety entry)
    $lastResetId = getTraceabilityLastResetId($project);
    if ($lastResetId > 0) {
        $rows = trace_db_all(
            'SELECT snDil FROM `' . $project . '`
             WHERE id > ? AND snDil != ?
             ORDER BY id ASC',
            [$lastResetId, 'ResetPalety']
        );
    } else {
        $rows = trace_db_all(
            'SELECT snDil FROM `' . $project . '`
             WHERE snDil != ?
             ORDER BY id DESC
             LIMIT ' . $paletteSize,
            ['ResetPalety']
        );
        $rows = array_reverse($rows);
    }

    if (empty($rows)) {
        return; // Nothing to log/print
    }

    $lines = array_column($rows, 'snDil');

    $logDir = '\\\\lbfile01\\pacz\\Departments\\LOGISTIKA\\TraceabilityLog';
    $tz = new DateTimeZone('Europe/Prague');
    $now = new DateTimeImmutable('now', $tz);
    $filename = $now->format('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $project) . '.txt';
    $filepath = $logDir . '\\' . $filename;

    $header = $filename . "\r\n" . str_repeat('-', strlen($filename)) . "\r\n";
    $content = $header . implode("\r\n", $lines) . "\r\n";

    // Save file to network share
    @file_put_contents($filepath, $content);

    // Tisk na tiskárnu je deaktivován
    // $shouldPrint = !in_array($project, ['48w', 'gen3cina'], true);
    // if ($shouldPrint && file_exists($filepath)) {
    //     $escapedPath = str_replace("'", "''", $filepath);
    //     $psCmd = 'powershell.exe -NonInteractive -WindowStyle Hidden -Command '
    //         . '"Get-Content -Path \'' . $escapedPath . '\' | Out-Printer"';
    //     @shell_exec($psCmd);
    // }
}

function handleTraceabilityScanAction(string $project, string $rawValue, int $traceUserId): array {
    $value = trim($rawValue);
    $command = strtolower($value);
    if ($value === '') {
        return ['ok' => false, 'message' => t('traceability_scan_empty')];
    }

    if (!isTraceabilityProject($project)) {
        return ['ok' => false, 'message' => t('traceability_project_invalid')];
    }

    if ($traceUserId <= 0) {
        return ['ok' => false, 'message' => t('traceability_user_mapping_missing')];
    }

    // Validate that the user exists in the traceability database
    if (!traceabilityUserExists($traceUserId)) {
        return ['ok' => false, 'message' => t('traceability_user_mapping_missing')];
    }

    $limits = getTraceabilityProjectLimits($project);
    if ($command === 'logout') {
        return ['ok' => true, 'action' => 'logout'];
    }

    if (in_array($command, ['en', 'cz', 'ua'], true)) {
        $lang = match ($command) {
            'en' => 'en',
            'ua' => 'uk',
            default => 'cs',
        };
        LanguageLoader::reload($lang);
        return ['ok' => true, 'action' => 'set_lang', 'lang' => $lang, 'message' => t('traceability_kiosk_lang_switched')];
    }

    if (in_array($command, ['light', 'dark'], true)) {
        $_SESSION['trace_kiosk_theme'] = $command;
        return ['ok' => true, 'action' => 'set_theme', 'theme' => $command, 'message' => t('traceability_kiosk_theme_switched')];
    }

    if ($command === 'resetpalety') {
        saveAndPrintPaletteLog($project, $limits);
        setTraceabilityProjectCounter($project, 0);
        insertTraceabilityScan($project, 'ResetPalety', $traceUserId);
        logAction('TRACEABILITY_RESET_PALETTE', $project, null, [], ['project' => $project, 'trace_user_id' => $traceUserId], t('traceability_palette_reset_done'));
        return ['ok' => true, 'message' => t('traceability_palette_reset_done')];
    }

    if ($limits['limit'] > 0 && $limits['counter'] >= $limits['limit']) {
        return ['ok' => false, 'message' => t('traceability_palette_reset_required')];
    }

    if (traceabilityProjectHasSerial($project, $value)) {
        return ['ok' => false, 'message' => t('traceability_duplicate_serial')];
    }

    insertTraceabilityScan($project, $value, $traceUserId);
    setTraceabilityProjectCounter($project, $limits['counter'] + 1);

    return ['ok' => true, 'message' => t('traceability_scan_saved')];
}

function isTraceabilityKioskLoggedIn(): bool {
    return !empty($_SESSION['trace_kiosk_user_id']);
}

function getTraceabilityKioskUser(): array {
    return [
        'id' => (int)($_SESSION['trace_kiosk_user_id'] ?? 0),
        'username' => (string)($_SESSION['trace_kiosk_username'] ?? ''),
        'rights' => (int)($_SESSION['trace_kiosk_rights'] ?? 0),
    ];
}

function requireTraceabilityKioskLogin(): void {
    if (!isTraceabilityKioskLoggedIn()) {
        redirect(APP_URL . '/admin/traceability_kiosk_login.php');
    }
}

function clearTraceabilityKioskSession(): void {
    unset(
        $_SESSION['trace_kiosk_user_id'],
        $_SESSION['trace_kiosk_username'],
        $_SESSION['trace_kiosk_rights'],
        $_SESSION['traceability_project']
    );
}

function loginTraceabilityKioskByPassword(string $password): array|false {
    $password = trim($password);
    if ($password === '') {
        return false;
    }

    if (ensureUsersTraceabilityColumns()) {
        $user = db_row(
            'SELECT id, username, skutecneJmeno, role, active, traceability_user_id, traceability_rights, traceability_card_code
             FROM users
             WHERE active = 1 AND traceability_card_code = ?
             LIMIT 1',
            [$password]
        );

        if ($user) {
            $traceUserId = (int)($user['traceability_user_id'] ?? 0);
            if ($traceUserId <= 0) {
                $traceUserId = getTraceabilityUserIdByUsername((string)$user['username']);
                if ($traceUserId > 0) {
                    db()->prepare('UPDATE users SET traceability_user_id = ? WHERE id = ?')->execute([$traceUserId, (int)$user['id']]);
                }
            }

            if ($traceUserId > 0) {
                $rights = (int)($user['traceability_rights'] ?? 0);
                if ($rights <= 0) {
                    $rights = mapRoleToTraceabilityRights((string)($user['role'] ?? 'read'));
                }

                $displayName = trim((string)($user['skutecneJmeno'] ?? ''));
                if ($displayName === '') {
                    $displayName = (string)$user['username'];
                }

                $_SESSION['trace_kiosk_user_id'] = $traceUserId;
                $_SESSION['trace_kiosk_username'] = $displayName;
                $_SESSION['trace_kiosk_rights'] = $rights;

                return [
                    'id' => $traceUserId,
                    'username' => $displayName,
                    'rights' => $rights,
                ];
            }
        }
    }

    return false;
}

function requirePasswordChange(): void {
    if (!isLoggedIn()) return;
    
    $userId = $_SESSION['admin_user_id'] ?? null;
    if (!$userId) return;
    
    // Zjistit, zda byl password změněn
    $user = db_row('SELECT password_changed_at FROM users WHERE id=?', [$userId]);
    if ($user && !$user['password_changed_at']) {
        // Password nebyl změněn - přesměrovat na profile.php
        $currentPage = basename($_SERVER['PHP_SELF']);
        if (!in_array($currentPage, ['profile.php', 'logout.php', 'login.php'], true)) {
            redirect(APP_URL . '/admin/profile.php?first_login=1');
        }
    }
}

function ensureUsersSidebarSectionsColumn(): bool {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    try {
        $exists = (int)db_val(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['users', 'sidebar_sections_json']
        );

        if ($exists === 0) {
            db()->exec('ALTER TABLE users ADD COLUMN sidebar_sections_json TEXT NULL AFTER password_changed_at');
        }

        $checked = true;
    } catch (Throwable $e) {
        error_log('Sidebar sections column check failed: ' . $e->getMessage());
        $checked = false;
    }

    return $checked;
}

function normalizeSidebarSectionsState(mixed $raw): array {
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($raw)) {
        return [];
    }

    $normalized = [];
    foreach ($raw as $key => $value) {
        $keyStr = trim((string)$key);
        if ($keyStr === '' || !preg_match('/^\d{1,2}$/', $keyStr)) {
            continue;
        }
        $normalized[(string)((int)$keyStr)] = ((int)$value) === 1 ? 1 : 0;
    }

    ksort($normalized, SORT_NUMERIC);
    return $normalized;
}

function getCurrentUserSidebarSectionsState(): array {
    if (!isLoggedIn()) {
        return [];
    }

    $userId = (int)($_SESSION['admin_user_id'] ?? 0);
    if ($userId <= 0 || !ensureUsersSidebarSectionsColumn()) {
        return [];
    }

    $json = db_val('SELECT sidebar_sections_json FROM users WHERE id = ? LIMIT 1', [$userId]);
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    return normalizeSidebarSectionsState($json);
}

function saveCurrentUserSidebarSectionsState(array $state): bool {
    if (!isLoggedIn()) {
        return false;
    }

    $userId = (int)($_SESSION['admin_user_id'] ?? 0);
    if ($userId <= 0 || !ensureUsersSidebarSectionsColumn()) {
        return false;
    }

    $normalized = normalizeSidebarSectionsState($state);
    $json = $normalized ? json_encode($normalized, JSON_UNESCAPED_UNICODE) : null;

    try {
        db()->prepare('UPDATE users SET sidebar_sections_json = ? WHERE id = ?')->execute([$json, $userId]);
        return true;
    } catch (Throwable $e) {
        error_log('Saving sidebar sections failed: ' . $e->getMessage());
        return false;
    }
}

function ensureUsersKtPresetsColumn(): bool {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    try {
        $exists = (int)db_val(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['users', 'kt_presets_json']
        );

        if ($exists === 0) {
            db()->exec('ALTER TABLE users ADD COLUMN kt_presets_json TEXT NULL AFTER sidebar_sections_json');
        }

        $checked = true;
    } catch (Throwable $e) {
        error_log('KT presets column check failed: ' . $e->getMessage());
        $checked = false;
    }

    return $checked;
}

function normalizeKtPresetCids(mixed $raw): array {
    if (!is_array($raw)) {
        return [];
    }

    $out = [];
    foreach ($raw as $v) {
        $cid = (int)$v;
        if ($cid > 0) {
            $out[] = $cid;
        }
    }

    return array_values(array_unique($out));
}

function normalizeCurrentUserKtPresets(mixed $raw): array {
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($raw)) {
        return [];
    }

    $normalized = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }

        $key = trim((string)($row['key'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        $cids = normalizeKtPresetCids($row['cids'] ?? []);

        if ($key === '' || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $key)) {
            continue;
        }
        if ($name === '' || mb_strlen($name) > 60) {
            continue;
        }
        if (empty($cids)) {
            continue;
        }

        $normalized[] = [
            'key' => $key,
            'name' => $name,
            'cids' => $cids,
        ];
    }

    return $normalized;
}

function getCurrentUserKtPresets(): array {
    if (!isLoggedIn()) {
        return [];
    }

    $userId = (int)($_SESSION['admin_user_id'] ?? 0);
    if ($userId <= 0 || !ensureUsersKtPresetsColumn()) {
        return [];
    }

    $json = db_val('SELECT kt_presets_json FROM users WHERE id = ? LIMIT 1', [$userId]);
    if (!is_string($json) || trim($json) === '') {
        return [];
    }

    return normalizeCurrentUserKtPresets($json);
}

function saveCurrentUserKtPresets(array $presets): bool {
    if (!isLoggedIn()) {
        return false;
    }

    $userId = (int)($_SESSION['admin_user_id'] ?? 0);
    if ($userId <= 0 || !ensureUsersKtPresetsColumn()) {
        return false;
    }

    $normalized = normalizeCurrentUserKtPresets($presets);
    $json = $normalized ? json_encode($normalized, JSON_UNESCAPED_UNICODE) : null;

    try {
        db()->prepare('UPDATE users SET kt_presets_json = ? WHERE id = ?')->execute([$json, $userId]);
        return true;
    } catch (Throwable $e) {
        error_log('Saving KT presets failed: ' . $e->getMessage());
        return false;
    }
}

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function verifyCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
function validateDate(string $date): bool {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}
function validateUploadedCsv(array $file, int $maxBytes = 5_242_880): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmpName = (string)($file['tmp_name'] ?? '');
    $fileName = (string)($file['name'] ?? '');
    $fileSize = (int)($file['size'] ?? 0);
    if ($tmpName === '' || !is_uploaded_file($tmpName) || $fileSize <= 0 || $fileSize > $maxBytes) {
        return null;
    }

    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['csv', 'txt'], true)) {
        return null;
    }

    $mime = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $tmpName) ?: null;
            finfo_close($finfo);
        }
    }

    $allowedMimes = [
        'text/plain',
        'text/csv',
        'application/csv',
        'application/vnd.ms-excel',
        'application/octet-stream',
        'inode/x-empty',
    ];
    if ($mime !== null && !in_array($mime, $allowedMimes, true)) {
        return null;
    }

    return $tmpName;
}
function checkLoginRateLimit(string $username): bool {
    $key = 'login_fails_' . md5($username);
    return (int)($_SESSION[$key] ?? 0) < 10;
}
function recordLoginFail(string $username): void {
    $key = 'login_fails_' . md5($username);
    $_SESSION[$key] = (int)($_SESSION[$key] ?? 0) + 1;
}
function clearLoginFails(string $username): void {
    unset($_SESSION['login_fails_' . md5($username)]);
}

// ── Helper functions ──────────────────────────────────────────
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function get(string $k, mixed $d = ''): mixed { return $_GET[$k] ?? $d; }
function post(string $k, mixed $d = ''): mixed { return $_POST[$k] ?? $d; }
function redirect(string $url): never { header('Location: '.$url); exit; }

function db_row(string $sql, array $p = []): array|false {
    $s = db()->prepare($sql); $s->execute($p); return $s->fetch();
}
function db_all(string $sql, array $p = []): array {
    $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll();
}
function db_val(string $sql, array $p = []): mixed {
    $s = db()->prepare($sql); $s->execute($p); return $s->fetchColumn();
}
function trace_db_row(string $sql, array $p = []): array|false {
    $s = trace_db()->prepare($sql); $s->execute($p); return $s->fetch();
}
function trace_db_all(string $sql, array $p = []): array {
    $s = trace_db()->prepare($sql); $s->execute($p); return $s->fetchAll();
}
function trace_db_val(string $sql, array $p = []): mixed {
    $s = trace_db()->prepare($sql); $s->execute($p); return $s->fetchColumn();
}

function getPracovisteList(string $typ = ''): array {
    $sql = 'SELECT * FROM pracovisite WHERE aktivni=1';
    $p = [];
    if ($typ) { $sql .= ' AND (typ=? OR typ="obe")'; $p[] = $typ; }
    return db_all($sql.' ORDER BY cislo_id', $p);
}
function getPracovniciList(): array { 
    return db_all('SELECT * FROM pracovnici WHERE aktivni=1 ORDER BY jmeno'); 
}

function ensureNokKodyMatrixSchema(): bool {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    try {
        $defaultColExists = (int)db_val(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['nok_kody', 'zobrazit_default']
        );
        if ($defaultColExists === 0) {
            db()->exec('ALTER TABLE nok_kody ADD COLUMN zobrazit_default TINYINT(1) NOT NULL DEFAULT 1 AFTER aktivni');
        }

        $legacyExists = (int)db_val(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['nok_kody_afo']
        );
        $newExists = (int)db_val(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['nok_matrix_afo']
        );

        if ($legacyExists === 1 && $newExists === 0) {
            db()->exec('RENAME TABLE nok_kody_afo TO nok_matrix_afo');
        }

        db()->exec(
            'CREATE TABLE IF NOT EXISTS nok_matrix_afo (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                afo VARCHAR(50) NOT NULL,
                nok_kod_id SMALLINT UNSIGNED NOT NULL,
                zobrazit TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_nok_matrix_afo (afo, nok_kod_id),
                KEY idx_nok_matrix_afo_nok (nok_kod_id),
                CONSTRAINT fk_nok_matrix_afo_nok FOREIGN KEY (nok_kod_id) REFERENCES nok_kody(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $checked = true;
    } catch (Throwable $e) {
        error_log('NOK matrix schema check failed: ' . $e->getMessage());
        $checked = false;
    }

    return $checked;
}

function getNokAfoVisibilityMap(): array {
    if (!ensureNokKodyMatrixSchema()) {
        return [];
    }

    $rows = db_all('SELECT afo, nok_kod_id, zobrazit FROM nok_matrix_afo');
    $map = [];
    foreach ($rows as $row) {
        $afo = trim((string)($row['afo'] ?? ''));
        $nokId = (int)($row['nok_kod_id'] ?? 0);
        if ($afo === '' || $nokId <= 0) {
            continue;
        }

        $afoKeys = [$afo];
        if (preg_match('/^\d+$/', $afo)) {
            $afoNum = (string)((int)$afo);
            if ($afoNum !== $afo) {
                $afoKeys[] = $afoNum;
            }
        }

        $visible = (int)($row['zobrazit'] ?? 0) === 1;
        foreach ($afoKeys as $afoKey) {
            $map[$afoKey][$nokId] = $visible;
        }
    }
    return $map;
}

function getNokKodyGrouped(): array {
    ensureNokKodyMatrixSchema();
    $lang = $_SESSION['lang'] ?? 'cs';
    
    $rows = db_all(
        "SELECT n.*,
                n.zobrazit_default,
                COALESCE(
                    (SELECT `value` FROM translations WHERE lang_code=? AND `key`=CONCAT('nok_',LPAD(n.kod,3,'0'),'_popis')),
                    n.popis
                ) as popis_trans
         FROM nok_kody n 
         WHERE aktivni=1 
         ORDER BY kod",
        [$lang]
    );
    
    $result = [];
    foreach ($rows as $r) {
        $r['popis'] = $r['popis_trans'];
        unset($r['popis_trans']);
        $result[] = $r;
    }
    return $result;
}
function getDefaultProstojTypySeed(): array {
    return [
        ['kod' => 'P1', 'popis' => 'Změna výroby – verze, formy'],
        ['kod' => 'P2', 'popis' => 'Čištění / 5S / údržba pracoviště'],
        ['kod' => 'P3', 'popis' => 'Plánovaná údržba'],
        ['kod' => 'P4', 'popis' => 'Porada / školení'],
        ['kod' => 'P5', 'popis' => 'Zkoušky / vzorkování'],
        ['kod' => 'P6', 'popis' => 'Inventura / logistická příprava'],
        ['kod' => 'P7', 'popis' => 'Přestavba linky'],
        ['kod' => 'P8', 'popis' => 'Seřízení'],
        ['kod' => 'P9', 'popis' => 'Plánovaný servis zařízení'],
        ['kod' => 'P10', 'popis' => 'Jiný plánovaný prostoj'],
        ['kod' => 'O1', 'popis' => 'Ostatní prostoj'],
        ['kod' => 'L1', 'popis' => 'Chybějící materiál sklad'],
        ['kod' => 'L2', 'popis' => 'Chybějící obaly / logistika'],
        ['kod' => 'V1', 'popis' => 'Org. problémy – chybí pracovník'],
        ['kod' => 'V2', 'popis' => 'Org. problémy – přesun pracovníků'],
        ['kod' => 'V3', 'popis' => 'Čekání na výrobu / plánování'],
        ['kod' => 'Q1', 'popis' => 'Nekvalitní vstupní materiál'],
        ['kod' => 'Q2', 'popis' => 'Neshoda v procesu'],
        ['kod' => 'Q3', 'popis' => 'Kontrola kvality / třídění'],
        ['kod' => 'Q4', 'popis' => 'Blokace kvalitou'],
    ];
}

function ensureProstojTypySeed(): void {
    static $seeded = false;
    if ($seeded) {
        return;
    }

    $insert = db()->prepare('INSERT IGNORE INTO prostoj_typy (kod, popis, detail) VALUES (?, ?, ?)');
    foreach (getDefaultProstojTypySeed() as $row) {
        $insert->execute([$row['kod'], $row['popis'], null]);
    }

    $seeded = true;
}

function ensureSmenyTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS smeny (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        kod             VARCHAR(10)  NOT NULL UNIQUE,
        nazev           VARCHAR(60)  NOT NULL,
        cas_od          TIME         NOT NULL DEFAULT '06:00:00',
        cas_do          TIME         NOT NULL DEFAULT '14:00:00',
        pracovni_doba_min SMALLINT UNSIGNED NOT NULL DEFAULT 480,
        pauza_min       SMALLINT UNSIGNED NOT NULL DEFAULT 30,
        poradi          TINYINT UNSIGNED NOT NULL DEFAULT 99,
        aktivni         TINYINT(1)   NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM smeny")->fetchColumn();
    if ($cnt === 0) {
        $seed = [
            ['R8',  'Ranní 8h',    '06:00', '14:00', 480, 30, 1],
            ['O8',  'Odpolední 8h','14:00', '22:00', 480, 30, 2],
            ['N8',  'Noční 8h',    '22:00', '06:00', 480, 30, 3],
            ['R12', 'Ranní 12h',   '06:00', '18:00', 720, 45, 4],
            ['N12', 'Noční 12h',   '18:00', '06:00', 720, 45, 5],
        ];
        $stmt = $pdo->prepare("INSERT IGNORE INTO smeny (kod, nazev, cas_od, cas_do, pracovni_doba_min, pauza_min, poradi) VALUES (?,?,?,?,?,?,?)");
        foreach ($seed as $r) { $stmt->execute($r); }
    }
}

function getSmenyList(bool $onlyActive = true): array {
    ensureSmenyTable();
    $where = $onlyActive ? 'WHERE aktivni=1' : '';
    return db_all("SELECT * FROM smeny $where ORDER BY poradi, kod");
}

function getDefaultPrestavkySeed(): array {
    $zona1 = 'Série';
    $zona2 = 'Filtry';
    $nazvy = [
        1 => 'Pauza 1',
        2 => 'Pauza 2',
        3 => 'Pauza 3',
        4 => 'Pauza 4',
    ];

    return [
        // Série - 8h
        [$zona1, 8, 'R8',  $nazvy[1], '08:30', '08:40', 10, 1],
        [$zona1, 8, 'R8',  $nazvy[2], '10:45', '11:15', 30, 2],
        [$zona1, 8, 'R8',  $nazvy[3], '13:55', '14:00', 5, 3],
        [$zona1, 8, 'O8',  $nazvy[1], '17:30', '18:00', 30, 1],
        [$zona1, 8, 'O8',  $nazvy[2], '19:30', '19:40', 10, 2],
        [$zona1, 8, 'O8',  $nazvy[3], '21:55', '22:00', 5, 3],
        [$zona1, 8, 'N8',  $nazvy[1], '01:30', '02:00', 30, 1],
        [$zona1, 8, 'N8',  $nazvy[2], '03:30', '03:40', 10, 2],
        [$zona1, 8, 'N8',  $nazvy[3], '05:55', '06:00', 5, 3],

        // Série - 12h
        [$zona1, 12, 'R12', $nazvy[1], '08:30', '08:40', 10, 1],
        [$zona1, 12, 'R12', $nazvy[2], '10:45', '11:15', 30, 2],
        [$zona1, 12, 'R12', $nazvy[3], '15:00', '15:15', 15, 3],
        [$zona1, 12, 'R12', $nazvy[4], '17:55', '18:00', 5, 4],
        [$zona1, 12, 'N12', $nazvy[1], '22:00', '22:30', 30, 1],
        [$zona1, 12, 'N12', $nazvy[2], '01:00', '01:15', 15, 2],
        [$zona1, 12, 'N12', $nazvy[3], '03:30', '03:40', 10, 3],
        [$zona1, 12, 'N12', $nazvy[4], '05:55', '06:00', 5, 4],

        // Filtry - 8h
        [$zona2, 8, 'R8',  $nazvy[1], '08:40', '08:50', 10, 1],
        [$zona2, 8, 'R8',  $nazvy[2], '11:15', '11:45', 30, 2],
        [$zona2, 8, 'R8',  $nazvy[3], '13:55', '14:00', 5, 3],
        [$zona2, 8, 'O8',  $nazvy[1], '17:00', '17:30', 30, 1],
        [$zona2, 8, 'O8',  $nazvy[2], '19:40', '19:50', 10, 2],
        [$zona2, 8, 'O8',  $nazvy[3], '21:55', '22:00', 5, 3],
        [$zona2, 8, 'N8',  $nazvy[1], '01:00', '01:30', 30, 1],
        [$zona2, 8, 'N8',  $nazvy[2], '03:40', '03:50', 10, 2],
        [$zona2, 8, 'N8',  $nazvy[3], '05:55', '06:00', 5, 3],

        // Filtry - 12h
        [$zona2, 12, 'R12', $nazvy[1], '08:40', '08:50', 10, 1],
        [$zona2, 12, 'R12', $nazvy[2], '11:15', '11:45', 30, 2],
        [$zona2, 12, 'R12', $nazvy[3], '15:00', '15:15', 15, 3],
        [$zona2, 12, 'R12', $nazvy[4], '17:55', '18:00', 5, 4],
        [$zona2, 12, 'N12', $nazvy[1], '22:00', '22:30', 30, 1],
        [$zona2, 12, 'N12', $nazvy[2], '01:00', '01:15', 15, 2],
        [$zona2, 12, 'N12', $nazvy[3], '03:30', '03:40', 10, 3],
        [$zona2, 12, 'N12', $nazvy[4], '05:55', '06:00', 5, 4],
    ];
}

function ensurePrestavkyTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS prestavky (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        zona            VARCHAR(160) NOT NULL,
        rezim_hod       TINYINT UNSIGNED NOT NULL DEFAULT 8,
        smena_kod       VARCHAR(10) NOT NULL,
        nazev           VARCHAR(80) NOT NULL,
        cas_od          TIME NOT NULL,
        cas_do          TIME NOT NULL,
        trvani_min      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        poradi          TINYINT UNSIGNED NOT NULL DEFAULT 1,
        aktivni         TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uq_prestavky_slot (zona, rezim_hod, smena_kod, poradi)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM prestavky")->fetchColumn();
    if ($cnt === 0) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO prestavky
            (zona, rezim_hod, smena_kod, nazev, cas_od, cas_do, trvani_min, poradi, aktivni)
            VALUES (?,?,?,?,?,?,?,?,1)");
        foreach (getDefaultPrestavkySeed() as $r) {
            $stmt->execute($r);
        }
    }
}

   
    // Ensure default slots are also aligned for already-populated tables.
    /*
    $ins = $pdo->prepare("INSERT INTO prestavky
        (zona, rezim_hod, smena_kod, nazev, cas_od, cas_do, trvani_min, poradi, aktivni)
        VALUES (?,?,?,?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE
            nazev=VALUES(nazev),
            cas_od=VALUES(cas_od),
            cas_do=VALUES(cas_do),
            trvani_min=VALUES(trvani_min),
            aktivni=1");
    foreach (getDefaultPrestavkySeed() as $r) {
        $ins->execute($r);
    }
}*/

function getPrestavkyList(bool $onlyActive = true): array {
    ensurePrestavkyTable();
    $where = $onlyActive ? 'WHERE aktivni=1' : '';
    return db_all("SELECT * FROM prestavky $where ORDER BY zona, rezim_hod, smena_kod, poradi");
}

function getProstojTypyList(): array {
    ensureProstojTypySeed();
    return db_all("SELECT * FROM prostoj_typy
        ORDER BY
            LEFT(UPPER(kod), 1),
            CASE WHEN SUBSTRING(UPPER(kod), 2) REGEXP '^[0-9]+$' THEN 0 ELSE 1 END,
            CAST(SUBSTRING(UPPER(kod), 2) AS UNSIGNED),
            UPPER(kod)");
}

function normalizeDowntimeTypeCode(?string $code): string {
    return strtoupper(trim((string)$code));
}

function isZapisyDowntimeSummaryCode(string $code): bool {
    return in_array($code, ['PLAN_SUM', 'VYR_SUM', 'KVAL_SUM', 'LOG_SUM', 'OST_SUM', 'TECH_SUM'], true);
}

function getZapisyDowntimeCategoryForCode(string $code): string {
    $code = normalizeDowntimeTypeCode($code);
    if ($code === '') {
        return 'tech';
    }

    return match (substr($code, 0, 1)) {
        'P' => 'plan',
        'V' => 'vyr',
        'Q' => 'kval',
        'L' => 'log',
        'O' => 'ost',
        default => 'tech',
    };
}

function buildZapisyDowntimeCodeMinutesFromRows(array $rows): array {
    $minutes = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $code = normalizeDowntimeTypeCode($row['typ_kod'] ?? '');
        $rowMinutes = max(0, (int)($row['minuty'] ?? 0));
        if ($code === '' || $rowMinutes <= 0 || isZapisyDowntimeSummaryCode($code)) {
            continue;
        }

        if (!isset($minutes[$code])) {
            $minutes[$code] = 0;
        }
        $minutes[$code] += $rowMinutes;
    }

    return $minutes;
}
function getCW(string $date): int { 
    return (int)(new DateTime($date))->format('W'); 
}

function normalizeZapisyWorkerValue(?string $value, int $maxLen = 150): ?string {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
    if (function_exists('mb_substr')) {
        $value = mb_substr($value, 0, $maxLen);
    } else {
        $value = substr($value, 0, $maxLen);
    }
    return trim($value) !== '' ? trim($value) : null;
}

function normalizeZapisyWorkerLookupKey(?string $value): ?string {
    $value = normalizeZapisyWorkerValue($value, 150);
    if ($value === null) {
        return null;
    }
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function normalizeZapisyWorkerTokenKey(?string $value): ?string {
    $value = normalizeZapisyWorkerLookupKey($value);
    if ($value === null || $value === '') {
        return null;
    }
    $tokens = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$tokens) {
        return null;
    }
    sort($tokens, SORT_STRING);
    return implode(' ', $tokens);
}

function normalizeZapisyWorkerAliasKey(?string $value): ?string {
    $value = normalizeZapisyWorkerValue($value, 150);
    if ($value === null || $value === '') {
        return null;
    }

    $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
    if (!is_string($ascii) || $ascii === '') {
        $ascii = $lower;
    }

    $ascii = strtolower($ascii);
    $ascii = preg_replace('/[^a-z0-9\s]/', ' ', $ascii) ?: $ascii;
    $ascii = preg_replace('/\s+/', ' ', trim($ascii)) ?: trim($ascii);
    if ($ascii === '') {
        return null;
    }

    $tokens = preg_split('/\s+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$tokens) {
        return null;
    }
    sort($tokens, SORT_STRING);
    return implode(' ', $tokens);
}

function resolveZapisyWorkerRows(PDO $pdo, array $workerRows): array {
    if (!$workerRows) {
        return [];
    }

    $workerIds = [];
    foreach ($workerRows as $workerRow) {
        if (!is_array($workerRow)) {
            continue;
        }

        if (!empty($workerRow['pracovnik_id'])) {
            $workerIds[(int)$workerRow['pracovnik_id']] = true;
        }
    }

    if (!$workerIds && !$workerRows) {
        return $workerRows;
    }

    $where = ['COALESCE(TRIM(jmeno), \'\') <> \'\''];
    $params = [];
    if ($workerIds) {
        $idPlaceholders = implode(',', array_fill(0, count($workerIds), '?'));
        $where[] = 'id IN (' . $idPlaceholders . ')';
        $params = array_merge($params, array_map('intval', array_keys($workerIds)));
    }

    $matchedWorkers = [];
    if ($where) {
        $stmt = $pdo->prepare('SELECT id, interni_id, jmeno, aktivni FROM pracovnici WHERE ' . implode(' OR ', $where) . ' ORDER BY aktivni DESC, id ASC');
        $stmt->execute($params);
        $matchedWorkers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $workersById = [];
    $workersByInterniId = [];
    $workersByName = [];
    $workersByToken = [];
    $workersByAlias = [];
    foreach ($matchedWorkers as $matchedWorker) {
        $matchedId = (int)($matchedWorker['id'] ?? 0);
        if ($matchedId > 0 && !isset($workersById[$matchedId])) {
            $workersById[$matchedId] = $matchedWorker;
        }

        $matchedInterniId = trim((string)($matchedWorker['interni_id'] ?? ''));
        if ($matchedInterniId !== '' && ctype_digit($matchedInterniId)) {
            $matchedInterniIdInt = (int)$matchedInterniId;
            if (!isset($workersByInterniId[$matchedInterniIdInt])) {
                $workersByInterniId[$matchedInterniIdInt] = $matchedWorker;
            }
        }

        $matchedNameKey = normalizeZapisyWorkerLookupKey((string)($matchedWorker['jmeno'] ?? ''));
        if ($matchedNameKey !== null && !isset($workersByName[$matchedNameKey])) {
            $workersByName[$matchedNameKey] = $matchedWorker;
        }

        $matchedTokenKey = normalizeZapisyWorkerTokenKey((string)($matchedWorker['jmeno'] ?? ''));
        if ($matchedTokenKey !== null && !isset($workersByToken[$matchedTokenKey])) {
            $workersByToken[$matchedTokenKey] = $matchedWorker;
        }

        $matchedAliasKey = normalizeZapisyWorkerAliasKey((string)($matchedWorker['jmeno'] ?? ''));
        if ($matchedAliasKey !== null && !isset($workersByAlias[$matchedAliasKey])) {
            $workersByAlias[$matchedAliasKey] = $matchedWorker;
        }
    }

    foreach ($workerRows as &$workerRow) {
        if (!is_array($workerRow)) {
            continue;
        }

        $matchedWorker = null;
        $pracovnikId = !empty($workerRow['pracovnik_id']) ? (int)$workerRow['pracovnik_id'] : null;
        if ($pracovnikId !== null && isset($workersByInterniId[$pracovnikId])) {
            $matchedWorker = $workersByInterniId[$pracovnikId];
        } elseif ($pracovnikId !== null && isset($workersById[$pracovnikId])) {
            $matchedWorker = $workersById[$pracovnikId];
        } else {
            $nameKey = normalizeZapisyWorkerLookupKey((string)($workerRow['jmeno'] ?? ''));
            if ($nameKey !== null && isset($workersByName[$nameKey])) {
                $matchedWorker = $workersByName[$nameKey];
            } else {
                $tokenKey = normalizeZapisyWorkerTokenKey((string)($workerRow['jmeno'] ?? ''));
                if ($tokenKey !== null && isset($workersByToken[$tokenKey])) {
                    $matchedWorker = $workersByToken[$tokenKey];
                } else {
                    $aliasKey = normalizeZapisyWorkerAliasKey((string)($workerRow['jmeno'] ?? ''));
                    if ($aliasKey !== null && isset($workersByAlias[$aliasKey])) {
                        $matchedWorker = $workersByAlias[$aliasKey];
                    }
                }
            }
        }

        if ($matchedWorker !== null) {
            $interniId = trim((string)($matchedWorker['interni_id'] ?? ''));
            $workerRow['pracovnik_id'] = (ctype_digit($interniId) ? (int)$interniId : null);
            $workerRow['jmeno'] = (string)(int)($matchedWorker['id'] ?? 0);
        }
    }
    unset($workerRow);

    return $workerRows;
}

function buildZapisyWorkerRowsFromSummary(?string $workerNamesSummary, ?string $workerPositionsSummary = null): array {
    $names = preg_split('/\s*,\s*/u', (string)$workerNamesSummary, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $positions = preg_split('/\s*,\s*/u', (string)$workerPositionsSummary, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $rows = [];

    foreach ($names as $index => $rawName) {
        $name = normalizeZapisyWorkerValue($rawName, 150);
        if ($name === null) {
            continue;
        }

        $position = null;
        if (count($positions) === 1) {
            $position = normalizeZapisyWorkerValue($positions[0], 100);
        } elseif (count($positions) === count($names)) {
            $position = normalizeZapisyWorkerValue($positions[$index] ?? null, 100);
        }

        $rows[] = [
            'pracovnik_id' => null,
            'jmeno' => $name,
            'pracovni_pozice' => $position,
        ];
    }

    return $rows;
}

function syncZapisyPracovnici(PDO $pdo, int $zapisId, array $workerRows): void {
    $pdo->prepare('DELETE FROM zapis_pracovnici WHERE zapis_id=?')->execute([$zapisId]);

    if (!$workerRows) {
        return;
    }

    $workerRows = resolveZapisyWorkerRows($pdo, $workerRows);

    $insert = $pdo->prepare('INSERT INTO zapis_pracovnici (zapis_id,pracovnik_id,jmeno,pracovni_pozice,poradi) VALUES (?,?,?,?,?)');
    $seen = [];
    $order = 1;

    foreach ($workerRows as $workerRow) {
        if (!is_array($workerRow)) {
            continue;
        }

        $pracovnikId = !empty($workerRow['pracovnik_id']) ? (int)$workerRow['pracovnik_id'] : null;
        $jmeno = normalizeZapisyWorkerValue((string)($workerRow['jmeno'] ?? ''), 150);
        $pracovniPozice = normalizeZapisyWorkerValue((string)($workerRow['pracovni_pozice'] ?? ''), 100);

        // Do not store orphan rows without worker identity.
        if ($jmeno === null && $pracovnikId === null) {
            continue;
        }

        if ($jmeno === null) {
            continue;
        }

        $nameKey = function_exists('mb_strtolower') ? mb_strtolower($jmeno, 'UTF-8') : strtolower($jmeno);
        $positionKey = function_exists('mb_strtolower') ? mb_strtolower((string)($pracovniPozice ?? ''), 'UTF-8') : strtolower((string)($pracovniPozice ?? ''));
        $dedupeKey = ($pracovnikId ?? 0) . '|' . $nameKey . '|' . $positionKey;
        if (isset($seen[$dedupeKey])) {
            continue;
        }
        $seen[$dedupeKey] = true;

        $insert->execute([$zapisId, $pracovnikId, $jmeno, $pracovniPozice, $order]);
        $order++;
    }
}

function buildZapisyDowntimeCodeMinutes(array $source, array $rows = []): array {
    $getInt = static function (string $key) use ($source): int {
        if (array_key_exists($key, $source)) {
            return max(0, (int)$source[$key]);
        }
        $alt = $key . '_min';
        if (array_key_exists($alt, $source)) {
            return max(0, (int)$source[$alt]);
        }
        return 0;
    };

    $minutes = buildZapisyDowntimeCodeMinutesFromRows($rows);
    for ($i = 1; $i <= 10; $i++) {
        $code = 'P' . $i;
        $minutes[$code] = max((int)($minutes[$code] ?? 0), $getInt('p' . $i));
    }
    $minutes['O1'] = max((int)($minutes['O1'] ?? 0), $getInt('o1'));
    $minutes['L1'] = max((int)($minutes['L1'] ?? 0), $getInt('l1'));
    $minutes['L2'] = max((int)($minutes['L2'] ?? 0), $getInt('l2'));
    $minutes['V1'] = max((int)($minutes['V1'] ?? 0), $getInt('v1'));
    $minutes['V2'] = max((int)($minutes['V2'] ?? 0), $getInt('v2'));
    $minutes['V3'] = max((int)($minutes['V3'] ?? 0), $getInt('v3'));
    $minutes['Q1'] = max((int)($minutes['Q1'] ?? 0), $getInt('q1'));
    $minutes['Q2'] = max((int)($minutes['Q2'] ?? 0), $getInt('q2'));
    $minutes['Q3'] = max((int)($minutes['Q3'] ?? 0), $getInt('q3'));
    $minutes['Q4'] = max((int)($minutes['Q4'] ?? 0), $getInt('q4'));
    $minutes['TECH_SUM'] = $getInt('prostoj_technicky');

    return $minutes;
}

function saveZapisyProstoje(PDO $pdo, int $zapisId, array $codeMinutes, array $techRows = []): void {
    $pdo->prepare('DELETE FROM zapisy_prostoje WHERE zapis_id=?')->execute([$zapisId]);

    $insert = $pdo->prepare(
        'INSERT INTO zapisy_prostoje (zapis_id,kategorie,typ_kod,minuty,cas_od,cas_do,pocet,poznamka) VALUES (?,?,?,?,?,?,?,?)'
    );

    $categoryInserted = ['plan' => 0, 'vyr' => 0, 'kval' => 0, 'log' => 0, 'ost' => 0, 'tech' => 0];
    $rowBackedCodeMinutes = buildZapisyDowntimeCodeMinutesFromRows($techRows);

    foreach ($codeMinutes as $code => $minutesRaw) {
        $code = normalizeDowntimeTypeCode($code);
        if ($code === '' || isZapisyDowntimeSummaryCode($code)) {
            continue;
        }
        if (isset($rowBackedCodeMinutes[$code]) && $rowBackedCodeMinutes[$code] > 0) {
            continue;
        }

        $category = getZapisyDowntimeCategoryForCode($code);
        $minutes = max(0, (int)$minutesRaw);
        if ($minutes <= 0) {
            continue;
        }
        $insert->execute([$zapisId, $category, $code, $minutes, null, null, 1, null]);
        $categoryInserted[$category] += $minutes;
    }

    $hadTechDetails = false;
    foreach ($techRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $typKod = normalizeDowntimeTypeCode($row['typ_kod'] ?? '');
        $minutes = max(0, (int)($row['minuty'] ?? 0));
        if ($typKod === '' || $minutes <= 0 || isZapisyDowntimeSummaryCode($typKod)) {
            continue;
        }
        $casOd = trim((string)($row['cas_od'] ?? ''));
        $casDo = trim((string)($row['cas_do'] ?? ''));
        $poznamka = trim((string)($row['poznamka'] ?? ''));
        $insert->execute([
            $zapisId,
            getZapisyDowntimeCategoryForCode($typKod),
            $typKod,
            $minutes,
            $casOd !== '' ? $casOd : null,
            $casDo !== '' ? $casDo : null,
            1,
            $poznamka !== '' ? $poznamka : null,
        ]);
        $hadTechDetails = true;
        $categoryInserted['tech'] += $minutes;
    }

    $getSummary = static function (array $source, array $keys): int {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            return max(0, (int)$source[$key]);
        }
        return 0;
    };

    $summaryByCategory = [
        'plan' => $getSummary($codeMinutes, ['PLAN_SUM', 'prostoj_planovany']),
        'vyr'  => $getSummary($codeMinutes, ['VYR_SUM', 'prostoj_vyrobni']),
        'kval' => $getSummary($codeMinutes, ['KVAL_SUM', 'prostoj_kvalitativni']),
        'log'  => $getSummary($codeMinutes, ['LOG_SUM', 'prostoj_logisticky']),
        'ost'  => $getSummary($codeMinutes, ['OST_SUM', 'prostoj_ostatni']),
        'tech' => $getSummary($codeMinutes, ['TECH_SUM', 'prostoj_technicky']),
    ];

    $summaryCodes = [
        'plan' => 'PLAN_SUM',
        'vyr' => 'VYR_SUM',
        'kval' => 'KVAL_SUM',
        'log' => 'LOG_SUM',
        'ost' => 'OST_SUM',
        'tech' => 'TECH_SUM',
    ];

    foreach ($summaryByCategory as $category => $summaryTotal) {
        $residual = $summaryTotal - (int)($categoryInserted[$category] ?? 0);
        if ($residual <= 0) {
            continue;
        }
        $insert->execute([$zapisId, $category, $summaryCodes[$category], $residual, null, null, 1, null]);
        $categoryInserted[$category] += $residual;
    }

    if (!$hadTechDetails) {
        $techSummary = max(0, (int)($summaryByCategory['tech'] ?? 0));
        if ($techSummary > 0) {
            $alreadyInserted = (int)($categoryInserted['tech'] ?? 0);
            $residualTech = $techSummary - $alreadyInserted;
            if ($residualTech > 0) {
                $insert->execute([$zapisId, 'tech', 'TECH_SUM', $residualTech, null, null, 1, null]);
            }
        }
    }
}

// JSON response helper
function jsonOut(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = '{"ok":false,"error":"JSON encoding failed"}';
    }
    echo $json;
    exit;
}

function getPythonImportCommand(): ?string {
    static $command = false;

    if ($command !== false) {
        return $command;
    }

    $candidates = [];
    $venvPython = APP_ROOT . '/.venv/Scripts/python.exe';
    if (is_file($venvPython)) {
        $candidates[] = [
            'command' => escapeshellarg($venvPython),
            'probe' => escapeshellarg($venvPython) . ' --version',
        ];
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        $candidates[] = ['command' => 'py -3', 'probe' => 'py -3 --version'];
        $candidates[] = ['command' => 'python', 'probe' => 'python --version'];
    }

    $candidates[] = ['command' => 'python3', 'probe' => 'python3 --version'];

    foreach ($candidates as $candidate) {
        $outputLines = [];
        $exitCode = 1;
        exec($candidate['probe'] . ' 2>&1', $outputLines, $exitCode);
        if ($exitCode === 0 && trim(implode("\n", $outputLines)) !== '') {
            $command = $candidate['command'];
            return $command;
        }
    }

    $command = null;
    return null;
}

// ── Audit Logging ────────────────────────────────────────────
function logAction(
    string $action,
    string $entityType = '',
    mixed $entityId = null,
    mixed $oldData = null,
    mixed $newData = null,
    string $description = ''
): void {
    try {
        $userId = null;
        if (!empty($_SESSION['admin_user'])) {
            $user = db_row('SELECT id FROM users WHERE username=?', [$_SESSION['admin_user']]);
            $userId = $user['id'] ?? null;
        }
        $username = $_SESSION['admin_user'] ?? (PHP_SAPI === 'cli' ? 'system' : 'anonymous');
        $ipAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $rawUserAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $userAgent = function_exists('mb_substr')
            ? mb_substr($rawUserAgent, 0, 500)
            : substr($rawUserAgent, 0, 500);
        
        $oldJson = $oldData ? json_encode($oldData, JSON_UNESCAPED_UNICODE) : null;
        $newJson = $newData ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null;
        
        $stmt = db()->prepare("
            INSERT INTO audit_log 
            (user_id, username, action, entity_type, entity_id, 
             old_data, new_data, ip_address, user_agent, description)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $userId,
            $username,
            $action,
            $entityType,
            $entityId,
            $oldJson,
            $newJson,
            $ipAddr,
            $userAgent,
            $description
        ]);
    } catch (Throwable $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
}

// Diffing helper – najít změny mezi starým a novým
function diffData(array $old, array $new): array {
    $diff = [];
    foreach ($new as $key => $value) {
        if (!isset($old[$key]) || $old[$key] !== $value) {
            $diff[$key] = ['old' => $old[$key] ?? null, 'new' => $value];
        }
    }
    return $diff;
}

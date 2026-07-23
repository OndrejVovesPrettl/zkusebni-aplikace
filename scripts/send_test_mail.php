<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';
require_once APP_ROOT . '/includes/mailer.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    requireSuperAdmin();
}

$to = '';
$subject = 'DVD mail test';

if ($isCli) {
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--to=')) {
            $to = trim((string)substr($arg, 5));
        } elseif (str_starts_with($arg, '--subject=')) {
            $subject = trim((string)substr($arg, 10));
        }
    }
} else {
    $to = trim((string)($_GET['to'] ?? ''));
    $subject = trim((string)($_GET['subject'] ?? $subject));
}

if ($to === '') {
    $to = trim((string)MAIL_TEST_TO);
}

$timestamp = date('Y-m-d H:i:s');
$host = (string)gethostname();

$result = [
    'ok' => false,
    'to' => $to,
    'subject' => $subject,
    'time' => $timestamp,
    'host' => $host,
    'error' => null,
];

if ($to === '') {
    $result['error'] = 'Missing recipient. Set DVD_MAIL_TEST_TO or use --to=';
} else {
    $html = '<h2>DVD SMTP test</h2>'
        . '<p>This is a PHPMailer SMTP test.</p>'
        . '<ul>'
        . '<li>Time: ' . htmlspecialchars($timestamp, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li>Host: ' . htmlspecialchars($host, ENT_QUOTES, 'UTF-8') . '</li>'
        . '<li>App version: ' . htmlspecialchars((string)APP_VERSION, ENT_QUOTES, 'UTF-8') . '</li>'
        . '</ul>';
    $text = "DVD SMTP test\nTime: {$timestamp}\nHost: {$host}\nApp version: " . APP_VERSION;

    $send = appSendMail([$to], $subject, $html, $text);
    $result['ok'] = (bool)$send['ok'];
    $result['error'] = $send['error'];
}

if (!$isCli) {
    header('Content-Type: application/json; charset=UTF-8');
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

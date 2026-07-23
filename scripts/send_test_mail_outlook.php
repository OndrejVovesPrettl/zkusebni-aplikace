<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/config.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    requireSuperAdmin();
}

$to = '';
$subject = 'DVD Outlook test';
$body = '';

if ($isCli) {
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--to=')) {
            $to = trim((string)substr($arg, 5));
        } elseif (str_starts_with($arg, '--subject=')) {
            $subject = trim((string)substr($arg, 10));
        } elseif (str_starts_with($arg, '--body=')) {
            $body = (string)substr($arg, 7);
        }
    }
} else {
    $to = trim((string)($_GET['to'] ?? ''));
    $subject = trim((string)($_GET['subject'] ?? $subject));
    $body = (string)($_GET['body'] ?? '');
}

if ($to === '') {
    $to = trim((string)MAIL_TEST_TO);
}

$result = [
    'ok' => false,
    'to' => $to,
    'subject' => $subject,
    'error' => null,
    'output' => '',
];

if ($to === '') {
    $result['error'] = 'Missing recipient. Set DVD_MAIL_TEST_TO or use --to=';
} else {
    $psScript = APP_ROOT . '/scripts/send_test_mail_outlook.ps1';
    if (!is_file($psScript)) {
        $result['error'] = 'PowerShell script not found: ' . $psScript;
    } else {
        $bodyFile = null;
        $cmd = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File '
            . escapeshellarg($psScript)
            . ' -To ' . escapeshellarg($to)
            . ' -Subject ' . escapeshellarg($subject);

        if ($body !== '') {
            $bodyFile = tempnam(sys_get_temp_dir(), 'dvd_mail_');
            if ($bodyFile === false || file_put_contents($bodyFile, $body) === false) {
                if (is_string($bodyFile)) {
                    @unlink($bodyFile);
                }
                $result['error'] = 'Failed to prepare temporary mail body file.';
            } else {
                $cmd .= ' -BodyFile ' . escapeshellarg($bodyFile);
            }
        }

        if ($result['error'] === null) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open($cmd, $descriptors, $pipes, APP_ROOT);
            if (!is_resource($process)) {
                $result['error'] = 'Failed to start PowerShell process.';
            } else {
                fclose($pipes[0]);
                $stdout = (string)stream_get_contents($pipes[1]);
                $stderr = (string)stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exitCode = proc_close($process);

                $combined = trim($stdout . "\n" . $stderr);
                $result['output'] = $combined;

                if ($combined !== '') {
                    $decoded = json_decode($combined, true);
                    if (is_array($decoded) && array_key_exists('ok', $decoded)) {
                        $result = array_merge($result, $decoded);
                        $result['ok'] = (bool)$result['ok'];
                        if ($result['ok']) {
                            $result['error'] = null;
                        }
                    } else {
                        $result['ok'] = $exitCode === 0;
                        $result['error'] = $exitCode === 0 ? null : ('PowerShell failed with exit code ' . $exitCode . '.');
                    }
                } else {
                    $result['ok'] = $exitCode === 0;
                    $result['error'] = $exitCode === 0 ? null : ('PowerShell failed with exit code ' . $exitCode . '.');
                }
            }
        }

        if (is_string($bodyFile)) {
            @unlink($bodyFile);
        }
    }
}

if (!$isCli) {
    header('Content-Type: application/json; charset=UTF-8');
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($result['ok'] ? 0 : 1);

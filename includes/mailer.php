<?php

if (!defined('APP_ROOT')) {
    require_once __DIR__ . '/config.php';
}

require_once APP_ROOT . '/includes/vendor/phpmailer/src/Exception.php';
require_once APP_ROOT . '/includes/vendor/phpmailer/src/PHPMailer.php';
require_once APP_ROOT . '/includes/vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

function appMailSubject(string $subject): string {
    $trimmed = trim($subject);
    if ($trimmed === '') {
        return '[DVD Production]';
    }

    if (str_starts_with($trimmed, '[DVD Production]')) {
        return $trimmed;
    }

    return '[DVD Production] ' . $trimmed;
}

function mailerIsConfigured(): bool {
    return MAIL_SMTP_HOST !== ''
        && MAIL_SMTP_PORT > 0
        && MAIL_FROM_ADDRESS !== '';
}

function appSendMail(array $recipients, string $subject, string $htmlBody, string $textBody = ''): array {
    if (!mailerIsConfigured()) {
        return ['ok' => false, 'error' => 'SMTP is not configured.'];
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = MAIL_SMTP_HOST;
        $mail->Port = MAIL_SMTP_PORT;
        $mail->SMTPAuth = MAIL_SMTP_USER !== '' || MAIL_SMTP_PASS !== '';
        $mail->Username = MAIL_SMTP_USER;
        $mail->Password = MAIL_SMTP_PASS;
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->SMTPDebug = MAIL_SMTP_DEBUG;

        if (MAIL_SMTP_ENCRYPTION === 'tls' || MAIL_SMTP_ENCRYPTION === 'ssl') {
            $mail->SMTPSecure = MAIL_SMTP_ENCRYPTION;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);

        foreach ($recipients as $recipient) {
            $trimmed = trim((string)$recipient);
            if ($trimmed !== '') {
                $mail->addAddress($trimmed);
            }
        }

        if (count($mail->getToAddresses()) === 0) {
            return ['ok' => false, 'error' => 'No recipient provided.'];
        }

        $mail->Subject = appMailSubject($subject);
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);

        $mail->send();
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function appSendOutlookMail(string $recipient, string $subject, string $textBody): array {
    $recipient = trim($recipient);
    if ($recipient === '') {
        return ['ok' => false, 'error' => 'No recipient provided.'];
    }

    $scriptPath = APP_ROOT . '/scripts/send_test_mail_outlook.ps1';
    if (!is_file($scriptPath)) {
        return ['ok' => false, 'error' => 'Outlook sender script not found.'];
    }

    $bodyFile = tempnam(sys_get_temp_dir(), 'dvd_mail_');
    if ($bodyFile === false) {
        return ['ok' => false, 'error' => 'Failed to create temporary mail body file.'];
    }

    $writeOk = file_put_contents($bodyFile, $textBody);
    if ($writeOk === false) {
        @unlink($bodyFile);
        return ['ok' => false, 'error' => 'Failed to write temporary mail body file.'];
    }

    $cmd = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File '
        . escapeshellarg($scriptPath)
        . ' -To ' . escapeshellarg($recipient)
        . ' -Subject ' . escapeshellarg(appMailSubject($subject))
        . ' -BodyFile ' . escapeshellarg($bodyFile);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes, APP_ROOT);
    if (!is_resource($process)) {
        return ['ok' => false, 'error' => 'Failed to start Outlook sender process.'];
    }

    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($bodyFile);

    $combined = trim($stdout . "\n" . $stderr);
    $decoded = $combined !== '' ? json_decode($combined, true) : null;

    if (is_array($decoded) && array_key_exists('ok', $decoded)) {
        return [
            'ok' => (bool)$decoded['ok'],
            'error' => (string)($decoded['error'] ?? ''),
            'output' => $combined,
        ];
    }

    return [
        'ok' => $exitCode === 0,
        'error' => $exitCode === 0 ? '' : ('Outlook sender failed with exit code ' . $exitCode . '.'),
        'output' => $combined,
    ];
}

function appSendInitialCredentialsOutlook(string $recipient, string $realName, string $username, string $password, string $role): array {
    $name = trim($realName) !== '' ? trim($realName) : $username;
    $subject = 'Nový uživatel ' . $username . ' byl vytvořen';
    $text = "Dobrý den {$name},\n"
        . "Do systému DVD Production byl přidán váš profil,\n"
        . 'Portál je dostupný na adrese: ' . APP_URL . "\n"
        . 'Vaše uživatelské jméno: ' . $username . "\n"
        . 'Vaše iniciální heslo: ' . $password . "\n"
        . 'Dostal jste roli: ' . $role . "\n\n"
        . 'Pěkný den fŘ';

    return appSendOutlookMail($recipient, $subject, $text);
}

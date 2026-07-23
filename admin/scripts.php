<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireSuperAdmin();

$pageTitle = t('scripts_title');
$activePage = 'scripts';

function findMysqlCliPath(): ?string {
    $candidates = [
        'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysql.exe',
        'C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function findPhpCliPath(): ?string {
    $candidates = [
        'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe',
        defined('PHP_BINARY') ? PHP_BINARY : null,
    ];

    foreach ($candidates as $candidate) {
        if ($candidate && is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function buildPhpScriptCommand(string $scriptPath, array $args = []): ?string {
    $phpBinary = findPhpCliPath();
    if ($phpBinary === null) {
        return null;
    }

    $phpDir = str_replace('\\', '/', dirname($phpBinary));
    $command = escapeshellarg($phpBinary)
        . ' -n'
        . ' -d extension_dir=' . escapeshellarg($phpDir . '/ext')
        . ' -d extension=openssl'
        . ' -d extension=pdo_mysql'
        . ' -d extension=mysqli'
        . ' -d extension=mbstring'
        . ' ' . escapeshellarg($scriptPath);

    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }

    return $command;
}

function runCommandCapture(string $command): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes, APP_ROOT);
    if (!is_resource($process)) {
        return ['ok' => false, 'output' => t('scripts_err_start_process')];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'ok' => $exitCode === 0,
        'exit_code' => $exitCode,
        'output' => trim((string)$stdout . "\n" . (string)$stderr),
    ];
}

function findGitCliPath(): ?string {
    $candidates = [
        'git',
        'C:/laragon/bin/git/cmd/git.exe',
        'C:/laragon/bin/git/bin/git.exe',
    ];

    foreach ($candidates as $candidate) {
        $cmd = escapeshellarg($candidate) . ' --version 2>&1';
        $lines = [];
        $exitCode = 1;
        exec($cmd, $lines, $exitCode);
        if ($exitCode === 0) {
            return $candidate;
        }
    }

    return null;
}

function getReleaseTags(string $gitBinary): array {
    $cmd = escapeshellarg($gitBinary)
        . ' -C ' . escapeshellarg(APP_ROOT)
        . ' tag --list v*.*.*';

    $result = runCommandCapture($cmd);
    if (!$result['ok']) {
        return [];
    }

    $tags = array_filter(array_map('trim', explode("\n", (string)$result['output'])));
    $tags = array_values(array_filter($tags, static function (string $tag): bool {
        return (bool)preg_match('/^v\d+\.\d+\.\d+[a-z]*$/', $tag);
    }));

    usort($tags, static function (string $a, string $b): int {
        return version_compare(ltrim($b, 'v'), ltrim($a, 'v'));
    });

    return $tags;
}

$scripts = [
    'validate_all' => [
        'title' => t('scripts_validate_all_title'),
        'description' => t('scripts_validate_all_desc'),
        'type' => 'sql',
        'path' => APP_ROOT . '/scripts/sql/validate_all.sql',
    ],
    'validate_import' => [
        'title' => t('scripts_validate_import_title'),
        'description' => t('scripts_validate_import_desc'),
        'type' => 'sql',
        'path' => APP_ROOT . '/scripts/sql/validate_import_data.sql',
    ],
    'validate_workers' => [
        'title' => t('scripts_validate_workers_title'),
        'description' => t('scripts_validate_workers_desc'),
        'type' => 'sql',
        'path' => APP_ROOT . '/scripts/sql/validate_pracovnici_data.sql',
    ],
    'validate_zapisy_jmeno' => [
        'title' => t('scripts_validate_zapisy_jmeno_title'),
        'description' => t('scripts_validate_zapisy_jmeno_desc'),
        'type' => 'sql',
        'path' => APP_ROOT . '/scripts/sql/validate_zapisy_jmeno_format.sql',
    ],
    'fix_zapisy_typ_template' => [
        'title' => t('scripts_fix_zapisy_typ_template_title'),
        'description' => t('scripts_fix_zapisy_typ_template_desc'),
        'type' => 'sql',
        'path' => APP_ROOT . '/scripts/sql/fix_zapisy_typ_by_template.sql',
    ],
    'cleanup_import' => [
        'title' => t('scripts_cleanup_import_title'),
        'description' => t('scripts_cleanup_import_desc'),
        'type' => 'sql',
        'path' => APP_ROOT . '/scripts/sql/cleanup_import_data.sql',
    ],
    'cleanup_workers' => [
        'title' => t('scripts_cleanup_workers_title'),
        'description' => t('scripts_cleanup_workers_desc'),
        'type' => 'sql',
        'path' => APP_ROOT . '/scripts/sql/cleanup_pracovnici_duplicates.sql',
    ],
    'setup_languages' => [
        'title' => t('scripts_setup_languages_title'),
        'description' => t('scripts_setup_languages_desc'),
        'type' => 'php',
        'path' => APP_ROOT . '/scripts/setup_languages.php',
    ],
    'backup_db' => [
        'title' => t('scripts_backup_db_title'),
        'description' => t('scripts_backup_db_desc'),
        'type' => 'php',
        'path' => APP_ROOT . '/scripts/backup_db.php',
    ],
    'consolidate_pracovisite' => [
        'title' => t('scripts_consolidate_pracovisite_title'),
        'description' => t('scripts_consolidate_pracovisite_desc'),
        'type' => 'php',
        'path' => APP_ROOT . '/scripts/consolidate_pracovisite.php',
        'args' => ['--apply'],
    ],
    'send_test_mail' => [
        'title' => t('scripts_send_test_mail_title'),
        'description' => t('scripts_send_test_mail_desc'),
        'type' => 'php',
        'path' => APP_ROOT . '/scripts/send_test_mail.php',
        'accept_target_email' => true,
    ],
    'send_test_mail_outlook' => [
        'title' => t('scripts_send_test_mail_outlook_title'),
        'description' => t('scripts_send_test_mail_outlook_desc'),
        'type' => 'php',
        'path' => APP_ROOT . '/scripts/send_test_mail_outlook.php',
        'accept_target_email' => true,
    ],
];

$runResult = null;
$deployResult = null;
$releaseCreateResult = null;
$defaultTargetEmail = MAIL_TEST_TO !== '' ? MAIL_TEST_TO : 'filip.rimek@prettl.com';
$productionRoot = 'C:/laragon/www';
$productionConfig = $productionRoot . '/includes/config.php';
$productionAppUrl = 'http://10.67.100.69';
$gitBinary = findGitCliPath();
$availableReleaseTags = $gitBinary !== null ? getReleaseTags($gitBinary) : [];
$defaultReleaseTag = 'v' . APP_VERSION;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'create_release_tag') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $newTag = trim((string)post('release_tag_name'));

    if ($gitBinary === null) {
        $releaseCreateResult = ['ok' => false, 'title' => t('release_create_result_err'), 'output' => t('release_deploy_git_not_found')];
    } elseif (!preg_match('/^v\d+\.\d+\.\d+[a-z]*$/', $newTag)) {
        $releaseCreateResult = ['ok' => false, 'title' => t('release_create_result_err'), 'output' => t('release_create_invalid_format') . ' (format: vX.Y.Z)'];
    } elseif (in_array($newTag, $availableReleaseTags, true)) {
        $releaseCreateResult = ['ok' => false, 'title' => t('release_create_result_err'), 'output' => t('release_create_already_exists') . ': ' . $newTag];
    } else {
        // Create tag with message
        $tagMessage = 'Release ' . $newTag;
        $createCommand = escapeshellarg($gitBinary)
            . ' -C ' . escapeshellarg(APP_ROOT)
            . ' tag -a ' . escapeshellarg($newTag)
            . ' -m ' . escapeshellarg($tagMessage);

        $createResult = runCommandCapture($createCommand);
        if (!$createResult['ok']) {
            $releaseCreateResult = [
                'ok' => false,
                'title' => t('release_create_result_err'),
                'output' => t('release_create_failed') . "\n\n" . $createResult['output'],
            ];
        } else {
            // Push tag
            $pushCommand = escapeshellarg($gitBinary)
                . ' -C ' . escapeshellarg(APP_ROOT)
                . ' push origin ' . escapeshellarg($newTag);

            $pushResult = runCommandCapture($pushCommand);
            if (!$pushResult['ok']) {
                $releaseCreateResult = [
                    'ok' => false,
                    'title' => t('release_create_result_err'),
                    'output' => t('release_create_push_failed') . "\n\n" . $pushResult['output'],
                ];
            } else {
                $releaseCreateResult = [
                    'ok' => true,
                    'title' => t('release_create_result_ok'),
                    'output' => t('release_create_success') . ': ' . $newTag . "\n\n"
                        . "Tag created and pushed to origin.\n"
                        . "You can now deploy this release from the Deploy Release section.",
                ];
                // Refresh available tags
                $availableReleaseTags = getReleaseTags($gitBinary);
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'run_script') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $scriptKey = (string)post('script_key');
    if (isset($scripts[$scriptKey])) {
        $script = $scripts[$scriptKey];

        if (!is_file($script['path'])) {
            $runResult = ['ok' => false, 'title' => $script['title'], 'output' => t('scripts_err_file_not_found') . ': ' . $script['path']];
        } else {
            if ($script['type'] === 'sql') {
                $mysqlPath = findMysqlCliPath();
                if ($mysqlPath === null) {
                    $runResult = ['ok' => false, 'title' => $script['title'], 'output' => t('scripts_err_mysql_not_found')];
                } else {
                    $cmd = escapeshellarg($mysqlPath)
                        . ' -u ' . escapeshellarg(DB_USER)
                        . ' -p' . escapeshellarg(DB_PASS)
                        . ' ' . escapeshellarg(DB_NAME)
                        . ' -e ' . escapeshellarg('source ' . str_replace('\\', '/', $script['path']));
                    $runResult = runCommandCapture($cmd);
                    $runResult['title'] = $script['title'];
                }
            } else {
                $runtimeArgs = $script['args'] ?? [];

                if (!empty($script['accept_target_email'])) {
                    $targetEmailRaw = trim((string)post('target_email'));
                    if ($targetEmailRaw !== '') {
                        if (filter_var($targetEmailRaw, FILTER_VALIDATE_EMAIL) === false) {
                            $runResult = [
                                'ok' => false,
                                'title' => $script['title'],
                                'output' => t('scripts_err_invalid_target_email') . ': ' . $targetEmailRaw,
                            ];
                        } else {
                            $runtimeArgs[] = '--to=' . $targetEmailRaw;
                        }
                    }
                }

                if ($runResult === null) {
                    $cmd = buildPhpScriptCommand($script['path'], $runtimeArgs);
                    if ($cmd === null) {
                        $runResult = ['ok' => false, 'title' => $script['title'], 'output' => t('scripts_err_start_process')];
                    } else {
                        $runResult = runCommandCapture($cmd);
                        $runResult['title'] = $script['title'];
                    }
                } else {
                    $runResult['title'] = $script['title'];
                }
            }
        }
    } else {
        $runResult = ['ok' => false, 'title' => t('scripts_unknown_script'), 'output' => t('scripts_unknown_script_key')];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'deploy_release') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $selectedTag = trim((string)post('release_tag'));

    if ($gitBinary === null) {
        $deployResult = ['ok' => false, 'title' => t('release_deploy_result_err'), 'output' => t('release_deploy_git_not_found')];
    } elseif (!preg_match('/^v\d+\.\d+\.\d+[a-z]*$/', $selectedTag)) {
        $deployResult = ['ok' => false, 'title' => t('release_deploy_result_err'), 'output' => t('release_deploy_invalid_tag')];
    } elseif (!in_array($selectedTag, $availableReleaseTags, true)) {
        $deployResult = ['ok' => false, 'title' => t('release_deploy_result_err'), 'output' => t('release_deploy_tag_not_found') . ': ' . $selectedTag];
    } elseif (!is_dir($productionRoot)) {
        $deployResult = ['ok' => false, 'title' => t('release_deploy_result_err'), 'output' => t('release_deploy_prod_missing') . ': ' . $productionRoot];
    } elseif (!is_file($productionConfig)) {
        $deployResult = ['ok' => false, 'title' => t('release_deploy_result_err'), 'output' => t('release_deploy_config_missing') . ': ' . $productionConfig];
    } else {
        $checkoutCommand = escapeshellarg($gitBinary)
            . ' -C ' . escapeshellarg(APP_ROOT)
            . ' --work-tree=' . escapeshellarg($productionRoot)
            . ' checkout -f ' . escapeshellarg($selectedTag)
            . ' -- :/';

        $checkoutResult = runCommandCapture($checkoutCommand);
        if (!$checkoutResult['ok']) {
            $deployResult = [
                'ok' => false,
                'title' => t('release_deploy_result_err'),
                'output' => t('release_deploy_checkout_failed') . "\n\n" . $checkoutResult['output'],
            ];
        } else {
            $configBody = file_get_contents($productionConfig);
            if (!is_string($configBody) || $configBody === '') {
                $deployResult = [
                    'ok' => false,
                    'title' => t('release_deploy_result_err'),
                    'output' => t('release_deploy_config_read_failed') . ': ' . $productionConfig,
                ];
            } else {
                $backupPath = $productionConfig . '.bak.' . date('Ymd_His');
                $backupOk = file_put_contents($backupPath, $configBody) !== false;
                if (!$backupOk) {
                    $deployResult = [
                        'ok' => false,
                        'title' => t('release_deploy_result_err'),
                        'output' => t('release_deploy_backup_failed') . ': ' . $backupPath,
                    ];
                } else {
                    $normalizedProductionAppUrl = rtrim($productionAppUrl, '/');
                    $replacementCount = 0;
                    $updatedConfigBody = preg_replace(
                        '/define\(\s*[\'\"]APP_URL[\'\"]\s*,\s*[\'\"][^\'\"]*[\'\"]\s*\);/',
                        "define('APP_URL', '" . $normalizedProductionAppUrl . "');",
                        $configBody,
                        1,
                        $replacementCount
                    );

                    if (!is_string($updatedConfigBody) || $replacementCount === 0) {
                        $deployResult = [
                            'ok' => false,
                            'title' => t('release_deploy_result_err'),
                            'output' => t('release_deploy_app_url_not_found') . ': ' . $productionConfig,
                        ];
                    } else {
                        $writeOk = file_put_contents($productionConfig, $updatedConfigBody) !== false;
                        if (!$writeOk) {
                            $deployResult = [
                                'ok' => false,
                                'title' => t('release_deploy_result_err'),
                                'output' => t('release_deploy_config_write_failed') . ': ' . $productionConfig,
                            ];
                        } else {
                            $deployResult = [
                                'ok' => true,
                                'title' => t('release_deploy_result_ok') . ' ' . $selectedTag,
                                'output' => t('release_deploy_success')
                                    . "\nRelease: " . $selectedTag
                                    . "\nProd root: " . $productionRoot
                                    . "\nConfig: " . $productionConfig
                                    . "\nAPP_URL: " . $normalizedProductionAppUrl
                                    . "\nBackup: " . $backupPath,
                            ];
                        }
                    }
                }
            }
        }
    }
}

require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-terminal me-2"></i><?= t('scripts_topbar') ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">
    <div class="card-dvd mb-3">
        <div class="card-body-dvd">
            <div style="font-size:.84rem;color:var(--muted)"><?= t('scripts_intro') ?></div>
        </div>
    </div>

    <?php if ($runResult !== null): ?>
    <div class="card-dvd mb-3">
        <div class="card-header-dvd">
            <span class="s-num"><?= $runResult['ok'] ? t('scripts_status_ok') : t('scripts_status_err') ?></span>
            <span class="s-title"><?= h($runResult['title']) ?></span>
        </div>
        <div class="card-body-dvd">
            <pre style="white-space:pre-wrap;font-size:.78rem;margin:0;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:8px;padding:.8rem"><?= h($runResult['output'] !== '' ? $runResult['output'] : t('scripts_no_output')) ?></pre>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($deployResult !== null): ?>
    <div class="card-dvd mb-3">
        <div class="card-header-dvd">
            <span class="s-num"><?= $deployResult['ok'] ? t('scripts_status_ok') : t('scripts_status_err') ?></span>
            <span class="s-title"><?= h($deployResult['title']) ?></span>
        </div>
        <div class="card-body-dvd">
            <pre style="white-space:pre-wrap;font-size:.78rem;margin:0;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:8px;padding:.8rem"><?= h($deployResult['output'] !== '' ? $deployResult['output'] : t('scripts_no_output')) ?></pre>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($releaseCreateResult !== null): ?>
    <div class="card-dvd mb-3">
        <div class="card-header-dvd">
            <span class="s-num"><?= $releaseCreateResult['ok'] ? t('scripts_status_ok') : t('scripts_status_err') ?></span>
            <span class="s-title"><?= h($releaseCreateResult['title']) ?></span>
        </div>
        <div class="card-body-dvd">
            <pre style="white-space:pre-wrap;font-size:.78rem;margin:0;background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:8px;padding:.8rem"><?= h($releaseCreateResult['output'] !== '' ? $releaseCreateResult['output'] : t('scripts_no_output')) ?></pre>
        </div>
    </div>
    <?php endif; ?>

    <div class="card-dvd mb-3">
        <div class="card-header-dvd">
            <span class="s-num">GIT</span>
            <span class="s-title"><?= t('release_create_title') ?></span>
        </div>
        <div class="card-body-dvd">
            <div style="font-size:.82rem;color:var(--muted);margin-bottom:.75rem"><?= t('release_create_intro') ?></div>
            <?php if ($gitBinary === null): ?>
                <div style="font-size:.82rem;color:#ff8d8d"><?= t('release_deploy_git_not_found') ?></div>
            <?php else: ?>
                <form method="post" class="d-flex flex-column flex-lg-row gap-2 align-items-lg-end">
                    <input type="hidden" name="action" value="create_release_tag">
                    <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                    <div style="min-width:260px;max-width:360px;width:100%">
                        <label for="release_tag_name" style="font-size:.82rem;color:var(--muted)"><?= t('release_create_tag_label') ?></label>
                        <input 
                            id="release_tag_name" 
                            type="text" 
                            name="release_tag_name" 
                            class="form-control" 
                            placeholder="<?= h('v' . APP_VERSION) ?>"
                            value="<?= h($defaultReleaseTag) ?>"
                            pattern="v\d+\.\d+\.\d+[a-z]*"
                            title="Format: vX.Y.Z (e.g. v5.1.5)"
                            required
                        >
                    </div>
                    <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-tag me-1"></i><?= t('release_create_run') ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-dvd mb-3">
        <div class="card-header-dvd">
            <span class="s-num">GIT</span>
            <span class="s-title"><?= t('release_deploy_select_release') ?></span>
        </div>
        <div class="card-body-dvd">
            <div style="font-size:.82rem;color:var(--muted);margin-bottom:.75rem"><?= t('release_deploy_intro') ?></div>
            <?php if ($gitBinary === null): ?>
                <div style="font-size:.82rem;color:#ff8d8d"><?= t('release_deploy_git_not_found') ?></div>
            <?php elseif (empty($availableReleaseTags)): ?>
                <div style="font-size:.82rem;color:var(--muted)"><?= t('release_deploy_no_releases') ?></div>
            <?php else: ?>
                <form method="post" class="d-flex flex-column flex-lg-row gap-2 align-items-lg-end">
                    <input type="hidden" name="action" value="deploy_release">
                    <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                    <div style="min-width:260px;max-width:360px;width:100%">
                        <label for="release_tag" style="font-size:.82rem;color:var(--muted)"><?= t('release_deploy_release_label') ?></label>
                        <select id="release_tag" name="release_tag" class="form-control" required>
                            <?php foreach ($availableReleaseTags as $tag): ?>
                                <option value="<?= h($tag) ?>"><?= h($tag) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-upload me-1"></i><?= t('release_deploy_run') ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3">
        <?php foreach ($scripts as $key => $script): ?>
        <div class="col-12 col-lg-6">
            <div class="card-dvd h-100">
                <div class="card-header-dvd">
                    <span class="s-num"><?= strtoupper($script['type']) ?></span>
                    <span class="s-title"><?= h($script['title']) ?></span>
                </div>
                <div class="card-body-dvd">
                    <div style="font-size:.82rem;color:var(--muted);margin-bottom:.6rem"><?= h($script['description']) ?></div>
                    <div class="mono" style="font-size:.68rem;color:var(--accent3);margin-bottom:.75rem"><?= h(basename($script['path'])) ?></div>
                    <form method="post">
                        <input type="hidden" name="action" value="run_script">
                        <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                        <input type="hidden" name="script_key" value="<?= h($key) ?>">
                        <?php if (!empty($script['accept_target_email'])): ?>
                            <div style="margin-bottom:.7rem">
                                <label for="target_email_<?= h($key) ?>" style="font-size:.78rem;color:var(--muted);display:block;margin-bottom:.2rem"><?= t('scripts_target_email_label') ?></label>
                                <input
                                    id="target_email_<?= h($key) ?>"
                                    type="email"
                                    name="target_email"
                                    class="form-control"
                                    placeholder="<?= h(t('scripts_target_email_placeholder')) ?>"
                                    value="<?= h($defaultTargetEmail) ?>"
                                    required
                                >
                            </div>
                        <?php endif; ?>
                        <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-play-fill me-1"></i><?= t('scripts_run') ?></button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>

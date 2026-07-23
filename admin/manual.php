<?php
require_once dirname(__DIR__) . '/includes/config.php';
requireLogin();

$pageTitle = t('manual_title');
$activePage = 'manual';

$baseUrl = APP_URL;
$manualMode = (string)get('manual_mode', 'detailed');
if (!in_array($manualMode, ['concise', 'detailed'], true)) {
    $manualMode = 'detailed';
}

$manualSections = [];
$addSection = static function (string $title, array $items) use (&$manualSections): void {
    if ($items !== []) {
        $manualSections[] = [
            'title' => $title,
            'items' => $items,
        ];
    }
};

$tr = static function (array $values, string $langCode): string {
    return (string)($values[$langCode] ?? $values['cs'] ?? '');
};
$trList = static function (array $values, string $langCode): array {
    $list = $values[$langCode] ?? $values['cs'] ?? [];
    return is_array($list) ? array_values($list) : [];
};
$limitList = static function (array $items, int $max): array {
    return $max > 0 ? array_slice($items, 0, $max) : [];
};

$loadLocalizedList = static function (string $prefix, int $max = 20): array {
    $items = [];
    for ($i = 1; $i <= $max; $i++) {
        $key = $prefix . $i;
        $value = t($key);
        if ($value === $key) {
            break;
        }
        $items[] = $value;
    }
    return $items;
};

$manualPathPrefix = trim((string)parse_url(APP_URL, PHP_URL_PATH), '/');
$manualKeyFromUrl = static function (string $url) use ($manualPathPrefix): string {
    $path = (string)parse_url($url, PHP_URL_PATH);
    $normalized = ltrim($path, '/');
    if ($manualPathPrefix !== '' && str_starts_with($normalized, $manualPathPrefix . '/')) {
        $normalized = substr($normalized, strlen($manualPathPrefix) + 1);
    }
    return $normalized;
};

$manualGuideKeys = [
    'index.php' => [
        'purpose' => 'manual_guide_index_purpose',
        'steps' => 'manual_guide_index_step_',
        'inputs' => 'manual_guide_index_input_',
        'errors' => 'manual_guide_index_error_',
        'tips' => 'manual_guide_index_tip_',
    ],
    'admin/zaznamy.php' => [
        'purpose' => 'manual_guide_records_purpose',
        'steps' => 'manual_guide_records_step_',
        'errors' => 'manual_guide_records_error_',
    ],
    'admin/oee.php' => [
        'purpose' => 'manual_guide_oee_purpose',
        'steps' => 'manual_guide_oee_step_',
    ],
    'admin/traceability_terminal.php' => [
        'purpose' => 'manual_guide_trace_terminal_purpose',
        'steps' => 'manual_guide_trace_terminal_step_',
        'inputs' => 'manual_guide_trace_terminal_input_',
        'errors' => 'manual_guide_trace_terminal_error_',
        'tips' => 'manual_guide_trace_terminal_tip_',
    ],
    'admin/traceability.php' => [
        'purpose' => 'manual_guide_trace_report_purpose',
        'steps' => 'manual_guide_trace_report_step_',
    ],
    'admin/rework_terminal.php' => [
        'purpose' => 'manual_guide_rework_terminal_purpose',
        'steps' => 'manual_guide_rework_terminal_step_',
    ],
    'admin/import.php' => [
        'purpose' => 'manual_guide_import_purpose',
        'steps' => 'manual_guide_import_step_',
        'errors' => 'manual_guide_import_error_',
    ],
    'admin/users.php' => [
        'purpose' => 'manual_guide_users_purpose',
        'steps' => 'manual_guide_users_step_',
    ],
    'admin/profile.php' => [
        'purpose' => 'manual_guide_profile_purpose',
        'steps' => 'manual_guide_profile_step_',
    ],
];

$getGuideForItem = static function (array $item) use ($manualKeyFromUrl, $manualGuideKeys, $loadLocalizedList): array {
    $manualKey = $manualKeyFromUrl((string)$item['url']);
    $guide = $manualGuideKeys[$manualKey] ?? [];
    if ($guide === []) {
        return [
            'purpose' => (string)$item['desc'],
            'steps' => [
                t('manual_fallback_step_1'),
                t('manual_fallback_step_2'),
                t('manual_fallback_step_3'),
                t('manual_fallback_step_4'),
            ],
            'inputs' => [
                t('manual_fallback_input_1'),
                t('manual_fallback_input_2'),
            ],
            'errors' => [
                t('manual_fallback_error_1'),
                t('manual_fallback_error_2'),
            ],
            'tips' => [
                t('manual_fallback_tip_1'),
                t('manual_fallback_tip_2'),
            ],
        ];
    }

    $result = [
        'purpose' => isset($guide['purpose']) ? t((string)$guide['purpose']) : (string)$item['desc'],
        'steps' => isset($guide['steps']) ? $loadLocalizedList((string)$guide['steps']) : [],
        'inputs' => isset($guide['inputs']) ? $loadLocalizedList((string)$guide['inputs']) : [],
        'errors' => isset($guide['errors']) ? $loadLocalizedList((string)$guide['errors']) : [],
        'tips' => isset($guide['tips']) ? $loadLocalizedList((string)$guide['tips']) : [],
    ];

    if ($result['inputs'] === []) {
        $result['inputs'] = [
            t('manual_fallback_default_input_1'),
        ];
    }
    if ($result['errors'] === []) {
        $result['errors'] = [
            t('manual_fallback_default_error_1'),
        ];
    }
    if ($result['tips'] === []) {
        $result['tips'] = [
            t('manual_fallback_default_tip_1'),
        ];
    }

    return $result;
};

$dvdItems = [];
if (canWrite()) {
    $dvdItems[] = ['label' => t('nav_form'), 'url' => $baseUrl . '/index.php', 'desc' => t('manual_desc_form')];
}
if (hasAnyRole(['superadmin', 'admin']) && !hasOnlyRole('rework')) {
    $dvdItems[] = ['label' => t('nav_records'), 'url' => $baseUrl . '/admin/zaznamy.php', 'desc' => t('manual_desc_records')];
    $dvdItems[] = ['label' => t('nav_workers'), 'url' => $baseUrl . '/admin/zaznamy_pracovnici.php', 'desc' => t('manual_desc_workers')];
    $dvdItems[] = ['label' => t('nav_oee'), 'url' => $baseUrl . '/admin/oee.php', 'desc' => t('manual_desc_oee')];
    if (hasAnyRole(['superadmin', 'admin'])) {
        $dvdItems[] = ['label' => t('nav_weekly_production'), 'url' => $baseUrl . '/admin/kt_report_all.php', 'desc' => t('manual_desc_weekly_production')];
    }
    $dvdItems[] = ['label' => t('nav_tv'), 'url' => $baseUrl . '/admin/tv.php', 'desc' => t('manual_desc_tv')];
}
$addSection('DVD', $dvdItems);

$traceabilityItems = [];
if (hasAnyRole(['superadmin', 'admin', 'traceability', 'writer', 'read']) && !hasOnlyRole('rework')) {
    if (hasAnyRole(['superadmin', 'admin', 'writer', 'traceability'])) {
        $traceabilityItems[] = ['label' => t('nav_traceability_terminal'), 'url' => $baseUrl . '/admin/traceability_terminal.php', 'desc' => t('manual_desc_traceability_terminal')];
    }
    $traceabilityItems[] = ['label' => t('nav_traceability_kiosk'), 'url' => $baseUrl . '/admin/traceability_kiosk_login.php', 'desc' => t('manual_desc_traceability_kiosk')];
    $traceabilityItems[] = ['label' => t('nav_traceability'), 'url' => $baseUrl . '/admin/traceability.php', 'desc' => t('manual_desc_traceability')];
    if (hasAnyRole(['superadmin', 'admin', 'writer', 'traceability'])) {
        $traceabilityItems[] = ['label' => t('nav_traceability_records'), 'url' => $baseUrl . '/admin/traceability_records.php', 'desc' => t('manual_desc_traceability_records')];
    }
    $traceabilityItems[] = ['label' => t('nav_traceability_shift'), 'url' => $baseUrl . '/admin/traceability_shift.php', 'desc' => t('manual_desc_traceability_shift')];
    $traceabilityItems[] = ['label' => t('nav_traceability_tv'), 'url' => $baseUrl . '/admin/traceability_tv.php', 'desc' => t('manual_desc_traceability_tv')];
}
$addSection('Traceability', $traceabilityItems);

$reworkItems = [];
if (hasAnyRole(['superadmin', 'admin', 'rework', 'rework_admin'])) {
    if (hasAnyRole(['superadmin', 'admin', 'rework_admin'])) {
        $reworkItems[] = ['label' => t('nav_rework_terminal'), 'url' => $baseUrl . '/admin/rework_terminal.php', 'desc' => t('manual_desc_rework_terminal')];
    }
    $reworkItems[] = ['label' => t('nav_rework_kiosk'), 'url' => $baseUrl . '/admin/rework_kiosk_login.php', 'desc' => t('manual_desc_rework_kiosk')];
    $reworkItems[] = ['label' => t('nav_rework_report'), 'url' => $baseUrl . '/admin/rework_report.php', 'desc' => t('manual_desc_rework_report')];
    if (hasRole('rework_admin')) {
        $reworkItems[] = ['label' => t('nav_rework_projects'), 'url' => $baseUrl . '/admin/rework_projects.php', 'desc' => t('manual_desc_rework_projects')];
        $reworkItems[] = ['label' => t('nav_rework_reasons'), 'url' => $baseUrl . '/admin/rework_reasons.php', 'desc' => t('manual_desc_rework_reasons')];
        $reworkItems[] = ['label' => t('nav_rework_actions'), 'url' => $baseUrl . '/admin/rework_actions.php', 'desc' => t('manual_desc_rework_actions')];
    }
}
$addSection(t('nav_repairs'), $reworkItems);

$setterItems = [];
if (hasAnyRole(['superadmin', 'admin', 'serizovac', 'serizovac_admin'])) {
    $setterItems[] = ['label' => t('nav_serizovac_checklist'), 'url' => $baseUrl . '/admin/serizovac_checklist.php', 'desc' => t('manual_desc_serizovac_checklist')];
    if (hasAnyRole(['superadmin', 'admin', 'serizovac_admin'])) {
        $setterItems[] = ['label' => t('nav_serizovac_editor'), 'url' => $baseUrl . '/admin/serizovac_editor.php', 'desc' => t('manual_desc_serizovac_editor')];
    }
}
$addSection(t('nav_serizovaci'), $setterItems);

$managementItems = [];
if (canAdmin() || hasRole('serizovac_admin')) {
    if (canAdmin()) {
        $managementItems[] = ['label' => t('nav_pracovisite'), 'url' => $baseUrl . '/admin/pracovisite.php', 'desc' => t('manual_desc_pracovisite')];
        $managementItems[] = ['label' => t('nav_workers'), 'url' => $baseUrl . '/admin/pracovnici.php', 'desc' => t('manual_desc_workers_manage')];
        $managementItems[] = ['label' => t('nav_smeny'), 'url' => $baseUrl . '/admin/smeny.php', 'desc' => t('manual_desc_smeny')];
        $managementItems[] = ['label' => t('nav_nok'), 'url' => $baseUrl . '/admin/nok_kody.php', 'desc' => t('manual_desc_nok')];
        $managementItems[] = ['label' => t('nav_prostoje'), 'url' => $baseUrl . '/admin/prostoj_typy.php', 'desc' => t('manual_desc_prostoje')];
    }
    if (hasAnyRole(['superadmin', 'admin', 'traceability'])) {
        $managementItems[] = ['label' => t('nav_writer_users'), 'url' => $baseUrl . '/admin/users.php?scope=traceability', 'desc' => t('manual_desc_writer_users')];
    }
    if (canAdmin()) {
        $managementItems[] = ['label' => t('nav_rework_projects'), 'url' => $baseUrl . '/admin/rework_projects.php', 'desc' => t('manual_desc_rework_projects_manage')];
        $managementItems[] = ['label' => t('nav_rework_reasons'), 'url' => $baseUrl . '/admin/rework_reasons.php', 'desc' => t('manual_desc_rework_reasons_manage')];
        $managementItems[] = ['label' => t('nav_rework_actions'), 'url' => $baseUrl . '/admin/rework_actions.php', 'desc' => t('manual_desc_rework_actions_manage')];
        if (canAdmin() || hasRole('serizovac_admin')) {
            $managementItems[] = ['label' => t('nav_serizovac_catalog'), 'url' => $baseUrl . '/admin/serizovac_checklist_catalog.php', 'desc' => t('manual_desc_serizovac_catalog')];
        }
        $managementItems[] = ['label' => t('nav_users'), 'url' => $baseUrl . '/admin/users.php', 'desc' => t('manual_desc_users')];
        $managementItems[] = ['label' => t('nav_import'), 'url' => $baseUrl . '/admin/import.php', 'desc' => t('manual_desc_import')];
        $managementItems[] = ['label' => t('manage_translations'), 'url' => $baseUrl . '/admin/translations.php', 'desc' => t('manual_desc_translations')];
        $managementItems[] = ['label' => t('audit_log'), 'url' => $baseUrl . '/admin/audit_log.php', 'desc' => t('manual_desc_audit_log')];
        if (isSuperAdmin()) {
            $managementItems[] = ['label' => t('nav_scripts'), 'url' => $baseUrl . '/admin/scripts.php', 'desc' => t('manual_desc_scripts')];
        }
    }
}
$addSection(t('nav_admin'), $managementItems);

$accountItems = [['label' => t('profile'), 'url' => $baseUrl . '/admin/profile.php', 'desc' => t('manual_desc_profile')]];
$addSection(t('account'), $accountItems);

require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><?= h(t('manual_title')) ?></span>
    </div>
    <div class="d-flex gap-2 align-items-center no-print">
        <button type="button" onclick="window.print()" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem">
            <i class="bi bi-printer me-1"></i><?= t('print') ?>
        </button>
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content manual-content-fix">
    <div class="card-dvd manual-contents-card" style="margin-bottom:1rem;">
        <div class="card-header-dvd"><span class="s-num"><i class="bi bi-book"></i></span><span class="s-title"><?= h(t('manual_title')) ?></span></div>
        <div class="card-body-dvd">
            <p class="text-muted-dvd" style="margin-bottom:0;"><?= h(t('manual_intro')) ?></p>
        </div>
    </div>

    <div class="card-dvd" style="margin-bottom:1rem;">
        <div class="card-header-dvd"><span class="s-num"><i class="bi bi-sliders"></i></span><span class="s-title"><?= h(t('manual_ui_mode_title')) ?></span></div>
        <div class="card-body-dvd">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <?php
                    $modeConciseUrl = '?' . http_build_query(array_merge($_GET, ['manual_mode' => 'concise']));
                    $modeDetailedUrl = '?' . http_build_query(array_merge($_GET, ['manual_mode' => 'detailed']));
                ?>
                <a href="<?= h($modeConciseUrl) ?>" class="<?= $manualMode === 'concise' ? 'btn-dvd btn-dvd-sm' : 'btn-outline' ?>" style="text-decoration:none;padding:.36rem .78rem;font-size:.76rem;">
                    <?= h(t('manual_ui_mode_concise')) ?>
                </a>
                <a href="<?= h($modeDetailedUrl) ?>" class="<?= $manualMode === 'detailed' ? 'btn-dvd btn-dvd-sm' : 'btn-outline' ?>" style="text-decoration:none;padding:.36rem .78rem;font-size:.76rem;">
                    <?= h(t('manual_ui_mode_detailed')) ?>
                </a>
                <span class="text-muted-dvd" style="font-size:.76rem;">
                    <?= h($manualMode === 'concise' ? t('manual_ui_mode_hint_concise') : t('manual_ui_mode_hint_detailed')) ?>
                </span>
            </div>
        </div>
    </div>

    <div class="card-dvd" style="margin-bottom:1rem;">
        <div class="card-header-dvd"><span class="s-num"><i class="bi bi-play-circle"></i></span><span class="s-title"><?= h(t('manual_ui_quick_start')) ?></span></div>
        <div class="card-body-dvd">
            <?php $quickSteps = $loadLocalizedList('manual_quick_step_'); ?>
            <ol style="margin:0;padding-left:1.15rem;display:grid;gap:.35rem;">
                <?php foreach (($manualMode === 'concise' ? $limitList($quickSteps, 4) : $quickSteps) as $quickStep): ?>
                    <li><?= h($quickStep) ?></li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>

    <div class="card-dvd" style="margin-bottom:1rem;">
        <div class="card-header-dvd"><span class="s-num"><i class="bi bi-list-ul"></i></span><span class="s-title"><?= h(t('manual_contents')) ?></span></div>
        <div class="card-body-dvd">
            <div class="row g-2">
                <?php foreach ($manualSections as $idx => $section): ?>
                    <div class="col-12 col-md-6 col-lg-4">
                        <a href="#manual-section-<?= (int)$idx ?>" class="btn-outline d-block" style="padding:.55rem .75rem;text-decoration:none;">
                            <i class="bi bi-chevron-right me-1"></i><?= h($section['title']) ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php foreach ($manualSections as $idx => $section): ?>
        <div class="card-dvd" id="manual-section-<?= (int)$idx ?>" style="margin-bottom:1rem;scroll-margin-top:90px;">
            <div class="card-header-dvd"><span class="s-num"><?= str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT) ?></span><span class="s-title"><?= h($section['title']) ?></span></div>
            <div class="card-body-dvd" style="padding:.9rem 1rem;">
                <div style="display:grid;gap:.75rem;">
                    <?php foreach ($section['items'] as $itemIdx => $item): ?>
                        <?php
                            $guide = $getGuideForItem($item);
                            $guideSteps = $guide['steps'];
                            $guideInputs = $guide['inputs'];
                            $guideErrors = $guide['errors'];
                            $guideTips = $guide['tips'];
                            if ($manualMode === 'concise') {
                                $guideSteps = $limitList($guideSteps, 3);
                                $guideInputs = $limitList($guideInputs, 1);
                                $guideErrors = $limitList($guideErrors, 1);
                                $guideTips = $limitList($guideTips, 1);
                            }
                        ?>
                        <article class="manual-guide-card" style="border:1px solid var(--border);border-radius:12px;padding:.8rem .9rem;background:rgba(255,255,255,.02);overflow-x:auto;">
                            <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:flex-start;flex-wrap:wrap;">
                                <div style="min-width:0;">
                                    <div class="mono text-muted-dvd" style="font-size:.72rem;word-break:break-all;overflow-wrap:anywhere;">#<?= (int)($itemIdx + 1) ?> · <?= h($item['url']) ?></div>
                                    <h3 style="margin:.15rem 0 .35rem 0;font-size:1rem;overflow-wrap:anywhere;"><?= h($item['label']) ?></h3>
                                </div>
                                <a href="<?= h($item['url']) ?>" target="_blank" rel="noopener noreferrer" class="btn-outline" style="padding:.33rem .62rem;font-size:.72rem;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem;">
                                    <i class="bi bi-box-arrow-up-right"></i><?= h(t('manual_open')) ?>
                                </a>
                            </div>

                            <p style="margin:.25rem 0 .6rem 0;overflow-wrap:anywhere;"><?= h((string)$guide['purpose']) ?></p>

                            <?php if ($guideSteps !== []): ?>
                                <div style="margin-bottom:.45rem;">
                                    <div class="text-muted-dvd" style="font-size:.74rem;font-weight:600;margin-bottom:.2rem;"><?= h(t('manual_ui_how_to')) ?></div>
                                    <ol style="margin:0;padding-left:1.15rem;display:grid;gap:.22rem;">
                                        <?php foreach ($guideSteps as $step): ?>
                                            <li style="overflow-wrap:anywhere;"><?= h((string)$step) ?></li>
                                        <?php endforeach; ?>
                                    </ol>
                                </div>
                            <?php endif; ?>

                            <?php if ($guideInputs !== []): ?>
                                <div>
                                    <div class="text-muted-dvd" style="font-size:.74rem;font-weight:600;margin-bottom:.2rem;"><?= h(t('manual_ui_inputs')) ?></div>
                                    <ul style="margin:0;padding-left:1.1rem;display:grid;gap:.2rem;">
                                        <?php foreach ($guideInputs as $note): ?>
                                            <li style="overflow-wrap:anywhere;"><?= h((string)$note) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <?php if ($guideErrors !== []): ?>
                                <div style="margin-top:.45rem;">
                                    <div class="text-muted-dvd" style="font-size:.74rem;font-weight:600;margin-bottom:.2rem;"><?= h(t('manual_ui_errors')) ?></div>
                                    <ul style="margin:0;padding-left:1.1rem;display:grid;gap:.2rem;">
                                        <?php foreach ($guideErrors as $err): ?>
                                            <li style="overflow-wrap:anywhere;"><?= h((string)$err) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <?php if ($guideTips !== []): ?>
                                <div style="margin-top:.45rem;">
                                    <div class="text-muted-dvd" style="font-size:.74rem;font-weight:600;margin-bottom:.2rem;"><?= h(t('manual_ui_tips')) ?></div>
                                    <ul style="margin:0;padding-left:1.1rem;display:grid;gap:.2rem;">
                                        <?php foreach ($guideTips as $tip): ?>
                                            <li style="overflow-wrap:anywhere;"><?= h((string)$tip) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>

<?php
// admin/translations.php – Správa překladů přes UI - všechny jazyky vedle sebe
require_once dirname(__DIR__) . '/includes/config.php';
requireRole('superadmin');

$pageTitle = t('manage_translations');
$activePage = 'translations';
$fSearch = trim((string) get('search', ''));

// Uložit všechny jazyky jednu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    if (!verifyCsrfToken(post('_csrf'))) { http_response_code(403); die('CSRF'); }
    $key = post('key');
    $cs = post('cs');
    $en = post('en');
    $uk = post('uk');
    
    // Uložit do všech jazyků
    foreach (['cs' => $cs, 'en' => $en, 'uk' => $uk] as $lang => $value) {
        $oldVal = db_val('SELECT `value` FROM translations WHERE lang_code=? AND `key`=?', [$lang, $key]);
        
        db()->prepare('INSERT INTO translations (lang_code, `key`, `value`) VALUES (?,?,?) 
                      ON DUPLICATE KEY UPDATE `value`=?, updated_at=NOW()')
           ->execute([$lang, $key, $value, $value]);
        
        if ($oldVal !== $value) {
            logAction('TRANSLATION_UPDATE', 'translations', null, 
                      ['lang' => $lang, 'key' => $key, 'old_value' => $oldVal], 
                      ['lang' => $lang, 'key' => $key, 'new_value' => $value], 
                      t('translation_updated') . ": $lang / $key");
            LanguageLoader::reload($lang);
        }
    }
    
    redirect(APP_URL . '/admin/translations.php?ok=1');
}

// Načti všechny translation klíče se všemi jazyky
$having = '';
$params = [];
if ($fSearch) {
    $having = "HAVING (
        t.`key` LIKE ?
        OR COALESCE(MAX(CASE WHEN lang_code='cs' THEN t.value ELSE NULL END), '') LIKE ?
        OR COALESCE(MAX(CASE WHEN lang_code='en' THEN t.value ELSE NULL END), '') LIKE ?
        OR COALESCE(MAX(CASE WHEN lang_code='uk' THEN t.value ELSE NULL END), '') LIKE ?
    )";
    $params = ['%'.$fSearch.'%', '%'.$fSearch.'%', '%'.$fSearch.'%', '%'.$fSearch.'%'];
}

$translations = db_all(
    "SELECT 
        t.`key`,
        MAX(CASE WHEN lang_code='cs' THEN t.value ELSE NULL END) as cs,
        MAX(CASE WHEN lang_code='en' THEN t.value ELSE NULL END) as en,
        MAX(CASE WHEN lang_code='uk' THEN t.value ELSE NULL END) as uk,
        MAX(t.updated_at) as updated_at
    FROM translations t
    GROUP BY t.`key`
    $having
    ORDER BY t.`key`",
    $params
);

require APP_ROOT . '/includes/layout.php';
?>

<div class="topbar">
    <div class="d-flex align-items-center gap-3">
        <button class="btn-icon d-md-none" id="sidebar-toggle"><i class="bi bi-list fs-5"></i></button>
        <span class="topbar-title"><i class="bi bi-translate me-2"></i><?= t('manage_translations') ?></span>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="logout.php" class="btn-outline" style="padding:.38rem .9rem;font-size:.78rem;white-space:nowrap"><i class="bi bi-box-arrow-left me-1"></i><?= t('nav_logout') ?></a>
    </div>
</div>

<div class="content">
    <div class="row mb-3 no-print">
        <div class="col-md-8">
            <form method="get" class="d-flex gap-2">
                <input type="text" name="search" class="form-control" placeholder="<?= t('search_key_translation') ?>" value="<?= h($fSearch) ?>">
                <button type="submit" class="btn-dvd btn-dvd-sm"><i class="bi bi-search"></i></button>
                <?php if ($fSearch): ?><a href="translations.php" class="btn-outline" style="padding:.35rem .8rem;font-size:.78rem">✕</a><?php endif; ?>
            </form>
        </div>
    </div>

    <?php if (get('ok')): ?>
    <div class="alert" style="background:rgba(74,222,128,.12);border:1px solid rgba(74,222,128,.3);color:var(--success);border-radius:8px;padding:.65rem 1rem;margin-bottom:1rem;font-size:.82rem">
        ✓ <?= t('translation_saved') ?>
    </div>
    <?php endif; ?>

    <?php if (empty($translations)): ?>
    <div style="text-align:center;padding:2rem;color:var(--muted);">
        <i class="bi bi-inbox" style="font-size:2rem;"></i>
        <p><?= t('no_translations') ?> <?= $fSearch ? t('matching_search') : '' ?></p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <div class="tbl-wrap">
        <div class="overflow-x">
        <table class="tbl">
            <thead>
                <tr>
                    <th style="width:20%;"><?= t('key') ?></th>
                    <th style="width:25%;">🇨🇿 Čeština</th>
                    <th style="width:25%;">🇬🇧 English</th>
                    <th style="width:25%;">🇺🇦 Українська</th>
                    <th style="width:5%;" class="text-center"><?= t('edit_button') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($translations as $t): ?>
                <tr>
                    <td>
                        <code style="color:var(--accent);font-size:.85rem;">
                            <?= h($t['key']) ?>
                        </code>
                    </td>
                    <td style="word-break:break-word;">
                        <small><?= h(mb_substr($t['cs'], 0, 80)) ?><?= mb_strlen($t['cs']) > 80 ? '…' : '' ?></small>
                    </td>
                    <td style="word-break:break-word;">
                        <small><?= h(mb_substr($t['en'], 0, 80)) ?><?= mb_strlen($t['en']) > 80 ? '…' : '' ?></small>
                    </td>
                    <td style="word-break:break-word;">
                        <small><?= h(mb_substr($t['uk'], 0, 80)) ?><?= mb_strlen($t['uk']) > 80 ? '…' : '' ?></small>
                    </td>
                    <td class="text-center">
                        <button class="btn btn-sm" style="background:none;border:1px solid var(--border);color:var(--accent);padding:.2rem .4rem;font-size:.75rem;border-radius:4px;cursor:pointer;" 
                                data-key="<?= h($t['key']) ?>" 
                                data-cs="<?= h($t['cs']) ?>"
                                data-en="<?= h($t['en']) ?>"
                                data-uk="<?= h($t['uk']) ?>"
                                onclick="editTranslation(this)">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
    </div>
    <div class="tbl-footer"><span><?= count($translations) ?> <?= t('translations_count') ?></span></div>
    <?php endif; ?>
</div>

<!-- Modal pro editaci všech jazyků -->
<div class="modal fade" id="editModal" tabindex="-1" style="--bs-modal-bg: var(--surface);--bs-modal-border-color: var(--border);">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:var(--surface);border:1px solid var(--border);">
            <form method="post">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="_csrf" value="<?= h(generateCsrfToken()) ?>">
                <div class="modal-header" style="border-bottom:1px solid rgba(255,255,255,.05);">
                    <h5 class="modal-title"><?= t('edit_translation_title') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:invert(1);"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label-dvd"><?= t('key_readonly') ?></label>
                        <input type="text" name="key" class="form-control" readonly style="background:rgba(255,255,255,.02);font-family:var(--mono);">
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label-dvd">🇨🇿 Čeština</label>
                            <textarea name="cs" class="form-control" rows="8" style="font-family:var(--mono);font-size:.85rem;"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-dvd">🇬🇧 English</label>
                            <textarea name="en" class="form-control" rows="8" style="font-family:var(--mono);font-size:.85rem;"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-dvd">🇺🇦 Українська</label>
                            <textarea name="uk" class="form-control" rows="8" style="font-family:var(--mono);font-size:.85rem;"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top:1px solid rgba(255,255,255,.05);">
                    <button type="button" class="btn" style="background:rgba(255,255,255,.05);border:1px solid var(--border);color:var(--text);padding:.4rem .8rem;border-radius:6px;cursor:pointer;" data-bs-dismiss="modal"><?= t('cancel') ?></button>
                    <button type="submit" class="btn-dvd"><?= t('save_change') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editTranslation(btn) {
    const key = btn.dataset.key;
    const cs = btn.dataset.cs;
    const en = btn.dataset.en;
    const uk = btn.dataset.uk;
    
    document.querySelector('#editModal input[name="key"]').value = key;
    document.querySelector('#editModal textarea[name="cs"]').value = cs;
    document.querySelector('#editModal textarea[name="en"]').value = en;
    document.querySelector('#editModal textarea[name="uk"]').value = uk;
    
    const modal = new bootstrap.Modal(document.getElementById('editModal'));
    modal.show();
}
</script>

<?php require APP_ROOT . '/includes/layout_end.php'; ?>

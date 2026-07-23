<?php
// One-time migration: move original users.username to users.skutecneJmeno
// and rewrite users.username to normalized jmeno.prijmeni format.

require_once dirname(__DIR__) . '/includes/config.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from CLI only.\n");
    exit(1);
}

$apply = in_array('--apply', $argv, true);
$dryRun = !$apply;

function normalizeToken(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false && $ascii !== '') {
        $value = $ascii;
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    return $value;
}

function baseUsernameFromOriginal(string $original, int $id): string {
    $src = trim($original);
    $src = str_replace(['_', '-', '.', ';', ','], ' ', $src);
    $src = preg_replace('/\s+/u', ' ', $src) ?? $src;

    $parts = array_values(array_filter(explode(' ', $src), static fn(string $p): bool => $p !== ''));

    $first = '';
    $last = '';
    if (count($parts) >= 2) {
        $first = normalizeToken($parts[0]);
        $last = normalizeToken($parts[count($parts) - 1]);
    } elseif (count($parts) === 1) {
        $first = normalizeToken($parts[0]);
    }

    if ($first === '' && $last === '') {
        return 'user' . $id;
    }
    if ($last === '') {
        return $first;
    }

    return $first . '.' . $last;
}

$rows = db_all('SELECT id, username, skutecneJmeno FROM users ORDER BY id');
if (!$rows) {
    echo "No users found.\n";
    exit(0);
}

$planned = [];
$used = [];

foreach ($rows as $row) {
    $id = (int)($row['id'] ?? 0);
    $oldUsername = trim((string)($row['username'] ?? ''));
    if ($id <= 0 || $oldUsername === '') {
        continue;
    }

    $base = baseUsernameFromOriginal($oldUsername, $id);
    if ($base === '') {
        $base = 'user' . $id;
    }

    $candidate = $base;
    $suffix = 2;
    while (isset($used[$candidate])) {
        $candidate = $base . '.' . $suffix;
        $suffix++;
    }

    $used[$candidate] = true;
    $planned[] = [
        'id' => $id,
        'old_username' => $oldUsername,
        'new_username' => $candidate,
    ];
}

if ($planned === []) {
    echo "Nothing to migrate.\n";
    exit(0);
}

echo ($dryRun ? "DRY RUN\n" : "APPLY\n");
echo 'Users to process: ' . count($planned) . "\n";

foreach (array_slice($planned, 0, 12) as $item) {
    echo '#'.$item['id'].' '.$item['old_username'].' -> '.$item['new_username']."\n";
}
if (count($planned) > 12) {
    echo '... plus '.(count($planned) - 12)." more\n";
}

if ($dryRun) {
    echo "\nUse --apply to execute updates.\n";
    exit(0);
}

$db = db();
$db->beginTransaction();
try {
    $phase1 = $db->prepare('UPDATE users SET skutecneJmeno = ?, username = ? WHERE id = ?');
    $phase2 = $db->prepare('UPDATE users SET username = ? WHERE id = ?');

    // Phase 1: move old username to skutecneJmeno and assign a temporary unique username.
    foreach ($planned as $item) {
        $phase1->execute([$item['old_username'], '__mig__' . (int)$item['id'], (int)$item['id']]);
    }

    // Phase 2: assign the final normalized username.
    foreach ($planned as $item) {
        $phase2->execute([$item['new_username'], (int)$item['id']]);
    }

    $db->commit();
    echo "Migration completed successfully.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Migration failed: '.$e->getMessage()."\n");
    exit(1);
}

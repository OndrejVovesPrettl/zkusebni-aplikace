#!/usr/bin/env php
<?php
/**
 * scripts/setup_languages.php
 * Inicializuje jazykový systém v DB
 * 
 * Použití: php scripts/setup_languages.php
 */

require_once dirname(__DIR__) . '/includes/config.php';

echo "🔄 Inicializuji jazykový systém...\n\n";

try {
    // 1. Vymazat starší data
    echo "1. Odstraňuji stávající překlady...\n";
    db()->exec('DELETE FROM translations');
    db()->exec('DELETE FROM languages');
    db()->exec('ALTER TABLE languages AUTO_INCREMENT = 1');
    echo "   ✓ Tabulky vymazány\n\n";

    // 2. Vložit jazyky
    echo "2. Vkládám jazyky...\n";
    $langs = [
        ['cs', 'Čeština'],
        ['uk', 'Українська'],
        ['en', 'English'],
    ];
    $stmt = db()->prepare('INSERT INTO languages (code, name, active) VALUES (?,?,1)');
    foreach ($langs as [$code, $name]) {
        $stmt->execute([$code, $name]);
        echo "   ✓ $code ($name)\n";
    }
    echo "\n";

    // 3. Vložit překlady
    echo "3. Vkládám překlady...\n";
    $langDir = dirname(__DIR__) . '/lang';
    $count = 0;
    $totalByLang = [];

    foreach (glob($langDir . '/*.php') as $file) {
        $code = basename($file, '.php');
        $translations = require $file;
        
        if (!is_array($translations)) {
            echo "   ⚠ Varování: $file není pole\n";
            continue;
        }
        
        $langCount = 0;
        $stmt = db()->prepare('INSERT INTO translations (lang_code, `key`, `value`) VALUES (?,?,?)');
        foreach ($translations as $key => $value) {
            try {
                $stmt->execute([$code, $key, $value]);
                $langCount++;
                $count++;
            } catch (Exception $e) {
                echo "   ✗ Chyba při vkládání $code/$key: " . $e->getMessage() . "\n";
            }
        }
        $totalByLang[$code] = $langCount;
        echo "   ✓ $code (" . $langCount . " překladů)\n";
    }
    echo "\n";

    // 4. Ověření
    echo "4. Ověřuji správnost dat...\n";
    $langCount = db_val('SELECT COUNT(*) FROM languages');
    $transCount = db_val('SELECT COUNT(*) FROM translations');
    echo "   ✓ Jazyků: $langCount\n";
    echo "   ✓ Překladů: $transCount\n\n";

    // Shrnutí
    echo "✅ Inicializace hotova!\n";
    echo "───────────────────────────────────────\n";
    foreach ($totalByLang as $lang => $cnt) {
        echo "  $lang: $cnt překladů\n";
    }
    echo "───────────────────────────────────────\n";
    echo "Celkem: $transCount překladů vloženo\n\n";

    echo "💡 Tip: Jazyky můžete editovat na:\n";
    echo "   " . APP_URL . "/admin/translations.php\n\n";

} catch (Exception $e) {
    echo "❌ Chyba: " . $e->getMessage() . "\n";
    die(1);
}
?>

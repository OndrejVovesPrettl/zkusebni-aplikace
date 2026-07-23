#!/usr/bin/env php
<?php
/**
 * scripts/generate_lang_sql.php
 * Konvertuje PHP jazykové soubory do SQL INSERT statements
 * 
 * Použití: php scripts/generate_lang_sql.php > translations_seed.sql
 */

$langDir = dirname(__DIR__) . '/lang';
$langFiles = glob($langDir . '/*.php');

if (empty($langFiles)) {
    die("Chyba: Žádné jazykové soubory v $langDir\n");
}

echo "-- ============================================================\n";
echo "-- Překlady - Auto-generated z PHP souborů\n";
echo "-- Generováno: " . date('Y-m-d H:i:s') . "\n";
echo "-- ============================================================\n\n";

echo "DELETE FROM translations;\n";
echo "DELETE FROM languages;\n\n";

// Vložit jazyky
echo "-- Vložit jazyky\n";
echo "INSERT IGNORE INTO languages (code, name, active) VALUES\n";

$languages = [];
foreach ($langFiles as $file) {
    $baseName = basename($file, '.php');
    $languages[] = $baseName;
}

$langMap = [
    'cs' => 'Čeština',
    'uk' => 'Українська',
    'en' => 'English',
];

$langInserts = [];
foreach ($languages as $code) {
    $name = $langMap[$code] ?? ucfirst($code);
    $langInserts[] = "('$code', '$name', 1)";
}

echo implode(",\n", $langInserts) . ";\n\n";

// Načíst všechny překlady
$allTranslations = [];

foreach ($langFiles as $file) {
    $langCode = basename($file, '.php');
    $translations = require $file;
    
    if (!is_array($translations)) {
        echo "-- VAROVÁNÍ: $file není pole\n";
        continue;
    }
    
    foreach ($translations as $key => $value) {
        if (!isset($allTranslations[$key])) {
            $allTranslations[$key] = [];
        }
        $allTranslations[$key][$langCode] = $value;
    }
}

// Vygenerovat INSERT statements
$insertCount = 0;
$inserts = [];

foreach ($allTranslations as $key => $langValues) {
    foreach ($langValues as $langCode => $value) {
        // Escape SQL
        $key_esc = addslashes($key);
        $value_esc = addslashes($value);
        
        $inserts[] = "('$langCode', '$key_esc', '$value_esc')";
        $insertCount++;
    }
}

// Rozpsat po 50 řádcích
echo "-- Vložit překlady\n";
echo "INSERT INTO translations (lang_code, `key`, `value`) VALUES\n";
$chunks = array_chunk($inserts, 50);
foreach ($chunks as $i => $chunk) {
    if ($i > 0) {
        echo "INSERT INTO translations (lang_code, `key`, `value`) VALUES\n";
    }
    echo implode(",\n", $chunk);
    echo ";\n";
    if ($i < count($chunks) - 1) echo "\n";
}

echo "\n-- Vloženo $insertCount překladů\n";
echo "-- Jazyky: " . implode(', ', $languages) . "\n";
?>

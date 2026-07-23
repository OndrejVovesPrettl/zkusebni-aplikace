<?php
// includes/lang_loader.php
// Načítání překladů z DB s fallbackem na PHP soubory

class LanguageLoader {
    private static $cache = [];
    private static $useDb = false;
    private static $currentLang = 'cs';
    private static $phpFallbackCache = [];
    
    public static function init(): void {
        self::$currentLang = $_SESSION['lang'] ?? 'cs';
        
        try {
            self::loadFromDb(self::$currentLang);
            self::$useDb = true;
        } catch (Exception $e) {
            // Fallback na PHP soubory
            error_log("Lang DB load failed for " . self::$currentLang . ", fallback to PHP files: " . $e->getMessage());
            self::$useDb = false;
            self::loadFromPhp(self::$currentLang);
        }
    }
    
    private static function loadFromDb(string $lang): void {
        try {
            $sql = "SELECT `key`, `value` FROM translations WHERE lang_code = ? ORDER BY `key`";
            $stmt = db()->prepare($sql);
            $stmt->execute([$lang]);
            
            $count = 0;
            while ($row = $stmt->fetch()) {
                self::$cache[$row['key']] = $row['value'];
                $count++;
            }
            
            if ($count === 0) {
                throw new Exception("No translations found for language: $lang");
            }
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    private static function loadFromPhp(string $lang): void {
        $file = APP_ROOT . "/lang/$lang.php";
        
        if (!file_exists($file)) {
            $file = APP_ROOT . "/lang/cs.php";
        }
        
        if (file_exists($file)) {
            self::$cache = require $file;
        } else {
            error_log("Warning: No language file found for $lang");
        }
    }
    
    public static function get(string $key): string {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        // Fallback na PHP soubory i v DB rezimu (kdyz v DB chybi novy klic).
        $lang = self::$currentLang;
        if (!isset(self::$phpFallbackCache[$lang])) {
            $file = APP_ROOT . "/lang/{$lang}.php";
            if (!file_exists($file)) {
                $file = APP_ROOT . '/lang/cs.php';
            }
            self::$phpFallbackCache[$lang] = file_exists($file) ? (require $file) : [];
        }

        $phpTranslations = self::$phpFallbackCache[$lang];
        if (isset($phpTranslations[$key])) {
            return $phpTranslations[$key];
        }

        // Final fallback – vrátit klíč
        return $key;
    }
    
    public static function reload(string $lang): void {
        self::$cache = [];
        self::$phpFallbackCache = [];
        self::$currentLang = $lang;
        $_SESSION['lang'] = $lang;
        self::init();
    }
    
    public static function getCurrentLang(): string {
        return self::$currentLang;
    }
    
    public static function isUsingDb(): bool {
        return self::$useDb;
    }
}
?>

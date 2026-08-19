# 🌍 Nový Jazykový Systém – Pokyny

## Stav dokumentace (v5.3.3)

- Dokumentace odpovídá stavu release `v5.0.7`.
- Pro sjednocený report `Týdenní výroba` používej klíč `kt_report_all_title`.
- V češtině je hodnota `Týdenní výroba`, v angličtině `Weekly production`, v ukrajinštině `Тижневе виробництво`.
- Pole pro kalendářní týden používá klíč `cw_label` (zobrazení `KT`).
- Nové deploy UI v `admin/scripts.php` používá klíče `release_deploy_*` (výběr release, spuštění deploye, výsledky a chyby).
- Mail workflow používá klíče `scripts_send_test_mail_*`, `scripts_send_test_mail_outlook_*`, `scripts_target_email_*`.
- Pro volitelný e-mail nového uživatele používej klíče `email_optional`, `invalid_email`, `initial_password_mail_hint`.
- Manuálové klíče jsou rozděleny do skupin: `manual_ui_*` (UI labely), `manual_quick_step_*` (rychlý start), `manual_fallback_*` (generické návody), `manual_guide_{modul}_*` (per-modul průvodce). Klíče se načítají genericky přes prefix — pro přidání kroku stačí přidat nový číslovaný klíč (např. `manual_guide_index_step_6`).
- `LanguageLoader` od v5.2.4 automaticky fallbackuje na PHP soubory i v DB režimu. Nové klíče v `lang/*.php` proto fungují okamžitě bez nutnosti synchronizace do DB.

## Nové klíče pro deploy workflow

Všechny jazykové soubory (`lang/cs.php`, `lang/en.php`, `lang/uk.php`) musí obsahovat:

- `release_deploy_title`
- `release_deploy_topbar`
- `release_deploy_intro`
- `release_deploy_select_release`
- `release_deploy_release_label`
- `release_deploy_run`
- `release_deploy_no_releases`
- `release_deploy_result_ok`
- `release_deploy_result_err`
- `release_deploy_success`
- `release_deploy_git_not_found`
- `release_deploy_invalid_tag`
- `release_deploy_tag_not_found`
- `release_deploy_prod_missing`
- `release_deploy_config_missing`
- `release_deploy_config_read_failed`
- `release_deploy_backup_failed`
- `release_deploy_app_url_not_found`
- `release_deploy_config_write_failed`
- `release_deploy_checkout_failed`

## ✨ Co se změnilo

Jazyky se nyní **natahují z databáze** místo PHP souborů. To umožňuje:
- ✅ Editovat překlady přes webové rozhraní (bez přístupu na server)
- ✅ Verzování překladů v audit logu
- ✅ Snadnější přidání nových jazyků
- ✅ Fallback na PHP soubory, pokud DB není dostupná

---

## 🚀 Instalační Kroky

### 1. Aktualizace Databáze

Spusť SQL script pro inicializaci tabulek `languages` a `translations`:

```bash
mysql -u dvd_user -p dvd_vyroba < schema.sql
```

Tabulky budou vytvořené s základními údaji pro **cs**, **uk**, **en**.

### 2. Inicializace Jazyků (Volitelné)

Pokud chceš importovat **všechny** překlady z PHP souborů do DB:

```bash
cd /var/www/html/dvd  # nebo tvá cesta
php scripts/setup_languages.php
```

Output:
```
🔄 Inicializuji jazykový systém...

1. Odstraňuji stávající překlady...
   ✓ Tabulky vymazány

2. Vkládám jazyky...
   ✓ cs (Čeština)
   ✓ uk (Українська)
   ✓ en (English)

3. Vkládám překlady...
   ✓ cs (dle aktuálního stavu)
   ✓ uk (dle aktuálního stavu)
   ✓ en (dle aktuálního stavu)

✅ Inicializace hotova!
───────────────────────────────────────
   cs: počet dle souboru
   uk: počet dle souboru
   en: počet dle souboru
───────────────────────────────────────
Celkem: součet všech klíčů ve 3 jazycích

💡 Tip: Jazyky můžete editovat na:
   http://localhost/dvd/admin/translations.php
```

### 3. Ověřit Přihlášení

Přihlaš se do adminu:
- **URL:** `http://localhost/dvd/admin/translations.php`
- **Uživatel:** admin
- **Heslo:** admin123

---

## 📋 Správa Překladů

### Web UI (`admin/translations.php`)

1. Vyber jazyk ze selectu
2. Vyhledej překlad pomocí klíče nebo textu
3. Klikni na "Editovat"
4. Uprav text
5. Klikni "Uložit změnu"

**Změny se ihned projeví** – loader automaticky reloaduje cache.

### Příkazy v Terminálu

#### Generovat SQL z PHP souborů

```bash
php scripts/generate_lang_sql.php > migrations/translations_seed.sql
```

Výstup obsahuje všechny INSERT statements, které můžeš importovat:
```bash
mysql -u dvd_user -p dvd_vyroba < migrations/translations_seed.sql
```

---

## 🔧 Technické Detaily

### Nové Třídy

#### `LanguageLoader` (includes/lang_loader.php)

```php
LanguageLoader::init()              // Inicializuje loader (automaticky)
LanguageLoader::get($key)            // Vrátí překlad
LanguageLoader::reload($lang)        // Reloaduje cache na novo
LanguageLoader::getCurrentLang()     // Vrátí aktuální jazyk
LanguageLoader::isUsingDb()          // Vrátí true = DB, false = PHP fallback
```

### Funkce `t()` (config.php)

```php
function t(string $key): string {
    return LanguageLoader::get($key);
}
```

**Fallback řetězec:**
1. Zkusit DB (tabulka `translations`)
2. Pokud DB selhá → PHP soubory (`lang/{lang}.php`)
3. Pokud nic → vrátit klíč

---

## 🗄️ Datový Model

### Tabulka: `languages`

```sql
id      | code | name         | active
--------|------|--------------|--------
1       | cs   | Čeština      | 1
2       | uk   | Українська   | 1
3       | en   | English      | 1
```

### Tabulka: `translations`

```sql
id   | lang_code | key         | value                          | created_at | updated_at
-----|-----------|-------------|--------------------------------|------------|----------
1    | cs        | app_name    | DVD Sledování výroby           | ...        | ...
2    | en        | app_name    | DVD Production Tracking        | ...        | ...
3    | uk        | app_name    | DVD Відстеження виробництва    | ...        | ...
...
```

---

## 📊 Audit Trail

Všechny změny překladů se logují v tabulce `audit_log`:

```
čas         | uživatel | akce              | popis
------------|----------|-------------------|---------------------------
14:30:45    | admin    | TRANSLATION_UPDATE| Překlad aktualizován: cs/app_name
```

Viz: **http://localhost/dvd/admin/audit_log.php**

---

## ⚠️ Troubleshooting

### "Chyba: No translations found for language: cs"

**Příčina:** Tabulka `translations` je prázdná

**Řešení:**
```bash
php scripts/setup_languages.php
```

### Překlady se neaktualizují

**Příčina:** Cache není nahrána, PHP fallback běží

**Řešení:**
1. Zkontroluj, zda máš přístup k DB
2. Podívej se na error log: `php error_log` (server logs)
3. Ujisti se, že `schema.sql` byl spuštěn

### Chybí mi některé překlady

**Příčina:** Nové klíče byly přidány do PHP souborů, ale ne do DB

**Řešení:**
```bash
php scripts/setup_languages.php  # Reimport všech
```

---

## 🎯 Best Practices

✅ **DĚLEJ:**
- Edituj překlady přes web UI (`admin/translations.php`)
- Když přidáš novou funkci s `t('novy_klic')` → přidej jej do všech 3 jazyků v PHP
- Spouštěj `setup_languages.php` po přidání nových klíčů
- Pokud přidáváš nové klíče do projektu, promítni je i do `schema.sql` (seed translations)

❌ **NEDĚLEJ:**
- Neupravuj tabulku `translations` ručně v phpMyAdmin (nebez důvodu)
- Nespoléhej jen na fallback – udržuj DB v souladu s PHP soubory
- Nemažby řádky z `translations` bez backupu

---

## 📞 Otázky?

Viz:
- [schema.sql](../schema.sql) – DB struktura
- [includes/lang_loader.php](../includes/lang_loader.php) – Loader implementace
- [includes/config.php](../includes/config.php) – Inicializace
- [admin/translations.php](../admin/translations.php) – Web UI
- [scripts/](../scripts/) – Utility skripty

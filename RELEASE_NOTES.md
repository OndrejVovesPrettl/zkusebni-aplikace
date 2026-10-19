# Release Notes

## v5.6.6 (2026-10-06)

### NOK matice
- Modal umožňuje vyhledat a vybrat až pět pracovišť; zobrazí se jen odpovídající sloupce matice.
- Uložení zachovává nastavení nevybraných pracovišť a ověřuje platnost i maximální počet vybraných AFO.
- Popisová část tabulky je kompaktnější a AFO sloupce využívají dostupnou šířku.
- Opraveno hledání pracovišť; modal si při filtrování zachovává výšku.
- Tlačítko Přestavět z Excelu je skryté, jeho serverová funkce zůstává zachována.

## v5.6.5 (2026-10-06)

### Formulář a tisk pracoviště
- Při změně nebo zrušení výběru pracoviště se vymažou navázaná data a skryté ID.
- Příprava tisku ověřuje platné ID pracoviště; bez platného výběru nevloží do tiskopisu staré identifikační údaje.
- NOK položky bez odpovídající AFO mapy se nezobrazí.

### Přehledy
- Filtry přehledu záznamů se obnoví po návratu z detailu, editace nebo mazání; tlačítko Reset uložené filtry smaže.
- Detail pracovníka používá responzivnější rozložení a zalamuje dlouhé údaje pracoviště.

## v5.6.4 (2026-10-05)

### Pracovníci a import
- Přidáno datum ukončení pracovního poměru, filtr **Vše / Ukončeno / Aktivní** a stavový badge bez zobrazení data.
- Výběr pracovníka v hlavním formuláři zobrazuje pouze ručně aktivní pracovníky s datem ukončení v budoucnosti.
- CSV import pracovníků podporuje čtvrtý sloupec `ukonceni`; při duplicitním `interni_id` se zobrazí modal s kandidáty a výběrem položky.
- Duplicitní skupiny, kde mají všichni kandidáti datum ukončení před dneškem, se automaticky přeskočí.
- Přidána nezávislá filtrace směnnosti a třídy a řazení sloupců cyklem ASC / DESC / vypnuto.
- Automatická i ruční synchronizace ukládají datum ukončení a zachovávají importovanou třídu.

## v5.6.3 (2026-10-01)

### Role-based print and manual updates
- Traceability Lineleader (`writer`) can use the main form in read-only mode to select a production type/workplace and print an A4 form; saving production data remains unavailable.
- The role simulator now returns through POST/Redirect/GET after switching roles, and the menu follows the selected role's permissions.
- Fixed an OEE Top NOK query failure caused by incompatible MySQL collations.
- Updated the in-app and Markdown manuals to match current roles, print behavior, OEE/NOK calculations, user administration and simulator behavior.

## v5.6.2 (2026-09-30)

### FSK print a simulátor rolí
- Přidána výhradní role `fsk_print` s read-only tiskovým režimem hlavního formuláře.
- FSK může vybrat pracoviště a výrobní okruh, vytisknout vyplněnou i prázdnou A4 šablonu a změnit své iniciační heslo.
- FSK má vlastní omezené sidebar menu s odkazem Nový zápis, profilem, jazykem, tématem, kompaktním režimem a odhlášením.
- Přidán simulátor rolí dostupný pouze skutečnému superadminovi; používá stejné navigační permission podmínky jako reálná role.
- Během simulace zůstává dostupný návrat do `scripts.php`, zatímco skripty, release a deploy akce jsou serverově blokované.
- FSK přístup k ostatním routám je chráněn centrálním route guardem; povolen je pouze index, profil, logout a řízení simulace.

## v5.6.0 (2026-09-30)

### Per-workplace NOK classification
- NOK exclusion from operator scrap/OEE is configured for each workplace (AFO) and NOK code in the NOK matrix.
- The selected workplace default is loaded into both the new-entry and edit forms; each record can override it per NOK code.
- Physical NOK remains in total production figures. Operator NOK is stored separately and drives operator scrap and quality Q/OEE.
- Worker reports, monthly results, record lists/details and the OEE Top NOK widget show the two counts separately.
- NOK matrix Excel rebuild/import preserves per-AFO operator defaults. New cells default to counting NOK against the operator.
- Existing records without an explicit operator value retain the legacy behavior and count all NOK against the operator.

## v5.5.0 (2026-09-29)

### Výsledovka a OEE
- Přidán měsíční přehled výsledovky operátorů s filtrováním, řazením a exportem do CSV.
- OEE přehledy používají sjednocenou váženou logiku; výkon je omezen na 150 % a celkové OEE na 100 %.
- Souhrnné statistiky započítávají sdílené výrobní záznamy pouze jednou.
- Detail výrobního záznamu zobrazuje skutečné číselné ID pracoviště.

### Checklist seřizovače
- Přidán denní checklist seřizovače, editor záznamů a správa katalogu úkonů podle projektu.
- Katalog lze naplnit z Excelové předlohy; kontrolní položky podporují rozdílná pravidla pro projekty Gen3 a 48W.

### Formulář a správa
- Synchronizovány změny formuláře, editace výrobních záznamů, správy pracovišť a importních pomocných funkcí.
- Runtime inicializace rozšiřuje databázový enum typů o `vstrikovna` a přiřadí tento typ určeným pracovištím i jejich výrobním záznamům.
- Zachována lokální URL testovací instalace `v3.4`; produkční URL se tímto releasem nemění.

## v5.4.0 (2026-08-31)

### Stabilní release a finalizace verzování
- Byl aktualizován APP_VERSION na `5.4.0` a synchronizovány všechny MD dokumenty pro nový GitHub release.
- `index.php`: pole `Vyrobeno celkem (ks)` nyní akceptuje `0` a při nulové výrobě se zobrazí potvrzovací hláška před uložením záznamu.
- Releasová dokumentace je připravena pro vytvoření GItHub release tagu `v5.4.0`.

## v5.3.4 (2026-08-13)

### Synchronizace kodu v3.4 na stav v5.3
- Do `v3.4` byly promitnuty funkcni zmeny z `v5.3` pro 6 hlavnic souboru:
   - `index.php`
   - `includes/config.php`
   - `admin/nok_kody.php`
   - `admin/prestavky.php`
   - `admin/zaznamy_pracovnici.php`
   - `admin/zaznamy_pracovnici_detail.php`

### NOK editor a ciselniky
- `admin/nok_kody.php` obsahuje finalni workflow filtrace, pridani/odebrani kodu a aktualizace prekladovych popisu.
- `rebuild_from_excel` zustava omezen na roli `superadmin` i na backendu.

### Prestavky a vypocty
- `admin/prestavky.php` a runtime logika v `includes/config.php` jsou sjednoceny na stejne datove a seed chovani jako v `v5.3`.
- `index.php` odpovida aktualnimu produkcnimu flow formulare ve vetvi `v5.3`.

### Reporty pracovniku
- `admin/zaznamy_pracovnici.php` a `admin/zaznamy_pracovnici_detail.php` pouzivaji stejnou OEE/downtime agregaci jako `v5.3`.

## v5.3.3 (2026-08-10)

### Dynamicke prestavky v hlavnim formulari
- `index.php` sekce 02 (`Pauza`) byla prepracovana na dynamicky vypocet dle casoveho prekryvu.
- Logika bere data z ciselniku `prestavky` a filtruje je podle:
   - typu vyroby (`Série` / `Filtry`),
   - rezimu smeny (`8h` / `12h`),
   - konkretniho kodu smeny (`R8/O8/N8/R12/N12`).
- Operativni zapisy na vice pracovistich v ramci smeny nyni dostavaji jen ty prestavky, na ktere casove vznikl narok.
- Pri neexistujicim prekryvu je hodnota `Pauza` automaticky `0`.

## v5.3.2 (2026-08-10)

### NOK editor (Sprava -> DVD)
- `admin/nok_kody.php`: doplnena filtrace seznamu NOK (ID/KOD/Popis, aktivni/vcetne neaktivnich).
- Pridana moznost pridat novy NOK kod a odebrat kod z aktivnich zaznamu primo v editoru.
- Pri pridani kodu se aktualizuje i prekladovy klic `nok_XXX_popis`.

### Role-based viditelnost Excel rebuildu
- Tlacitko `Prestavet z Excelu` a cely jeho panel jsou viditelne jen pro roli `superadmin`.
- Backend akce `rebuild_from_excel` ma navic explicitni autorizačni kontrolu a vraci `403` bez opravneni.

### Import pracovniku
- `admin/pracovnici.php`: oba importni toky (`sync_replace_workers`, `csv_import`) fungují v rezimu full replace.
- Import pred vlozenim smaze tabulku `pracovnici` a nahraje obsah zdrojoveho souboru.
- Pridana deduplikace podle `interni_id`, aby import nespadl na unikátním indexu pri duplicitach ve vstupu.

### Ciselnik prestavek a vypocet pauz
- Ciselnik prestavek byl sjednocen na cislovane polozky `Pauza 1..N` podle aktualni importni sablony.
- `index.php`: vyhodnoceni pauzy nyni zapocitava vsechny polozky z ciselniku bez vyjimek.

## v5.3.1 (2026-08-05)

### Číselník směn (Správa -> DVD)
- **Nová stránka `admin/smeny.php`**: Přidána kompletní správa směn (vytvoření, editace, smazání, aktivace/deaktivace).
- **DB-first přístup**: Výběr směn je přesunut z pevného seznamu do tabulky `smeny`.
- **Automatická inicializace**: `includes/config.php` při prvním běhu zajistí vytvoření tabulky a vložení výchozích směn.

### Integrace do formuláře a navigace
- **`index.php`**: Pole Směna používá dynamický seznam z DB, včetně výchozích časů a parametrů směny.
- **`includes/layout.php`**: Do sekce Správa -> DVD přidána položka menu **Směny**.

### Manuál a i18n
- **`admin/manual.php` + `MANUAL.md`**: Doplněn popis číselníku směn a rozšířené kroky pro Traceability Terminál (ResetPalety).
- **Jazykové soubory**: Doplněn chybějící český klíč `manual_quick_step_7`; klíče `cs` a `uk` jsou nyní v parity.

## v5.3 (2026-07-20)

### Vylepšený výběr Pracovišť a Pracovníků
- **Interaktivní dropdown místo SELECT**: Pracoviště a Pracovník nyní používají vlastní dropdown s fulltextovým vyhledáváním.
- **Filtrování dle typu**: Pracovní místa se automaticky filtrují při přepínání mezi "SÉRIE" a "FILTRY".
- **Vyhledávání podle ID**: Zadáním čísla (např. "50") se najde pracoviště s `[50]` v hranatých závorkách.
- **Tmavý a světlý režim**: Dropdown plně podporuje obě témata přes CSS proměnné.
- **Klávesnicová navigace**: Šipky nahoru/dolů pro pohyb, Enter pro výběr, Escape pro zavření menu.

### Oprava Výpočtu OEE
- **Matematicky správná agregace**: OEE se nyní počítá ze součtu komponent, ne z průměru jednotlivých záznamů.
- **Vzorec**: OEE = (čistý_čas / pracovní_doba) × (vyrobeno / norma) × (dobré / vyrobeno) × 100%
- **Aplikováno v modulech**:
  - Celková statistika OEE
  - OEE dle pracovišť
  - OEE dle pracovníků/operátorů
  - Denní a týdenní přehledy

### Refaktorace NOK Systému
- **Zjednodušení architektury**: Odebrána tabulka `nok_afo_alias` (zbytečné mezikroky mapování).
- **Nové schéma**: Přejmenována tabulka `nok_kody_afo` → `nok_matrix_afo` pro jasnost.
- **Automatická migrace**: Runtime migrace staré schématu na nové (bez nutnosti ručního SQL).
- **Odstranění skupin**: NOK kódy již nejsou seskupovány do "skupin" (skupina column), zobrazují se plochý seznam.
- **AFO-based filtrování**: Při editaci záznamu se NOK kódy automaticky filtrují podle zvoleného AFO (v `admin/zapis_edit.php`).

---

## v5.2.4 (2026-07-07)

### Nový uživatelský manuál (`admin/manual.php`)
- Nová stránka dostupná z postranního menu (stejná záložka, bez nového okna).
- Zobrazuje pouze sekce odpovídající roli přihlášeného uživatele (DVD, Traceability, Opravy, Naladčci, Správa, Účet).
- Dva režimy detailu: **Stručně** (`?manual_mode=concise`) a **Podrobně** (`?manual_mode=detailed`).
- Sekce „Obsah manuálu" je při tisku skryta; opraveno přetékání obsahu v tiskovém layoutu.
- Všechny texty jsou v lang souborech (`lang/cs.php`, `lang/en.php`, `lang/uk.php`) – žádné hardcoded stringy v PHP.

### Správa uživatelů – čtečka karet (Operátor)
- Heslo zadané při vytvoření uživatele s rolí `read` (Operátor) se ukládá jako plain-text do:
  - `dvd_vyroba.users.traceability_card_code`
  - `traceability2.user.password`
- Přihlášení přes čtečku karet porovnává kód 1:1 (bez hashování).
- Při editaci uživatele se `traceability2.user.password` přepíše pouze pokud bylo zadáno nové heslo.
- Nový účet s rolí `read` má `password_changed_at = NOW()` → není vyžadována změna hesla při prvním přihlášení.

### LanguageLoader – PHP fallback
- Při chybějícím klíči v DB se automaticky sahá do PHP lang souborů (nové klíče fungují okamžitě bez nutnosti sync do DB).

### Bezpečnost
- `.env` přidán do `.gitignore` (zabránění neúmyslnému commitu credentials).

---

# Release Notes - Version 5.1.6

## Release Date
2024-01-XX (TBD)

---

## 🎉 What's New in v5.1.6

### Scripts Management System

A comprehensive scripts management interface has been added to the admin panel, providing powerful tools for database maintenance and system operations.

#### Features Added:

**1. Database Validation Scripts**
- `validate_all.sql` - Comprehensive validation of all database tables
- `validate_import_data.sql` - Validates imported data integrity
- `validate_pracovnici_data.sql` - Validates employee records
- `validate_zapisy_jmeno_format.sql` - Validates name format in records

**2. Data Fixing Scripts**
- `fix_zapisy_typ_by_template.sql` - Fixes template-based type assignments

**3. Cleanup Operations**
- `cleanup_import_data.sql` - Cleans up import-related data
- `cleanup_pracovnici_duplicates.sql` - Removes duplicate employee records

**4. System Maintenance Scripts**
- `setup_languages.php` - Configures application languages
- `backup_db.php` - Creates database backups
- `consolidate_pracovisite.php` - Consolidates work site data
- `send_test_mail.php` - Sends test emails (configurable recipient)
- `send_test_mail_outlook.php` - Outlook-compatible email testing

#### Key Capabilities:

| Feature | Description |
|---------|-------------|
| **One-Click Execution** | Run any script with a single click from the admin panel |
| **Email Integration** | Scripts can send test emails to configurable recipients |
| **SQL & PHP Support** | Execute both SQL queries and PHP scripts directly |
| **Error Reporting** | Detailed output display for debugging and verification |
| **CSRF Protection** | All operations protected against cross-site request forgery |

---

### Release Management System

A new Git-based release management system has been implemented, enabling version control and deployment automation.

#### Features:

**1. Tag Creation**
- Create semantic version tags (vX.Y.Z format)
- Automatic tag naming based on APP_VERSION constant
- Push tags to remote repository automatically

**2. Release Deployment**
- Select any previous release from dropdown list
- Automatic checkout of selected tag to production root
- Smart file synchronization using Git work-tree feature
- Backup creation before deployment

**3. Configuration Management**
- Automatic APP_URL update in production config
- Config backup with timestamped naming
- Safe replacement of configuration values

#### Deployment Workflow:

```
1. Create release tag → 2. Push to origin → 3. Select from dropdown → 
4. Deploy to production → 5. Verify deployment
```

---

### Technical Improvements

**Git Integration:**
- Automatic Git binary detection (Laragon paths + system PATH)
- Graceful fallback when Git is not available
- Tag validation with semantic versioning pattern matching

**Command Execution:**
- Secure command building with proper escaping
- Output capture and error handling
- Process timeout protection

**File Operations:**
- Safe file reading/writing with existence checks
- Backup creation before modifications
- Atomic operations where possible

---

## 📋 Migration Guide

### For Administrators:

1. **Access the Scripts Panel**
   - Navigate to Admin → Scripts (new menu item)
   - Review available scripts and their descriptions

2. **Create Your First Release Tag**
   ```bash
   # Default tag will be v5.1.6
   # Click "Run" button with default or custom version number
   ```

3. **Deploy to Production**
   - Select desired release from dropdown
   - Click "Deploy" to checkout and update production files
   - Verify APP_URL is correctly set in config.php

### For Developers:

1. **Adding New Scripts**
   Add script definitions to the `$scripts` array in `admin/scripts.php`:
   ```php
   'my_script' => [
       'title' => t('my_script_title'),
       'description' => t('my_script_desc'),
       'type' => 'sql',  // or 'php'
       'path' => APP_ROOT . '/scripts/my_operation.sql',
   ];
   ```

2. **Email Scripts**
   For scripts that need email functionality:
   ```php
   'my_mail_script' => [
       'title' => t('mail_title'),
       'description' => t('mail_desc'),
       'type' => 'php',
       'path' => APP_ROOT . '/scripts/my_mail.php',
       'accept_target_email' => true,  // Adds email input field
   ];
   ```

---

## 🔒 Security Considerations

- All POST operations require CSRF token validation
- SQL commands are properly escaped to prevent injection
- File paths are validated before execution
- Git operations run with restricted scope (work-tree mode)

---

## 🐛 Known Issues

- None at this time

---

## 📞 Support

For issues or questions regarding the scripts management system:
- Check existing documentation in `scripts/README.md`
- Review changelog for related fixes
- Contact support team

---

*Release v5.1.6 - Scripts Management & Release System*

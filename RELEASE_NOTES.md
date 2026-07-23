# Release Notes

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

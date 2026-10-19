# Skripty - Utility pro údržbu (v5.6.6)

## Jazykový Systém

Jazykovy workflow je popsany v `LANGUAGES.md` a technicky se opira o skripty:

- `scripts/setup_languages.php` - rebuild jazykovych tabulek `languages` a `translations`
- `scripts/generate_lang_sql.php` - generovani SQL seedu z `lang/*.php`

## RCH (Release Checklist Helper)

### `release_check.php`

Kontrola release pripravenosti a volitelne vytvoreni/push tagu.

```bash
php scripts/release_check.php 5.6.6
php scripts/release_check.php 5.6.6 --create-tag
php scripts/release_check.php 5.6.6 --create-tag --push
```

Co kontroluje:
1. Repo je git.
2. Aktualni vetev je `main`.
3. Working tree je clean.
4. Tag neexistuje lokalne.
5. Tag neexistuje na `origin` (pokud je dostupna sit/auth).
6. V souboru `changelog.md` existuje sekce `## vX.Y.Z` pro pripravovanou verzi.

Poznamka k verzovani:
- Pred kazdym release tagem nejdriv dopln `changelog.md`.
- RCH bez teto sekce tag nevytvori.

GitHub nastaveni (doporučeno):
1. Repository settings -> Branches -> Add branch protection rule.
2. Branch name pattern: `main`.
3. Zapnout: `Require a pull request before merging`.
4. Zapnout: `Block force pushes`.
5. Zapnout: `Require status checks to pass` (az budete mit CI).

GitHub release postup:
1. Vytvor tag pres `release_check.php`.
2. Pushni commit s release metadaty na `main`.
3. Vytvor GitHub release z tagu, napr. `gh release create v5.6.6 --repo Niobisoft/dvdsql --title "v5.6.6" --notes-file RELEASE_NOTES.md`.

Pred vydanim over, ze `origin` smeruje na `https://github.com/Niobisoft/dvdsql`, working tree je cisty a tag jeste neexistuje.

Poznamka k cistote repozitare:
- SQL dumpy v `backupSQL/` jsou lokalni provozni zalohy a od v5.0.10 se negituji.
- Docasne vystupy typu `output.txt` a `temp_log_tail.txt` se take negituji.

---

## Deploy release do produkce (dashboard)

Deploy workflow je integrovany v `admin/scripts.php` (superadmin-only).

Postup:
1. Otevrit `Sprava -> Skripty`.
2. V sekci Deploy release vybrat tag `vX.Y.Z`.
3. Kliknout na `Nasadit release`.

Co se stane:
- obsah vybraneho tagu se nasadi do produkcniho rootu `C:/laragon/www`,
- v `C:/laragon/www/includes/config.php` se prepise `APP_URL` na `http://LB100069/`,
- pred zmenou configu se vytvori backup `config.php.bak.Ymd_His`.

Poznamka:
- samostatna deploy stranka neni potreba; jedine misto pro deploy je `admin/scripts.php`.

---

## Automaticky import DVD

### `auto_import_dvd.php`

Spusti import z pevneho souboru:

`\\lbfile01.czlb-pms.prettl.pgn\pacz\Departments\DVD\DVD-2026.xlsx`

a vlozi zaznamy do tabulky `zapisy`.

Web:

```text
/scripts/auto_import_dvd.php
/scripts/auto_import_dvd.php?force=1
```

CLI:

```bash
php scripts/auto_import_dvd.php
php scripts/auto_import_dvd.php --force
```

Poznamky:
- Webove spusteni vyzaduje prihlaseni jako superadmin.
- Standardne je povolen jen 1 beh za den (lock soubor v uploads/tmp).
- Opakovany beh lze vynutit pres `force=1` nebo `--force`.
- Vystup je JSON se statistikou: `inserted`, `duplicates`, `parser_skipped`, `db_errors`.

---

## Synchronizace Traceability2

### `sync_traceability.php`

Prubezne synchronizuje databazi `traceability2` ze serveru `LB100021` do lokalni databaze stejneho nazvu.

Web:

```text
/scripts/sync_traceability.php
/scripts/sync_traceability.php?force=1
/scripts/sync_traceability.php?full=1
```

CLI:

```bash
php scripts/sync_traceability.php
php scripts/sync_traceability.php --force
php scripts/sync_traceability.php --full
```

Poznamky:
- Webove spusteni vyzaduje prihlaseni jako admin nebo superadmin.
- Synchronizace je prirustkova podle posledniho `id` ulozeneho v `uploads/tmp/sync_traceability_state.json`.
- Pri `--full` se synchronizace spusti znovu od `id=0`, ale `INSERT IGNORE` preskakuje existujici radky.
- Behem behu se vytvari lock soubor `uploads/tmp/sync_traceability.lock`.
- Pripojovaci udaje se ctou z `includes/config.local.php` nebo z promennych `TRACEABILITY_REMOTE_*`.

### `sync_traceability_users.php`

Synchronizuje uzivatele z tabulky `traceability2.user` do `dvd_vyroba.users`.

Mapovani role podle `traceability2.user.rights`:
- `100` -> `superadmin`
- `90` -> `writer`
- `1` -> `read`
- ostatni hodnoty -> `read`

Web:

```text
/scripts/sync_traceability_users.php
```

CLI:

```bash
php scripts/sync_traceability_users.php
php scripts/sync_traceability_users.php --dry-run
```

---

## Synchronizace DVD importu z LBFILE01

### `sync_dvd_import.php`

Prubezne importuje DVD data ze sdileneho souboru na `LBFILE01` do tabulky `zapisy`.

Web:

```text
/scripts/sync_dvd_import.php
/scripts/sync_dvd_import.php?force=1
```

CLI:

```bash
php scripts/sync_dvd_import.php
php scripts/sync_dvd_import.php --force
```

Poznamky:
- Webove spusteni vyzaduje prihlaseni jako admin nebo superadmin.
- Behem behu se vytvari lock soubor `uploads/tmp/sync_dvd_import.lock`.
- Stav a kontrolni metadata se ukladaji do `uploads/tmp/sync_dvd_state.json`.
- Pokud se nezmenil `mtime` zdrojoveho souboru, bez `--force` se import preskoci (`source_unchanged`).
- Typ `serie/filtry` se pri importu urcuje stejnou sablonou jako ve formulari `index.php`:
	- `filtry` pokud `projekt == "EMV Filter"` nebo text obsahuje `Ostatni`/`Srot` (`Šrot`), jinak `serie`.

### `sql/fix_zapisy_typ_by_template.sql`

Jednorazovy opravny skript pro historicka data v DB.

Co dela:
1. Prepocte `pracoviste.typ` podle stejne sablony jako `index.php`.
2. Prepocte `zapisy.typ` (s preferenci dat z navazaneho pracoviste, fallback na sloupce v `zapisy`).
3. Vypise kontrolni souhrn poctu podle typu.

Spusteni:

```bash
mysql -u root -p dvd_vyroba < scripts/sql/fix_zapisy_typ_by_template.sql
```

### `sync_dvd_import_scheduler.ps1`

Fallback scheduler pro Windows/Laragon prostredi.

Vlastnosti:
- Spousti `sync_dvd_import.php` **kazdou sudou hodinu** na cele hodine (`00:00, 02:00, 04:00, ...`).
- Loguje beh do `uploads/tmp/sync_dvd_import_cron.log`.
- Pouziva runtime lock `uploads/tmp/sync_dvd_import_cron.lock` proti soubeznemu spusteni.

---

## Synchronizace IKOS pracovniku

### `sync_ikos_workers.php`

Importuje pracovniky ze souboru:

`uploads/export_ikos.txt`

do tabulky `pracovnici`.

Pravidla:
- CSV pouziva poradi `interni_id;jmeno;ukonceni;smennost`.
- Pro kazde `interni_id` se importuje pouze radek s `ukonceni` v budoucnosti; historicke radky se preskoci.
- Pokud ma jedno ID historicky i aktivni radek, vybere se aktivni radek bez ohledu na poradi v souboru.
- Existujici role pracovnika zustava pri synchronizaci zachovana; nove pracovniky dostanou vychozi roli `D`.
- Pokud zdroj neobsahuje zadne aktualne zamestnane pracovniky, databaze se nezmeni.
- Pri nezmenenem `mtime` souboru se bez `force` import preskoci (`source_unchanged`).
- Po zmene synchronizacni logiky se soubor jednorazove zpracuje znovu podle `sync_version`, i kdyz se jeho `mtime` nezmenilo.
- U textu se resi konverze kodovani (UTF-8 / Windows-1250 / ISO-8859-2), aby jmena s diakritikou byla korektni.

Web:

```text
/scripts/sync_ikos_workers.php
/scripts/sync_ikos_workers.php?force=1
```

CLI:

```bash
php scripts/sync_ikos_workers.php
php scripts/sync_ikos_workers.php --force
```

### `sync_ikos_workers_scheduler.ps1`

Scheduler pro Windows/Laragon prostredi.

Vlastnosti:
- Spousti `sync_ikos_workers.php` kazdou hodinu cca v `HH:01`.
- Loguje beh do `uploads/tmp/sync_ikos_workers_cron.log`.
- Pouziva runtime lock `uploads/tmp/sync_ikos_workers_cron.lock` proti soubeznemu spusteni.

---

## SMTP test email (PHPMailer)

### `send_test_mail.php`

Odesle testovaci e-mail pres SMTP (PHPMailer) podle konfigurace v `includes/config.local.php`.

Web:

```text
/scripts/send_test_mail.php
/scripts/send_test_mail.php?to=someone@example.com
```

CLI:

```bash
php scripts/send_test_mail.php
php scripts/send_test_mail.php --to=filip.rimek@prettl.com --subject="DVD SMTP test"
```

Poznamky:
- V admin dashboardu je dostupny i pres `Sprava -> Skripty` jako `SMTP test e-mail (PHPMailer)`.
- Pokud neni predan `to`, pouzije se `DVD_MAIL_TEST_TO`.
- SMTP se nastavuje pres klice `DVD_MAIL_SMTP_*` a `DVD_MAIL_FROM_*`.

---

## Outlook test email (lokalni profil)

### `send_test_mail_outlook.php`

Odesle testovaci e-mail pres lokalne prihlaseny Outlook profil (COM/MAPI), bez SMTP hesla v aplikaci.

Web:

```text
/scripts/send_test_mail_outlook.php
/scripts/send_test_mail_outlook.php?to=someone@example.com
```

CLI:

```bash
php scripts/send_test_mail_outlook.php
php scripts/send_test_mail_outlook.php --to=filip.rimek@prettl.com --subject="DVD Outlook test"
```

Poznamky:
- V admin dashboardu je dostupny jako `Outlook test e-mail (lokální profil)`.
- Pokud neni predan `to`, pouzije se `DVD_MAIL_TEST_TO`.
- Funguje jen v uzivatelske relaci, kde je Outlook nainstalovany a prihlaseny.

---

## SQL udrzba importovanych dat

### `sql/cleanup_import_data.sql`

Jednorazovy nebo opakovatelny cleanup importovanych dat v tabulce `zapisy`.

Co dela:
1. Dopocte `vyrobeno_dobre_ks` z agregovanych hodnot.
2. Normalizuje `oee_procent` do rozsahu 0-1.
3. Vytvori deterministicky `import_hash` pro deduplikaci.
4. Smaze presne duplicitni importovane radky.
5. Doplni `pracovisite` z importovanych snapshotu a napoji `zapisy.pracovisite_id`.
6. Zalozi unique index `uq_import_hash` pro ochranu proti dalsim duplicitam.

Spusteni:

```bash
mysql -u root -p dvd_vyroba < scripts/sql/cleanup_import_data.sql
```

### `sql/validate_import_data.sql`

Kontrolni SQL report nad importovanymi daty.

Kontroluje:
1. Zakladni rozsah a pocty v `zapisy`.
2. Zbyvajici duplicity podle `import_hash`.
3. Nesoulad `vyrobeno_dobre_ks`.
4. `oee_procent` mimo rozsah 0-1.
5. Nesoulad souctu prostoju.
6. Naplnenost detailnich tabulek (`zapis_nok`, `zapisy_prostoje`, `hodinove_sledovani`).

Spusteni:

```bash
mysql -u root -p dvd_vyroba < scripts/sql/validate_import_data.sql
```

### `sql/schema_post_restore_v5.sql`

Post-restore patch pro starsi dump `schema.sql`, ktery jeste obsahuje legacy downtime sloupce v `zapisy`.

Co dela:
1. Vytvori/obnovi tabulku `zapisy_prostoje`.
2. Migruje prostoje ze starych sloupcu + `technicke_prostoje`.
3. Odstrani legacy downtime sloupce z `zapisy`.

Spusteni:

```bash
mysql -u root -p dvd_vyroba < scripts/sql/schema_post_restore_v5.sql
```

### `sql/cleanup_pracovnici_duplicates.sql`

Servisni cleanup duplicit v tabulce `pracovnici` podle presne shodneho jmena.

Co dela:
1. Najde duplicitni skupiny podle `jmeno`.
2. Ponecha nejstarsi zaznam podle `id`.
3. Pokud existuje `zapis_pracovnici`, prepne na ponechany zaznam vsechny vazby.
4. Smaze nadbytecne duplicitni radky.
5. Vypise zaverecny souhrn poctu a zbyvajicich duplicit.

Spusteni:

```bash
mysql -u root -p dvd_vyroba < scripts/sql/cleanup_pracovnici_duplicates.sql
```

### `sql/validate_pracovnici_data.sql`

Kontrolni SQL report nad tabulkou `pracovnici` a pripadnymi vazbami v `zapis_pracovnici`.

Kontroluje:
1. Zakladni pocty a pomer aktivnich/neaktivnich pracovniku.
2. Duplicity podle `jmeno`.
3. Duplicity podle `interni_id`.
4. Prazdne povinne hodnoty `interni_id` a `jmeno`.
5. Rozbite nebo prazdne reference v `zapis_pracovnici`.
6. Detail maximalne 50 duplicitnich jmen pro rychly rozbor.

Spusteni:

```bash
mysql -u root -p dvd_vyroba < scripts/sql/validate_pracovnici_data.sql
```

### `sql/validate_zapisy_jmeno_format.sql`

Kontrolni SQL report formatu sloupce `zapisy.jmeno`.

Kontroluje:
1. Vyskyt stredniku v hodnotach jmen.
2. Koncove stredniky (`Jmeno;`).
3. Podezrele vicenasobne mezery.
4. Detail max 100 poslednich problematickych zaznamu.

Spusteni:

```bash
mysql -u root -p dvd_vyroba < scripts/sql/validate_zapisy_jmeno_format.sql
```

### `sql/validate_all.sql`

Spusti oba validacni reporty v jednom kroku:
1. `sql/validate_import_data.sql`
2. `sql/validate_pracovnici_data.sql`
3. `sql/validate_zapisy_jmeno_format.sql`

Spusteni:

```bash
mysql -u root -p dvd_vyroba < scripts/sql/validate_all.sql
```

---

### `setup_languages.php`

Inicializuje jazykový systém do databáze ze všech PHP souborů.

```bash
php scripts/setup_languages.php
```

**Co dělá:**
1. ✓ Vymaže stávající jazyky a překlady
2. ✓ Vloží jazyky (cs, uk, en)
3. ✓ Importuje všechny překlady z `lang/*.php`
4. ✓ Ověří počet vložených záznamů

**Výstup:** Přehledný report o počtu překladů na jazyk

---

### `generate_lang_sql.php`

Vygeneruje SQL INSERT statements ze PHP souborů.

```bash
php scripts/generate_lang_sql.php > translations_seed.sql
```

**Použití:**
- Záloha překladů
- Migrace mezi servery
- Ruční kontrola dat

**Výstup:** SQL script připravený k importu

```sql
DELETE FROM translations;
INSERT INTO translations (lang_code, `key`, `value`) VALUES
('cs', 'app_name', 'DVD Sledování výroby'),
('en', 'app_name', 'DVD Production Tracking'),
...
```

---

## Jak to Funguje

1. **scripts/setup_languages.php** se **automaticky připojuje** k DB (přes config.php)
2. **scripts/generate_lang_sql.php** je **standalone** – generuje jen SQL text (bez DB)

---

## Instalace (jednorázově)

```bash
cd /cesta/k/dvd
php scripts/setup_languages.php
```

**Done!** Všechny překlady jsou nyní v DB.

---

## Údržba (až na nové překlady)

Když přidáš nový klíč `t('novy_klic')`:

```php
// 1. Přidej do všech 3 souborů v lang/
// lang/cs.php
'novy_klic' => 'Česká hodnota',

// lang/en.php
'novy_klic' => 'English value',

// lang/uk.php
'novy_klic' => 'Українське значення',

// 2. Synchronizuj s DB
php scripts/setup_languages.php

// 3. Teď můžeš editovat přes web
// admin/translations.php
```

---

## Tips & Tricks

### Backup překladů

```bash
php scripts/generate_lang_sql.php > backup_2026-01-15.sql
```

### Export do CSV

```bash
# Přes phpMyAdmin nebo:
mysql -u dvd_user -p dvd_vyroba -e "SELECT lang_code, key, value FROM translations" > translations.tsv
```

### Kombinovaný import-export

```bash
# Vygeneruj SQL z PHP
php scripts/generate_lang_sql.php | mysql -u dvd_user -p dvd_vyroba
```

---

Viz také: [LANGUAGES.md](../LANGUAGES.md) pro web UI a detaily

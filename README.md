# DVD Sledování výroby v5.6.6 - Instalační a provozní příručka

## Struktura projektu

```
dvd/                                ← Složka aplikace (URL dle APP_URL)
├── index.php                       ← Hlavní vstupní formulář pro zápis směny
├── save_zapis.php                  ← Uložení nového záznamu (normalizované prostoje)
├── schema.sql                      ← Aktuální schema dump (včetně post-restore bloku v5)
├── changelog.md                    ← Historie verzí
├── README.md                       ← Tento soubor
├── MANUAL.md                       ← Uživatelský manuál
├── LANGUAGES.md                    ← i18n dokumentace
├── DEPENDENCY_GRAPH.md             ← Architektura a závislosti
├── TESTING_ROLES.md                ← Testovací scénáře rolí
├── role_levels.md                  ← Matice oprávnění
│
├── admin/                          ← Admin část aplikace
│   ├── import.php                  ← Ruční import Excel/CSV
│   ├── scripts.php                 ← Servisní dashboard + deploy release
│   ├── users.php                   ← Správa uživatelů
│   ├── writer_users.php            ← Správa writer/read účtů
│   ├── pracovisite.php             ← Správa pracovišť
│   ├── pracovnici.php              ← Správa pracovníků
│   ├── nok_kody.php                ← Správa NOK kódů
│   ├── prostoj_typy.php            ← Správa typů prostojů
│   ├── zaznamy.php                 ← Přehled záznamů
│   ├── zapis_detail.php            ← Detail záznamu
│   ├── zapis_edit.php              ← Editace záznamu
│   ├── save_zapis_edit.php         ← Uložení editace
│   ├── oee.php                     ← OEE dashboard
│   ├── tv.php                      ← TV dashboard
│   ├── kt_report_all.php           ← Týdenní výroba (dashboard)
│   ├── kt_report_all_tv.php        ← Týdenní výroba (TV)
│   ├── traceability.php            ← Traceability report
│   ├── traceability_terminal.php   ← Traceability terminál
│   ├── traceability_shift.php      ← Traceability přehled směny
│   ├── traceability_records.php    ← Traceability editor
│   ├── traceability_tv.php         ← Traceability TV
│   ├── traceability_kiosk_login.php
│   ├── traceability_kiosk_project.php
│   ├── traceability_kiosk_scan.php
│   └── manual.php                  ← Uživatelský manuál (role-based, concise/detailed)
│
├── api/
│   └── pracovisite.php             ← JSON API pro data pracoviště
│
├── includes/
    ├── config.php                  ← Bootstrap + DB + helpery (APP_VERSION 5.6.6)
│   ├── config.local.php.example    ← Lokální konfigurace
│   ├── import_runtime.php          ← Sdílené mapování/import helpery
│   ├── import_excel.py             ← Parser Excel/CSV
│   ├── lang_loader.php             ← DB/PHP fallback překladů
│   ├── layout.php
│   └── layout_end.php
│
├── scripts/
│   ├── release_check.php           ← Release readiness + tag workflow
│   ├── backup_db.php               ← SQL backup
│   ├── auto_import_dvd.php
│   ├── sync_dvd_import.php
│   ├── sync_ikos_workers.php
│   ├── sync_traceability.php
│   ├── sync_traceability_users.php
│   ├── run_migrate_zapisy_prostoje.php
│   ├── run_check_zapisy_prostoje.php
│   ├── apply_schema_post_restore_patch.ps1
│   ├── README.md
│   └── sql/
│       ├── migrate_zapisy_prostoje.sql
│       ├── schema_post_restore_v5.sql
│       ├── validate_zapisy_prostoje_consistency.sql
│       ├── validate_import_data.sql
│       └── validate_all.sql
│
├── assets/                         ← CSS/JS + CSV template
├── lang/                           ← cs/en/uk fallback překlady
├── uploads/tmp/                    ← Locky + runtime state soubory (logy a tmp data negitovat)
└── backupSQL/                      ← Lokální SQL zálohy mimo git
```

## Aktuální CSV import pracovníků

```text
interni_id;jmeno;ukonceni;smennost;role
11726;Bobková Světlana;31.12.2099;Směna A;D
11587;Balogová Milada;31.12.2099;Směna B;
```

Pole `role` je volitelné. Prázdná nebo chybějící hodnota se uloží jako `D`; ostatní hodnoty se přijímají jako volný text bez omezeného seznamu.

## Co je nové ve v5.6.6

- NOK matice umožňuje vybrat až pět pracovišť a vykreslí jen jejich sloupce.
- Uložení zachovává nastavení nevybraných pracovišť; AFO seznam lze filtrovat hledáním.
- Matice lépe využívá šířku obrazovky a tlačítko Přestavět z Excelu je skryté.

## Co bylo nové ve v5.6.5

- Změna pracoviště zneplatní stará předvyplněná data; tisk před sestavením ověřuje platné ID pracoviště.
- NOK položky bez AFO mapování se nezobrazí.
- Přehled záznamů obnovuje filtry po návratu z akce a umožňuje jejich úplné vymazání tlačítkem Reset.
- Detail pracovníka lépe využívá šířku obrazovky a zalamuje dlouhé údaje pracoviště.

## Co bylo nové ve v5.6.4

- Číselník pracovníků ukládá datum ukončení a zobrazuje stavy **Aktivní/Ukončeno**; přesné datum se ve výpisu nezobrazuje.
- Hlavní formulář nabízí pouze pracovníky s datem ukončení v budoucnosti.
- Importní CSV používá formát `interni_id;jmeno;smennost;ukonceni`; duplicity lze vybrat v modalu a již ukončené duplicitní skupiny se přeskočí.
- Číselník má sloupcové filtry, selecty pro směnnost/třídu a řazení ASC/DESC/vypnuto.
- Synchronizace uchovává datum ukončení a třídu zaměstnance.

## Co bylo nové ve v5.6.3

- Traceability Lineleader (`writer`) může na hlavním formuláři vybrat okruh/pracoviště a tisknout, ale nemůže zapisovat výrobní data.
- Simulátor rolí po změně role znovu načítá stránku přes POST/Redirect/GET a menu odpovídá simulované roli.
- Opravena chyba OEE Top NOK způsobená rozdílnou kolací textových hodnot v databázi.
- Aktualizován úplný statický i role-based manuál podle aktuálního stavu projektu.

## Co bylo nové ve v5.6.2

- Role `fsk_print` poskytuje read-only výběr pracoviště/okruhu a tisk prázdné nebo vyplněné A4 šablony.
- FSK má omezený sidebar s Novým zápisem, profilem, odhlášením a nastavením tématu, jazyka a kompaktního režimu.
- Superadmin může v `admin/scripts.php` simulovat libovolnou roli; menu se řídí stejnými permission podmínkami jako skutečný účet.
- Simulaci lze ukončit bez odhlášení a spouštění skriptů/deploy akcí je během simulace blokováno.

## Co bylo nové ve v5.6.0

- Default „Nezapočítávat operátorovi“ se spravuje v NOK/AFO matici pro každé pracoviště a NOK kód zvlášť.
- V novém i editovaném záznamu lze default konkrétního pracoviště přepsat; fyzický NOK se stále eviduje celý.
- Scrap/Q/OEE pracovníků a přehledy rozlišují NOK celkem a NOK operátora.
- Excel rebuild/import matice zachovává její per-pracovištní výjimky.

## Co je nové ve v5.5.0

- Měsíční výsledovka operátorů s filtry, OEE a CSV exportem; sdílené záznamy se v souhrnech počítají pouze jednou.
- Přidaný denní checklist seřizovače, editor checklistů a katalog úkonů podle projektu.
- Aktualizovány výpočty OEE, detail pracoviště, formulář, editace záznamů a správa pracovišť.
- Testovací instalace `v3.4` si zachovává vlastní URL.

- Při bootstrapu databáze se přidá typ `vstrikovna` a nastaví se na určených pracovištích i souvisejících záznamech; před prvním spuštěním vytvořte zálohu DB.

## Co je nove ve v5.3.4

- `v3.4` byla synchronizovana s implementaci `v5.3` pro klicove soubory formularoveho toku a administrace.
- Sjednocene soubory: `index.php`, `includes/config.php`, `admin/nok_kody.php`, `admin/prestavky.php`, `admin/zaznamy_pracovnici.php`, `admin/zaznamy_pracovnici_detail.php`.
- NOK editor, prestavky a reporty pracovniku odpovidaji stejnemu funkcionalnimu stavu jako ve vetvi `v5.3`.

## Co je nové ve v5.3.3

- Sekce 02 ve formuláři (`index.php`) počítá pole `Pauza` dynamicky podle reálného časového překryvu záznamu s číselníkem `prestavky`.
- Výpočet je vázaný na typ výroby (`Série/Filtry`), režim směny (8h/12h) a konkrétní kód směny.
- Při částečném záznamu směny se započítají jen přestávky, které časově skutečně nastaly; bez překryvu je hodnota `0`.

## Co je nové ve v5.3.2

- NOK editor (`admin/nok_kody.php`) rozsireny o filtraci a editor pridani/odebrani kodu.
- Panel `Prestavet z Excelu` je viditelny pouze pro `superadmin` a backend akce je autorizacne chranena.
- Import pracovniku (`admin/pracovnici.php`) funguje jako full replace + deduplikace podle `interni_id`.
- Ciselnik prestavek a vypocet pauzy byly sladene na aktualni sablonu cislovanych pauz.

## Co je nové ve v5.3.1

### Číselník směn (Správa -> DVD)
- **Nová administrace směn**: Přidána stránka `admin/smeny.php` pro správu směn (CRUD + aktivace/deaktivace).
- **Databázový model**: Směny jsou ukládány v tabulce `smeny` (kód, název, časy, pracovní doba, pauza, pořadí, aktivita).
- **Runtime bootstrap**: `includes/config.php` zajišťuje automatické vytvoření tabulky a seed výchozích směn při prvním použití.
- **Hlavní formulář**: Výběr směny v `index.php` je nyní dynamicky načítán z DB číselníku.

### Manuál a lokalizace
- **Uživatelský manuál**: Rozšířena část Traceability Terminál (detailnější postup resetu palety) a doplněn číselník směn.
- **Lokalizace**: Sjednoceny klíče mezi jazykovými soubory (doplněn chybějící `manual_quick_step_7` v `lang/cs.php`).

## Co je nové ve v5.3

### UI/UX Vylepšení
- **Pracoviště a Pracovník**: Změněn výběr ze standardního SELECT na interaktivní custom dropdown s fulltextovým vyhledáváním.
- **Filtrování dle typu**: Pracovní místa se automaticky filtrují při přepínání mezi tlačítky "SÉRIE" a "FILTRY" (v `index.php`).
- **Vyhledávání podle ID**: Zadáním čísla (např. "50") se najde pracoviště s `[50]` v hranatých závorkách.
- **Klávesnicová navigace**: Šipky nahoru/dolů pro pohyb v dropdownu, Enter pro výběr, Escape pro zavření.
- **Tmavý a světlý režim**: Obě témata jsou plně podporována přes CSS proměnné.

### OEE Výpočty – Oprava Agregace
- **Problém**: Původní `AVG(oee_procent)` vypočítával průměr záznamů bez ohledu na jejich dobu trvání → 5 minut OEE 90% měl stejnou váhu jako 8hodinová směna OEE 50%.
- **Řešení**: Implementován vážený výpočet: $OEE = \frac{\sum čistý\_čas}{\sum pracovní\_doba} \times \frac{\sum vyrobeno}{\sum norma} \times \frac{\sum vyrobeno - \sum nok}{\sum vyrobeno}$
- **Aplikováno**: `admin/oee.php`, `admin/tv.php`, `admin/zaznamy.php`, `admin/zaznamy_pracovnici.php`, `admin/zaznamy_pracovnici_detail.php`.
- **Výsledek**: OEE je nyní matematicky správné pro agregované údaje (denní/týdenní/celoroční statistiky).

### Refaktorace NOK Systému
- **Odebrána tabulka**: `nok_afo_alias` (zbytečné mapování — přímé vazby stačí).
- **Schéma**: Přejmenování `nok_kody_afo` → `nok_matrix_afo` pro vyšší čitelnost.
- **Automatická migrace**: Runtime migrace ze starého schématu na nové (bez ručního SQL, spustí se v `includes/config.php`).
- **Architektura**: Odebrán koncept "skupin" (`nok_kody.skupina` column) — NOK kódy se zobrazují v plochém seznamu.
- **AFO-based filtrování**: V `admin/zapis_edit.php` se NOK kódy automaticky filtrují podle zvoleného AFO (VisibilityMap).

## Co je nové ve v5.2.4

- Uživatelský manuál: nová stránka `/admin/manual.php` v menu (stejná záložka), zobrazuje pouze sekce dostupné podle role.
- Manuál: stručný/podrobný režim (`?manual_mode=concise|detailed`), sekce obsahu při tisku skryta, opravený tiskový layout.
- Manuál: všechny texty v lang souborech, stránka používá pouze `t('...')` klíče.
- `LanguageLoader`: fallback – při chybějícím DB klíči se sahá do PHP lang souborů.
- Správa uživatelů: uživatel s rolí `read` (Operátor) dostane `password_changed_at = NOW()` při vytvoření — účet je rovnou aktivní bez iniciálního hesla.
- Správa uživatelů: heslo (číslo karty) se ukládá jako plain-text do `dvd_vyroba.users.traceability_card_code` a `traceability2.user.password` pro porovnání 1:1 přes čtečku karet.
- Traceability sync: `traceability2.user.password` se přepíše pouze pokud bylo zadáno nové heslo při editaci.
- Bezpečnost: `.env` přidán do `.gitignore`.

## Co je nové ve v5.1.1

- Serizovaci checklist je stabilizovan po runtime chybach (guardy inicializace + odolnejsi importni tok).
- Katalog checklistu podporuje synchronizaci projektu/polozek z XLSX i rucni pridani polozky pres modal.
- Pri sprave uzivatelu se role `read` a `writer` automaticky synchronizuji i do `traceability2.user`.

**Aktuální stav v5.1.1:**
- ✅ Responsive design (6 breakpointů: desktop, tablet, mobile 600px, mobile <600px, landscape)
- ✅ Klávesové zkratky: `Ctrl+Alt+Enter` pro submit formuláře
- ✅ Role-based access control (6 úrovní: superadmin, admin, write, writer, read, traceability)
- ✅ Audit trail s JSON diffs (audit_log tabulka)
- ✅ Database-driven i18n (languages, translations tabulky)
- ✅ Správa uživatelů s real name polem
- ✅ CSV import uživatelů v sekci Správa uživatelů
- ✅ TV mode bez autentizace (veřejný live dashboard)
- ✅ touch-friendly prvky (44×44px minimum na mobilu)
- ✅ Přepínání tmavého/světlého režimu s uložením volby
- ✅ Přepínač kompaktního sidebaru (menu pouze ikony)
- ✅ Traceability² report (synchronizace z LB100021, filtry, graf, top operátoři, CSV export)
- ✅ Automatická synchronizace traceability2 každých 30 minut (Cronical / cron)
- ✅ Nová role `traceability`: readonly přístup k Traceability², Záznamy, OEE, TV; výchozí přihlášení na Traceability² report
- ✅ První přihlášení: povinná změna hesla (dokud není změněno, přístup jen na Profil + Odhlásit)
- ✅ Správa uživatelů: stav hesla (změněno/iniciální) + reset na iniciální stav
- ✅ Výběr pracoviště přes fulltext našeptávač (bez původního selectu)
- ✅ Režimové omezení pracovišť: `Filtry` = projekt `EMV Filter` + `Ostatní`/`Šrot`, `Série` = všechna pracoviště
- ✅ Sjednocený report `Týdenní výroba` (nahradil dashboard varianty KT report 1/2)
- ✅ Multi-výběr pracovišť s logikou aktivní výroby v daném KT
- ✅ Uživatelské trvalé presety filtrů (per-user) + pevné presety `KT report` a `KT report 2`
- ✅ TV menu obsahuje `Týdenní výroba` (zrušena samostatná položka `Denní výroba LH a EMV`)
- ✅ Sekce `Skripty` obsahuje integrované nasazení release do produkce (výběr tagu + tlačítko `Nasadit release`)
- ✅ Deploy workflow přepisuje v produkčním rootu `C:/laragon/www/includes/config.php` hodnotu `APP_URL` na `http://10.67.100.69/` a před změnou vytváří backup configu
- ✅ Testovací mail workflow přes SMTP i lokální Outlook profil
- ✅ Nový uživatel může dostat inicialni heslo e-mailem z Outlooku pri vytvoreni uctu
- ✅ Sidebar spravne sbaluje i polozky s `d-flex` wrapperem (napr. `Týdenní výroba`)
- ✅ Mazani uzivatele ve `Správa -> Uživatelé` je potvrzovano pres modal
- ✅ V editaci uzivatele lze znovu odeslat inicialni mail (modal s cilovym e-mailem) a tim nastavit stav hesla zpet na `Inicialni`
- ✅ Uživatelský manuál dostupný z menu (role-based, stručný/podrobný režim, tiskový layout)
- ✅ Heslo Operátora (role `read`) = číslo karty uložené plain-text v `traceability2.user.password` i `users.traceability_card_code`
- ✅ Nový účet role `read` nevyžaduje změnu hesla při prvním přihlášení (`password_changed_at` nastaveno při vytvoření)

---

## Release a Deploy workflow (v5.0.7)

### Test prostředí

- Testovací prostředí (repo root): `C:/laragon/www/pisek/v3.4`
- URL testu: `http://LB100069/pisek/v3.4`

### Produkční prostředí

- Produkční root: `C:/laragon/www`
- URL produkce: `http://10.67.100.69/`

### Nasazení release z dashboardu

1. Přihlásit se jako `superadmin`.
2. Otevřít `Správa -> Skripty`.
3. V sekci **Deploy release** vybrat release tag (`vX.Y.Z`).
4. Kliknout na **Nasadit release**.

Deploy provede:
- checkout vybraného tagu do produkčního rootu `C:/laragon/www`,
- vytvoření backupu produkčního `includes/config.php`,
- přepis `define('APP_URL', ...)` na `define('APP_URL', 'http://10.67.100.69/');`.

Poznámka:
- Deploy nevyžaduje samostatnou stránku; workflow je součástí `admin/scripts.php`.

---

## Požadavky

| Komponenta | Verze        |
|-----------|-------------|
| PHP       | 8.1+        |
| MySQL     | 8.0+ nebo MariaDB 10.6+ |
| Python    | 3.8+ (pro import z Excelu) |
| openpyxl  | pip install openpyxl |

---

## Kompatibilita a výkon

### Devices & Browsers

| Zařízení | Stav | Poznámka |
|----------|------|----------|
| 💻 Desktop | ✅ Plná podpora | Chrome, Firefox, Edge, Safari |
| 📱 Mobil (iOS/Android) | ✅ Optimalizováno | Responsive design, touch-friendly (44×44px min) |
| 📲 Tablet (8"+) | ✅ Ideální | Landscape režim doporučen |
| ⌚ Malé telefony (<480px) | ⚠️ Limitované | Základní funkčnost, složitější sekce |

### Responsive design

Aplikace se automaticky přizpůsobuje velikosti obrazovky:

```
Počítač (768px+)  → Sidebar + Plný formulář
Tablet (600–768px) → Skrytá sidebar, optimalizační layout
Mobil (<600px)    → Комpаktní design, skryté labely
```

Detaily naleznete v **[MANUAL.md](MANUAL.md#3-mobilní-a-malá-zařízení) → Mobilní a malá zařízení**.

---

## Instalace krok za krokem

### 1. Databáze

```sql
CREATE DATABASE dvd_vyroba CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'dvd_user'@'localhost' IDENTIFIED BY 'VaseHeslo123';
GRANT ALL PRIVILEGES ON dvd_vyroba.* TO 'dvd_user'@'localhost';
FLUSH PRIVILEGES;
```

```bash
mysql -u dvd_user -p dvd_vyroba < schema.sql
```

### 2. Konfigurace

Nastavte konfiguraci bez plaintext hesla v repozitáři:

```php
// 1) Zkopírujte includes/config.local.php.example -> includes/config.local.php
// 2) Vyplňte lokální hodnoty (soubor se negituje)

return [
    'DVD_DB_HOST' => 'localhost',
    'DVD_DB_NAME' => 'dvd_vyroba',
    'DVD_DB_USER' => 'dvd_user',
    'DVD_DB_PASS' => 'VaseHeslo123',
];
```

Alternativně použijte environment proměnné (`DVD_DB_HOST`, `DVD_DB_NAME`, `DVD_DB_USER`, `DVD_DB_PASS`).

`APP_URL` upravujte v `includes/config.php` podle nasazení.

Po prvním spuštění (nebo po změnách v `lang/*.php`) inicializujte překlady do DB:

```bash
php scripts/setup_languages.php
```

**Admin heslo** – změňte výchozí heslo `admin123`:

```bash
php -r "echo password_hash('NoveHeslo', PASSWORD_DEFAULT);"
```

Výsledný hash vložte do tabulky `users`:

```sql
UPDATE users SET pass_hash='$2y$10$...' WHERE username='admin';
```

### 3. Oprávnění složek

```bash
chmod 755 uploads/tmp/
chown www-data:www-data uploads/tmp/
```

### 4. Webserver

#### Apache – `.htaccess` v kořeni `dvd/`:

```apache
Options -Indexes
DirectoryIndex index.php
<FilesMatch "\.py$">
    Deny from all
</FilesMatch>
```

#### Nginx:

```nginx
location /dvd {
    try_files $uri $uri/ /dvd/index.php?$query_string;
}
location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
location ~ \.py$ { deny all; }
```

### 5. Python pro import Excelu

```bash
pip install openpyxl
# nebo:
pip3 install openpyxl --user
```

Ověření:

```bash
python3 includes/import_excel.py preview /cesta/DVD-2025-odemčená.xlsx
```

---

## Přihlašovací údaje (výchozí)

| Uživatel | Heslo    |
|----------|----------|
| admin    | admin123 |

**Okamžitě změňte!** Viz bod 2 výše.

---

## Funkce přehled

### Vstupní formulář (`index.php`)
- Přepínač Série / Filtry
- Výběr pracoviště přes vyhledávací našeptávač (datalist)
- Režim `Filtry`: pouze pracoviště projektu `EMV Filter` + položky `Ostatní`/`Šrot`
- Režim `Série`: kompletní seznam všech pracovišť
- Auto-fill detailů pracoviště z číselníku po výběru položky
- Výpočet ISO KT (CW) z data
- Výpočet čistého času dle příchod/odchod
- Živý OEE výpočet (A × P × Q)
- Prostoje P1–P10, O1, L1–L2, V1–V3, Q1–Q4
- Dynamické technické prostoje (T1–T22)
- 110+ NOK kódů seskupených dle kategorie
- Hodinové sledování výroby (24 slotů)
- AJAX uložení bez refreshe
- **Klávesové zkratky:**
  - **Ctrl+Alt+Enter** – 1. stisk otevře potvrzovací dialog, 2. stisk okamžitě uloží záznam
  - **Enter v textových polích** – Přesun na další pole (zabránění nechtěnému odeslání)

### Záznamový list (`admin/zaznamy.php`)
- Filtry: datum, typ, směna, pracoviště, jméno, KT, rok, OEE min
- Statistické karty
- OEE vizuální bary
- Export CSV (UTF-8 BOM pro Excel)
- Tisk – profiltrovaný report
- Klik na řádek → detail záznamu

### Detail záznamu (`admin/zapis_detail.php`)
- Všechna data záznamu strukturovaně
- Technické prostoje, NOK záznamy, hodinové sledování
- Tisk záznamu směny

### OEE Dashboard (`admin/oee.php`)
- Denní OEE line chart (Chart.js 4)
- Struktura prostojů donut chart
- Žebříček OEE dle pracoviště
- Top 10 NOK kódů

### Traceability² (`admin/traceability.php`)
- Report nad lokální kopií DB `traceability2` (skenání SN filtrů z LB100021)
- Filtry: datum, tabulka, sériové číslo, operátor
- Statistické karty, denní graf, rozpad dle tabulky, top operátoři, posled. 100 skenů
- Hlavička zobrazuje čas poslední sync; superadmin má odkaz pro ruční spouštění
- Automatická synchronizace každých 30 minut (Laragon Cronical: `0,30 * * * *`)
- Ruční spouštění: `php scripts/sync_traceability.php` nebo přes admin Skripty

### Traceability Kiosk (`admin/traceability_kiosk_login.php`)
- Fullscreen scanner flow bez sidebaru: přihlášení heslem -> výběr projektu -> produkční scan stránka.
- Stránky jsou optimalizované pro terminál/kiosk (velké inputy, scanner-first ovládání, auto-focus inputu).
- Při ztrátě focusu klikem mimo ovládací prvky se focus automaticky vrací do vstupního pole.
- V horní liště scan stránky je přepínač jazyka, přepínač light/dark a tlačítko Odhlásit.
- Příkazy lze zadávat přímo scannerem do inputu.

#### Moduly Traceability Kiosk
- `admin/traceability_kiosk_login.php` -> krok 1: login heslem + scanner příkazy `cz/en/ua/light/dark`
- `admin/traceability_kiosk_project.php` -> krok 2: výběr projektu
- `admin/traceability_kiosk_scan.php` -> krok 3: produkční scan + servisní příkazy

#### Kiosk příkazy (scanner input)

**Přihlašovací stránka (`traceability_kiosk_login.php`)**
- `cz`, `en`, `ua` -> přepnutí jazyka (bez ověření uživatele)
- `light`, `dark` -> přepnutí grafického režimu (bez ověření uživatele)

**Výběr projektu (`traceability_kiosk_project.php`)**
- `<nazev_projektu>` -> výběr projektu a přechod na scan stránku

**Produkční scan (`traceability_kiosk_scan.php`)**
- `project` -> návrat na výběr projektu
- `editor` -> přechod na editor záznamů (jen práva >= 90)
- `users` -> přechod na správu uživatelů (jen práva >= 100)
- `logout` -> odhlášení kiosku
- `resetpalety` -> reset počítadla palety + zápis systémového řádku
- `cz`, `en`, `ua` -> okamžité přepnutí jazyka
- `light`, `dark` -> okamžité přepnutí grafického režimu
- `<seriove_cislo>` -> standardní uložení nového průchodu dílu

### TV Mode (`admin/tv.php`)
- Fullscreen výrobní statistiky pro TV displej
- Auto-refresh každých 60 sekund
- Dnešní výroba, NOK, OEE, top chyby
- Live hodiny

### Týdenní výroba (`admin/kt_report_all.php`, `admin/kt_report_all_tv.php`)
- Dashboard: KT/rok + multi-výběr pracovišť
- Bez ručního výběru se zobrazují pouze pracoviště, kde se v týdnu vyrábělo
- Uložené filtry: pevné (`KT report`, `KT report 2`) a uživatelské trvalé presety
- TV varianta: posledních 7 dní a pouze aktivní pracoviště

### Správa číselníků (vyžaduje přihlášení)
- **Pracoviště**: CRUD + CSV import
- **Pracovníci**: CRUD + CSV import
- **NOK kódy**: CRUD + CSV import
- **Typy prostojů**: CRUD

### Import z Excelu/CSV (`admin/import.php`)
- Nahrání souborů .xlsx, .xls, .csv
- Náhled prvních 20 řádků
- Import do databáze (Excel: list „Databáze", CSV: automatická detekce oddělovače)
- Python backend script
- Auto-import ze sdíleného síťového souboru (superadmin + denní lock)

### Jazyky
- 🇨🇿 Čeština (výchozí)
- 🇬🇧 Angličtina
- 🇺🇦 Ukrajinština
- Přepínač v sidebar footer, nebo `?lang=cs` / `?lang=en` / `?lang=uk`

---

## CSV formáty pro import

### Pracoviště (`pracovisite.csv`)
```
cislo_id;zakaznik;projekt;verze;kod_pracovisite;nazev_pracovisite;nazev_dilu;sap_cislo;nakladove_stredisko;oznaceni;norma_ks_min;cyklus_min;typ
45;Bosch;Dnox 2.2 M;Magnet 24V;AFO 50;Zástřik;Zastříklá spule;50000414;11001108;Pol;0.175;5.714;serie
```

### Pracovníci (`pracovnici.csv`)
```
interni_id;jmeno;ukonceni;smennost;role
11726;Bobková Světlana;31.12.2099;Směna A;D
11587;Balogová Milada;31.12.2099;Směna B;
```

### Uživatelé (`users.csv`)
```
username;skutecneJmeno;role;aktivni;heslo
novakj;Jan Novak;write;1;BezpecneHeslo123
```

### NOK kódy (`nok_kody.csv`)
```
kod;popis;skupina
103;Poškozeno manipulací (spadlo na zem);manipulace
```

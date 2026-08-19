# Changelog

Tento soubor je centralni changelog projektu.
Kazda nova verze musi mit vlastni sekci ve formatu ## vX.Y.Z.

## v5.3.3 (2026-08-10)

### Zmeny
- `index.php` (sekce 02): pole `Pauza` je nyni vypocitavano dynamicky podle realneho casoveho prekryvu zaznamu s ciselnikem `prestavky`.
- Vypocet respektuje kombinaci `Série/Filtry`, rezim smeny (8h/12h) a konkretni kod smeny (`R8/O8/N8/R12/N12`).
- Pokud zaznam nezasahuje do zadne prestavky, hodnota `Pauza` je `0`; pri celesmennovem zaznamu je to soucet prestavek dane smeny.
- Prepocet se provadi prubezne pri zmene typu vyroby, smeny a casu `od/do`, aby odpovidal realnemu prubehu jednotlivych zaznamu operatora.

## v5.3.2 (2026-08-10)

### Zmeny
- `admin/nok_kody.php`: pridana filtrace (ID/KOD/Popis, aktivni/vse), editor pro pridani a odebrani NOK kodu a aktualizace prekladu `nok_XXX_popis`.
- `admin/nok_kody.php`: tlacitko a panel `Prestavet z Excelu` jsou zobrazene pouze pro roli `superadmin`; backend akce je navic chranena proti primeho POST volani bez opravneni.
- `admin/pracovnici.php`: importy (`sync_replace_workers` i `csv_import`) byly prepracovany na rezim full replace (smazani tabulky `pracovnici` a nahrani celeho souboru).
- `admin/pracovnici.php`: doplnena deduplikace podle `interni_id` pri full-replace importu, aby nedochazelo k rollbacku celeho importu pri duplicitach ve zdroji.
- `includes/config.php` + `index.php`: upraven ciselnik prestavek a vypocet pauzy tak, aby se pracovalo s cislovanymi pauzami z aktualni sablony a zapocitavaly se vsechny polozky.

## v5.3.1 (2026-08-05)

### Zmeny
- Pridana nova administracni stranka `admin/smeny.php` pro ciselnik smen (CRUD + aktivace/deaktivace).
- Smeny presunuty z pevneho seznamu do DB tabulky `smeny`; `index.php` nyni nacita seznam smen dynamicky.
- `includes/config.php`: doplnena runtime inicializace ciselniku smen vcetne seed dat vychozich smen.
- `includes/layout.php`: do menu `Sprava -> DVD` pridana polozka `Smeny`.
- Rozsireny uzivatelsky manual (`admin/manual.php`, `MANUAL.md`) o ciselnik smen a detailni postup resetu palety v Traceability Terminalu.
- Lokalizace: doplnen chybejici klic `manual_quick_step_7` v `lang/cs.php`; czech/ukrainian key parity sjednocena.

## v5.3 (2026-07-20)

### Zmeny

#### UI/UX - Výběr Pracoviště a Pracovníka
- Pracoviště výběr: změněn z `<select>` na custom dropdown s fulltextovým vyhledáváním.
- Pracoviště: vyhledávání funguje po kliknutí (zobrazí se všech 80+ položek) nebo při psaní textu.
- Pracoviště: speciální podpora pro hledání podle čísla v hranatých závorkách (cislo_id, např. "50" najde "[50] Kayser").
- Pracoviště: filtrování podle typu (Serie/Filtry) se aktualizuje v real-time při kliknutí na typ-tlačítka.
- Pracovník: aplikován stejný custom dropdown pattern pro konzistenci UI.
- Pracovník: klik na pole zobrazí seznam všech dostupných pracovníků, psaní filtruje fulltextově.
- Oba dropdowny: navigace pomocí ↑↓ kláves, Enter pro výběr, Escape pro zavření.
- Oba dropdowny: plná podpora tmavého (dark) a světlého (light) tématu přes CSS proměnné.

#### OEE Výpočty - Oprava Agregace
- OEE: změněn výpočet z `AVG(oee_procent)` na vážený výpočet přes součty: $A \\times P \\times Q$
- Vzorec: $OEE = \\frac{\\sum cisty\_cas}{\\sum pracovni\_doba} \\times \\frac{\\sum vyrobeno}{\\sum norma} \\times \\frac{\\sum vyrobeno - \\sum nok}{\\sum vyrobeno}$
- Výpočet je nyní matematicky správný pro agregované údaje (např. celkové OEE z více záznamů).
- Změněno v modulech:
  - `admin/oee.php` - denní OEE, OEE dle pracoviště, celkové statistiky
  - `admin/tv.php` - denní stats a týdenní přehled
  - `admin/zaznamy.php` - filtované statistiky záznamů
  - `admin/zaznamy_pracovnici.php` - přehled na pracovníka + agregované stats
  - `admin/zaznamy_pracovnici_detail.php` - detail pracovníka

### Technical Details
- Pracoviště: přidán `typ` field do JS `pracovisteSuggest` datové struktury pro filtrování.
- Dropdown: CSS proměnné (`--surface`, `--border`, `--text`, `--accent`) pro automatický theme.
- OEE: odstranění dependence na per-záznam `oee_procent` při agregaci; používáno výhradně `cisty_cas_min`, `pracovni_doba_min`, `vyrobeno_ks`, `norma_ks`, `nok_ks`.

## v5.2.4 (2026-07-07)

### Zmeny
- Uzivatelsky manual: nova stranka `/admin/manual.php` jako polozka menu (stejna zalozka), zobrazuje pouze sekce dostupne podle role uzivatele.
- Manual: obsah rozdelenl na strucny/podrobny rezim (`?manual_mode=concise|detailed`), tisk skryje sekci obsahu a opraven layout pri tisku.
- Manual: vsechny texty presunute z inline PHP poli do jazykovych souboru (`lang/cs.php`, `lang/en.php`, `lang/uk.php`), stranka pouziva pouze `t('...')` klice.
- `LanguageLoader`: zlepsen fallback — pri chybejicim DB klici se automaticky sahne do PHP lang souboru (neklice se neztraceji).
- Uzivatelska sprava: pri vytvoreni uzivatele s roli `read` (Operator) se nastavi `password_changed_at = NOW()`, ucet je rovnou aktivni bez inicialniho hesla.
- Uzivatelska sprava: cislo karty (pole hesla ve formulari) se uklada jako plain-text do `dvd_vyroba.users.traceability_card_code` a zaroven do `traceability2.user.password` pro porovnani 1:1 pri pristupu pres ctecku.
- Traceability sync: pri editaci uzivatele se `traceability2.user.password` prepise pouze pokud bylo zadano nove heslo (karta).

## v5.2.3 (2026-06-26)

### Zmeny
- Traceability Terminal: hotfix AJAX endpointu pro live refresh presunuty pred render stranky, aby odpoved byla vzdy validni JSON i na produkci.
- Traceability Terminal: backend vraci pri chybe jednotny JSON (`400/500`) misto neparsovatelne odpovedi.
- Traceability Terminal: klientsky refresh zobrazuje detail chyby (`HTTP xxx`) misto obecneho `network/API`.

## v5.2.2 (2026-06-26)

### Zmeny
- Traceability Terminal: opraven live refresh pocitadla kusu na palete (stabilni polling, `no-store` cache, anti-overlap request guard).
- Traceability Terminal: pridana viditelna kontrola refresh stavu (OK/chyba) s casem posledni odpovedi.
- Traceability Terminal: v refresh statusu se zobrazuje oficialni nazev projektu (napr. pro `48w200` se zobrazi `CSL`).
- Traceability TV: pole Live je nově navazane na realny stav API refreshe (`Live` / `Not Live`).
- Traceability TV: `Not Live` stav je zobrazen cervene a zustava pulzujici pro okamzitou viditelnost v provozu.
- Dokumentace: aktualizovan `DEPENDENCY_GRAPH.md` na aktualni architekturu a sync toky.

## v5.2.1 (2026-06-26)

### Zmeny
- Pracovnici: synchronizace pres topbar tlacitko prepracovana na refresh podle `interni_id` (1. sloupec CSV), bez delete/reinsert.
- Pracovnici: pri refreshi se nejdriv nastavi `aktivni=0`, nasledne se existujici zaznamy aktualizuji a chybejici vlozi.
- Automaticky IKOS sync (`scripts/sync_ikos_workers.php`): sjednocena logika s rucnim syncem na rezim `refresh_by_interni_id`.
- Audit log: sjednocena akce synchronizace na `SYNC_IKOS_WORKERS` pro rucni i automaticky beh.
- Pracoviste: upraven filtr `Stav: vse`, vychozi hodnota filtru a razeni zaznamu podle `id`.
- Lokalizace roli: vizualni prejmenovani role `Read` na `Operator` (`Operátor` / `Operator` / `Оператор`).

## v5.2.0 (2026-06-26)

### Zmeny
- Traceability Správa a Editor: přidán projekt `gen3cina` do výběru tabulek
- Traceability terminal: live refresh posledních scanovaných kusů každých 5 sekund (AJAX endpoint vrací `recent` rows, JS aktualizuje tbody bez přerušení scanování)
- Nový projekt `gen3cina`: pouze logování bez tisku (limit 280 ks)
- `.gitignore`: přidány výjimky pro runtime SQL dumpy a deployment skripty

## v5.1.4 (2026-05-28)

### Zmeny
- Traceability TV dashboard: snížený refresh interval z 10s na 5s pro live data
- Traceability terminal: snížený refresh interval z 30s na 5s pro live data
- Nový projekt traceability: `gen3cina` s limitom 280 kusů (pouze logování bez tisku)
- Traceability TV: projekty se nyní zobrazují pouze pokud měly výkon v poslední hodině (count > 0)

## v5.1.5 (2026-06-03)

### Zmeny
- Traceability management & Editor: přidán nový projekt `gen3cina` do výběrů, aby byl viditelný ve Správě a Editoru
- Drobná aktualizace verzování a metadata nasazení

## v5.1.3 (2026-05-28)

### Zmeny
- Traceability terminal: upravenus logika tisku pri resetu palety — projekt `48w` pouze loguje na sitovy disk (bez tisku), projekty `48w200`, `48w200_2`, `gen3`, `gen3manual` logují a tisknou na výchozí tiskárnu.
- Traceability terminal: přidán živý náhled počtu kusů za směnu s auto-refreshem každých 30 sekund (podobně jako na TV dashboardu).

## v5.1.2 (2026-05-28)

### Zmeny
- Traceability terminal: pri resetu palety se automaticky ulozi seznam SN na sitovy disk (`\\lbfile01\pacz\Departments\LOGISTIKA\TraceabilityLog\YYYYMMDD_HHMMSS_projekt.txt`) a soubor se ticho odesle na vychozi systemovou tiskarnu bez potvrzovacich dialogu.
- Traceability terminal: hlavicka tisku obsahuje nazev ulozeného souboru a oddelovaci caru pro snazsi identifikaci.

## v5.1.1 (2026-05-21)

### Zmeny
- Serizovaci checklist: opravena runtime stabilita (`HTTP 500`) pri inicializaci tabulek a cteni dat.
- Serizovaci checklist: doplnen robustni import XLSX vcetne fallbacku bez `zip` extension a lepsiho parsovani XML namespacu.
- Serizovaci checklist: doplnen katalog projektu/polozek, synchronizace z XLSX, deaktivace neaktivnich polozek a normalizace projektu `List3 -> 48w`.
- Serizovaci checklist UI: pridano seskupeni AFO, modal pro rucni pridani polozky v katalogu, upravy toolbaru a uprava logiky vysledku na `OK/NOK`.
- Navigace a i18n: v sekci serizovaciho katalogu prejmenovano `Checklist` na `Polozky/Items/Пункти` a upravene poradi menu ve Sprave.
- Uzivatele: pri create/update/import je doplnen synchronizacni upsert do `traceability2.user` pro role `read` a `writer` (lineleader).

## v5.1.0 (2026-05-14)

### Zmeny
- Traceability TV: pridany prepinac 8h/12h rezimu s ulozenim stavu v `localStorage` a live refresh API dat podle zvoleneho rezimu.
- Traceability TV: pridana nova statisticka bublina `Kusu za smenu` pro kazdy projekt.
- Traceability TV: hodinovy prehled respektuje rezim smeny (8h = 9 radku, 12h = 13 radku).
- Navigace pro neprihlasene: skryty sekce DVD a Traceability management; zobrazeny pouze TV/Kiosk odkazy + login.
- Navigace: pro roli `writer` skryta sekce DVD.
- Traceability terminal: roli `writer` povolen pristup na terminal s aktivnim resetem palety, ale bez moznosti zadavat nove skeny.
- Profil: pole `Uzivatelske jmeno` nyni zobrazuje login (`username`) misto skutecneho jmena.
- Vizuální premenovani role `writer` na `Lineleader` v UI (CS/EN/UK preklady, interni role klice zustavaji beze zmen).

## v5.0.11 (2026-05-14)

### Zmeny
- Traceability terminal: pridano vizualni potvrzeni pred provedenim Reset Palety (ochrana pred nahodnym stisknutim nebo refreshem F5).
- Traceability terminal: opravena E500 chyba pri vkladani zaznamu (FK constraint violation, userId neexistoval v `traceability2.user`).
- Traceability terminal: admin/superadmin si nyni automaticky vytvori zaznam v `traceability2.user` pokud tam jeste neni, takze mohou okamzite skenovat.
- Traceability kiosk scan: pridany statistiky "Kusu za smenu" vedle pocitadla palety s live refreshem pres API.
- Traceability kiosk scan: opraveno pocitani hodinove vyroby — `ResetPalety` zaznamy jsou nynni vylouceny z poctu (drive zpusobovaly rozdil +N kusu).
- Traceability kiosk scan: pridan prepinac 8h/12h rezimu smeny v hlavicce; stav se uklada do `localStorage`.
  - 8h rezim: 06-14, 14-22, 22-06
  - 12h rezim: 06-18, 18-06
- Traceability kiosk scan: pri prepnuti rezimu se v tabulce Hodinova vyroba zobrazuje 9 hodin (8h) nebo 13 hodin (12h).
- Rework kiosk form: pole DMC prejmenovano na "Interni kod", pole pro nahradni kod prejmenovano na "Novy kod"; poznamka prevedena na textarea.
- Rework kiosk form: vsechny popisky formulate jsou nynni prekladatelne pres `t()` ve vsech 3 jazycich (CS/EN/UK).
- Menu: pridana vizualni potvrzovaci logika pro tlacitka s `data-confirm` atributem (vyuzivano pro Reset Palety a mazani).
- Lang: pridany prekladove klice `rework_internal_code`, `rework_replacement_code`, `traceability_palette_reset_confirm`, `traceability_shift_count`.


### Zmeny
- Detail vyroby v `admin/zapis_detail.php` uz zobrazuje jmeno pracovnika misto suroveho ID i po migraci modelu `zapis_pracovnici`.
- Worker reporty a resolver zustaly sjednocene na model `jmeno = pracovnici.id`, `pracovnik_id = pracovnici.interni_id`.
- Projekt byl procisten od jednorazovych SQL dumpu, pomocnych vystupu a servisnich testovacich skriptu, ktere nepatri do release commitu.
- Lokalne generovane zalohy v `backupSQL/` a pomocne vystupy `output.txt`, `temp_log_tail.txt` jsou nove ignorovane gitem.
- Ve workspace nebyl nalezen zadny soubor nad GitHub limitem `100 MB`, proto nebylo potreba vytvaret zip archiv.

## v5.0.9 (2026-05-13)

### Zmeny
- Traceability terminal: opraveno logovani resetu palety tak, aby se do auditu ukladal i `trace_user_id` aktualne prihlaseneho uzivatele.
- Traceability terminal: opravene mapovani uzivatelu v seznamu poslednich scanu; jmena se primarne berou z `traceability2.user` a fallbackem z lokalni tabulky `users`.
- Databaze: odstraneny nepouzivane backup/stage tabulky `pracovisite_import_stage`, `pracovisite_import_stage_backup_20260513` a `zapisy_pracovisite_backup_20260420`.
- Pridany servisni SQL skripty pro bezpecnou kontrolu/deprovisioning tabulky `pracovisite_import_stage` vcetne rollbacku.

## v5.0.8 (2026-05-12)

### Zmeny
- Dokoncena sekce Opravy: terminal, fullscreen kiosk (login + form), report, editory ciselniku a import historickych dat z `uploads/Opravy.xlsx`.
- Upraven layout kiosku Opravy dle Traceability kiosku vcetne horni funkcni listy (theme/lang/hodiny/uzivatel) a panelu poslednich zaznamu.
- Opraveny runtime chyby po nasazeni Opravy modulu (`rework_terminal` 500, kompatibilita `user_roles` FK typu).
- Zavedena podpora vice roli na uzivatele (`user_roles`) vcetne aktualizace auth helperu, login session a spravy uzivatelu.
- V administraci uzivatelu prepracovan vyber roli na role-bubliny (multi-select chips) a upraveno zobrazovani vice roli.
- U dnes importovanych rework polozek sjednocen cas `recorded_at`, `created_at`, `updated_at` na aktualni cas pri provedeni operace.

## v5.0.8a (2026-05-13)

### Zmeny
- Rework report: preusporadani statistik do 2 sloupcu (typ opravy vlevo, projekt + akce vpravo pod sebou).
- Rework report: odstraneny informacni hint panel a sjednocene nazvy "Rozpad" na "Statistika" ve vsech jazycich.
- Navigace: polozky `Zaznamy` a `Report oprav` prejmenovany na `Editor` ve vsech fallback jazycich.
- Rework report: doplnen sloupec Akce s odkazy na detail, editaci a mazani zaznamu.
- Pridany stranky `admin/rework_entry_detail.php`, `admin/rework_entry_edit.php`, `admin/rework_entry_delete.php`.
- Rework kiosk form: uprava layoutu na dvousloupcovy rezim (formular vlevo, data vpravo), odstraneni ikon v nazvech panelu a zruseni vedlejsi stat bubliny.

## v5.0.7d (2026-05-12)

### Zmeny
- Doplnen online refresh kiosku pro sync stavu palety mezi zarizenimi (`admin/traceability_kiosk_scan.php`).
- Pridan API endpoint pro live data kiosku (`api/traceability_kiosk_data.php`).

## v5.0.7c (2026-05-12)

### Zmeny
- Upraveno omezeni hesla pro traceability writer/read uzivatele: minimum 6 znaku (`admin/writer_users.php`).
- Aktualizovany lokalizacni texty pro traceability minimum hesla (`lang/cs.php`, `lang/en.php`, `lang/uk.php`).

## v5.0.7b (2026-05-12)

### Zmeny
- Opravena validace nasazeni release tagu v `admin/scripts.php` pro suffix verze (napr. `v5.0.7a`).
- Opraven online refresh TV dashboardu v `admin/traceability_tv.php` (okamzity refresh + cache-busting + trigger pri focus/online/visibilitychange).
- Opravena validace datetime pri editaci traceability zaznamu v `admin/traceability_records.php` (`step="1"` pro sekundy).
- Osetreno mazani traceability zaznamu tak, aby se po smazani kusu z aktivni palety korektne prepocital `counterOnPallete` (`includes/config.php`).
- Docasne vypnuta traceability synchronizace pres kill-switch flag `uploads/tmp/traceability_sync.disabled` a scheduler guardy.

## v5.0.7a (2026-05-12)

### Zmeny
- Zvysena casova osa v traceability kiosk scanu z 9 hodin na 13 hodin (`admin/traceability_kiosk_scan.php`).
- Zachovan interval 13 hodin v TV dashboardu (`admin/traceability_tv.php`).
- Upravena registracni batch skript `scripts/register_scheduler_task.bat` s podporou --no-pause pro automatizovane spousteni.

## v5.0.7 (2026-04-30)

### Zmeny
- Soucasti deploy akce je povinny prepis produkcniho `APP_URL` v `C:/laragon/www/includes/config.php` na `http://10.67.100.69/`.
- Opraveny runtime chyby v reportu pracovniku (PHP parse error, SQL kolace, bind parametry hledani) a sjednoceno predavani `worker_key` do detailu.
- Doplnen fallback zobrazeni `interni_id` v reportu/detailu i pro zaznamy bez primych FK vazeb.
- Zvysena aplikacni verze v `includes/config.php` na `5.0.7`.

## v5.0.6 (2026-04-30)

### Zmeny
- Dokoncena normalizace downtime modelu: aktivni zapis i cteni prostojovych dat jsou sjednocene do tabulky `zapisy_prostoje`.
- Odstraneny legacy downtime sloupce z runtime SQL cest (manualni zapis, editace, importy, reporty) a navazujici fallbacky.
- Rozsirena podpora post-restore scenare: pridany patch `scripts/sql/schema_post_restore_v5.sql` a stejny blok vlozen do `schema.sql`.
- Doplneny validacni a servisni skripty pro kontrolu konzistence normalizovanych prostojovych dat.
- Release clean: srovnana dokumentace skriptu, sjednocene SQL validace/import hash logika a priprava schema dumpu pro cisty restore.
- Sjednocena hlavni dokumentace projektu na verzi `v5.0.6` (`README.md`, `MANUAL.md`, `LANGUAGES.md`, `DEPENDENCY_GRAPH.md`, `TESTING_ROLES.md`, `role_levels.md`, `scripts/README.md`).
- Zvysena aplikacni verze v `includes/config.php` na `5.0.6`.

## v5.0.5 (2026-04-29)

### Zmeny
- Pridano filtrovani v sekcich `admin/users.php`, `admin/pracovisite.php`, `admin/pracovnici.php`, `admin/nok_kody.php` a `admin/prostoj_typy.php`.
- Do importnich bloku ve vsech uvedenych sekcich pridano tlacitko `Vzor CSV` pro prime stazeni sablony.
- Pridany vzorove CSV soubory do `assets/templates/`:
	- `import_users_template.csv`
	- `import_pracovisite_template.csv`
	- `import_pracovnici_template.csv`
	- `import_nok_kody_template.csv`
	- `import_prostoj_typy_template.csv`
- Zvysena aplikacni verze v `includes/config.php` na `5.0.5`.

## v5.0.4 (2026-04-29)

### Zmeny
- Pridan `package.json` s metadaty projektu, licenci `MIT` a skriptem `release:check`.
- Pridan samostatny soubor `faq.md` se zakladnimi provoznimi dotazy.
- Pridan soubor `LICENSE` s textem licence MIT.
- Sjednoceny Markdown odkazy a struktura dokumentace v `MANUAL.md`, `README.md` a `scripts/README.md` pro korektni GitHub rendering.
- Zvysena aplikacni verze v `includes/config.php` na `5.0.4`.

## v5.0.3 (2026-04-29)

### Zmeny
- V `admin/users.php` pridano znovu-odeslani inicialniho mailu z prehledu uzivatelu pres modal s cilovym e-mailem.
- Po znovu-odeslani inicialniho mailu se uzivateli vygeneruje nove inicialni heslo, ulozi se hash do DB a `password_changed_at` se nastavi na `NULL` (stav hesla `Inicialni`).
- V editacnim modalu uzivatele pribyla rychla tlacitka `Resetovat heslo na inicialni` a `Znovu odeslat inicialni mail`.
- Doplneny prekladove klice pro novy modal a hlasky v `lang/cs.php`, `lang/en.php`, `lang/uk.php`.
- Zvysena aplikacni verze v `includes/config.php` na `5.0.3`.

## v5.0.2 (2026-04-28)

### Zmeny
- Pridany prepinace zobrazeni hesla do `admin/login.php`, `admin/profile.php`, `admin/users.php` a `admin/writer_users.php` vcetne sdileneho stylingu v `assets/css/main.css`.
- Upraven kiosk login v `admin/traceability_kiosk_login.php`: odstranena password toggle logika, pridano omezeni autofillu a neutralni pole `kiosk_access_code`.
- Opraveny text navigace Traceability ve vsech fallback prekladech (`lang/cs.php`, `lang/en.php`, `lang/uk.php`) na spravne znaceni spravy.
- Zpevnene audit logovani pro CLI/scheduler kontext v `includes/config.php` (`system` uzivatel, fallback bez `mb_substr`, zachyt `Throwable`).
- `scripts/sync_dvd_import.php` nyni zapisuje audit log primo i pro skip behy `source_unchanged`, aby audit odpovidal cron logu.
- Doplneny chybejici historicke audit zaznamy pro DVD sync skip behy z 28.04.2026.
- Zvysena aplikacni verze v `includes/config.php` na `5.0.2`.

## v5.0.1 (2026-04-28)

### Zmeny
- Pridana centralni mail helper vrstva `includes/mailer.php` pro SMTP (PHPMailer) i Outlook COM odesilani.
- Do `admin/scripts.php` pridany servisni testy mailu: SMTP test a Outlook test vcetne pole pro cilovy e-mail.
- Pridano odeslani inicialniho hesla pri vytvoreni noveho uzivatele v `admin/users.php` a `admin/writer_users.php`; e-mail je volitelny a neuklada se do DB.
- Outlook odesilani bylo opraveno pro vice-radkove telo mailu pomoci docasneho UTF-8 souboru, aby se neodesilal jen prvni radek.
- Sjednocen subject maileru: vsechny zpravy dostavaji prefix `[DVD Production]`.
- Aktualizovana sablona uvodniho mailu pro nove uzivatele.
- Opraveno sbalovani sidebar sekci v `assets/js/main.js` pro polozky s Bootstrap display utility tridami (napr. `d-flex`).
- V `includes/layout.php` odstranena duplicitni polozka `Týdenní výroba` z TV sekce a navigace pro `Týdenní výroba` je plne lokalizovana.
- V `admin/users.php` nahrazeno mazani uzivatele s inline `confirm()` za modal potvrzeni.
- Zvysena aplikacni verze v `includes/config.php` na `5.0.1`.

## v5.0.0 (2026-04-28)

### Zmeny
- Cleanup projektu po predchozich provoznich zasazich: odstranena redundantni samostatna deploy stranka `admin/release_deploy.php` a sjednocen release workflow do jedineho mista.
- `admin/scripts.php` rozsireno o samostatnou deploy sekci: vyber release tagu (`vX.Y.Z`) + tlacitko `Nasadit release`.
- Deploy akce z dashboardu nasazuje vybranou verzi do produkcniho rootu `C:/laragon/www` pomoci git checkout do work-tree.
- Soucasti deploy akce je povinny prepis produkcniho `APP_URL` v `C:/laragon/www/includes/config.php` na `http://LB100069/`.
- Pred prepisem produkcniho configu se automaticky vytvari timestamp zaloha `config.php.bak.Ymd_His`.
- Pridana validace deploy vstupu (existence tagu, git binarka, existence produkcniho rootu a configu, format tagu).
- Zachovana role ochrana: deploy je dostupny pouze pro `superadmin` v sekci `Skripty`.
- Aktualizovana dokumentace pro release/deploy workflow napric vsemi Markdown soubory.
- Zvysena aplikacni verze v `includes/config.php` na `5.0.0`.

## v4.6.0 (2026-04-24)

### Zmeny
- Pridana synchronizace IKOS pracovniku ze souboru `export_ikos.txt` (`scripts/sync_ikos_workers.php`) s pokrocilym kodovanim (UTF-8, Windows-1250, ISO-8859-2).
- Implementovana inkrementalni synchronizace se stavovym JSON souborem `uploads/tmp/sync_ikos_workers_state.json` a lock mechanismem.
- Pridana PowerShell planifikovac (`scripts/sync_ikos_workers_scheduler.ps1`) pro automaticke spusteni kazdou hodinu v minutě 01.
- Aktualizovan registracni skript Task Scheduleru (`scripts/register_scheduler_task.bat`): vsechny 3 syncy (Traceability, DVD, IKOS) nyni bezi na ONSTART bez uzivatelskeho prihlaseni (SYSTEM account).
- Zmena v `admin/pracovnici.php`: zobrazen cas posledni synchronizace IKOS pracovniku v topbaru pro superadmina (s moznosti vynucenosti syncu).
- Rozsirena role `admin` pro pristupu do `admin/traceability_terminal.php` na rovni `superadmin` (umoznuje aktivni zapis dat do traceability).
- Aktualizovana prvni-prihlaskova preusmereni (`admin/profile.php`): role `admin` nyni smeruje na traceability terminal.
- Pridany vypocet NOK procentualnich hodnot do KT tydenni vyroba (webova verze `admin/kt_report_all.php` a TV verze `admin/kt_report_all_tv.php`).
- Implementovano podminene zobrazeni NOK: radky se zobrazuji jen kdyz NOK >= 1 (skryvaji se nulove hodnoty).
- Pridana informace o akualnim kalendarnim tydnu (KT) do clock panelu vlevo dole: format `HH:MM:SS DD.MM.YYYY KT%cislo%`.
- Aktualizovana dokumentace skriptu: `scripts/README.md` s sekci "Synchronizace IKOS pracovniku".

## v4.7.0 (2026-04-23)

### Zmeny
- Sjednoceny dashboard KT reporty do jedne stranky `admin/kt_report_all.php` (Týdenní výroba).
- TV varianta sjednocena do `admin/kt_report_all_tv.php` s filtrem na aktivni pracoviste za poslednich 7 dni.
- V menu ponechana jedna polozka TV tydenni produkce; odstranena polozka `Denní výroba LH a EMV`.
- V dashboard verzi se nabidka pracovišť omezuje na ty, kde se v danem KT realne vyrabelo.
- Pridany per-user trvale presety filtru (JSON ulozeni v `users.kt_presets_json`) s lazy migraci sloupce.
- Presety nyni ukladaji pouze seznam vybranych pracovišť (bez KT a roku).
- Pridany preset workflow: vytvoreni/smazani pres dialogy, aplikace presetu pres POST.
- Preset chipy sjednoceny do jedne bubliny `%name% ×`.
- Zachovany pevne presety `KT report` a `KT report 2` jako nesmazatelne.
- Sjednocene nazvy reportu na `Týdenní výroba` ve vsech jazycich (cs/en/uk).
- Aktualizovana dokumentace: `README.md`, `MANUAL.md`, `LANGUAGES.md`, `DEPENDENCY_GRAPH.md`, `TESTING_ROLES.md`, `role_levels.md`, `scripts/README.md`.

## v4.5.1 (2026-04-22)

### Zmeny
- Odstraneny prefiky "Traceability" z menu sekce: Terminal, Kiosk, Report, Editor, Přehled, Uživatelé (čisčejší UI).
- Pod značkou verze v sidebaru zobrazen přihlášený uživatel s ikonou osob.
- Rozšířena správa Writer/Read uživatelů (`admin/writer_users.php`): nyní umožňuje editaci jak `writer`, tak `read` rolí s volbou role v modálním okně.
- Aktualizovány jazykové klíče pro nová menu v cs/en/uk.

## v4.5 (2026-04-21)

### Zmeny
- Pridana synchronizace DVD importu ze sdileneho souboru LBFILE01 (`scripts/sync_dvd_import.php`) se stavovym JSON souborem `uploads/tmp/sync_dvd_state.json`.
- Pridan fallback scheduler pro DVD sync kazdou sudou hodinu (`scripts/sync_dvd_import_scheduler.ps1`) vcetne cron logu a lock souboru.
- Opravena importni pipeline pro vkladani dat do `zapisy`: pred `PDO::execute()` se nyni filtrují jen SQL bind parametry (`importSqlBindParams`).
- Opraveno dohledani/vytvoreni `pracovisite` pri importu (inicializace `projekt`/`verze`) pro stabilni insert bez warningu.
- V reportu `admin/zaznamy.php` je v hlavicce zobrazen cas posledni DVD synchronizace; superadmin ma navic odkaz pro vynucene spusteni syncu.
- V `admin/oee.php` opraven textovy separator pracoviste na ` - ` (misto escapovane entity), aby se v UI nezobrazoval literal `ndash`.
- Rozsiren Traceability² report: v sekci pruchodu dilu pribyly sloupce uzivatelu pro 48w/48w200 a lokalizovany stav `nenalezeno`.
- Rozsirena dokumentace skriptu o popis DVD syncu a scheduleru (`scripts/README.md`).
- Rozsiren kiosk flow Traceability: fullscreen login -> vyber projektu -> produkcni scan stranka.
- Pridany scanner prikazy v kiosk rezimu: `project`, `editor`, `users`, `logout`, `resetpalety`, `cz`, `en`, `ua`, `light`, `dark`.
- Login kiosk nyni umi prikazy `cz/en/ua/light/dark` bez autentizace (jen prepnuti jazyka nebo tematu).
- Opraveno okamzite prepnuti jazyka a theme po scanner prikazu (bez dalsiho refresh).
- Pridany hard-focus guardy na inputy v kiosk login/project/scan pro scanner-first obsluhu.
- Na scan strance sjednocen dark/light switch s dashboard komponentou a pridano tlacitko odhlaseni vedle jazykove volby.
- Vypnuto audit logovani akce vlozeni nove traceability polozky (`TRACEABILITY_SCAN_INSERT`).
- Aktualizovana dokumentace kiosk rezimu v `README.md` a `MANUAL.md`.

## v4.1.2 (2026-04-20)

### Zmeny
- Hlavni formular (`index.php`) nově používá vyhledávací pole s našeptávačem pro výběr pracoviště (původní select byl odstraněn).
- Přidána režimová filtrace pracovišť podle tlačítek `Série` / `Filtry`.
- V režimu `Filtry` se v nabídce pracovišť zobrazují pouze položky s projektem `EMV Filter` a zároveň vždy položky obsahující `Ostatní` nebo `Šrot`.
- V režimu `Série` je dostupný kompletní seznam všech pracovišť.
- Při přepnutí režimu se neplatně zvolené pracoviště automaticky vyčistí, aby nebylo možné uložit záznam s nepovolenou kombinací typu a pracoviště.

## v4.1.1 (2026-04-20)

### Zmeny
- Filtrace v Traceability² report: přidáno zaškrtávací políčko „Jen SN začínající na T".
- Přidán CSV export z Traceability² reportu (respektuje všechny aktivní filtry).
- Přidána nová role uživatelů `traceability`: pouze čtení (Záznamy, OEE, TV, Traceability²).
- Po přihlášení: role `traceability` přesměrována na Traceability² report; ostatní role na hlavní formulář (`index.php`).
- Doplněny překladové klíče `role_display_traceability`, `role_label_traceability` ve všech 3 lang souborech a v DB `translations`.
- **Nové: Logika prvního přihlášení** – sloupec `password_changed_at` v tabulce `users`; noví uživatelé musí při prvním přihlášení změnit heslo na bezpečnější.
- **Nové: Vynucená změna hesla** – dokud je heslo iniciální (`password_changed_at IS NULL`), uživatel má přístup pouze na profil a odhlášení.
- **Nové: Správa uživatelů – sloupec stavu hesla** – zobrazuje indikátor (zelená = změněno, žlutá = iniciální). Tlačítko pro resetování hesla na iniciální stav (superadmin může vynutit změnu hesla při dalším loginu).
- **Nové: Traceability² report – průchod dílů 48w → 48w200** – nová sekce zobrazující SN, čas průchodu pozicí 48w a 48w200 (zelená barva = oba záznamy nalezeny, červená = 48w200 chybí).
- Optimalizace query pro průchod dílů: dva nezávislé derived table + LEFT JOIN místo korelovaného poddotazu.
- Přidány MySQL indexy: `48w.idx_sn_ts (snDil, timestamp)` a `48w200.idx_sn (snDil)` pro zrychlení reportů.
- Minimální délka hesla zvýšena na 12 znaků (bylo 5).

## v4.1.0 (2026-04-20)

### Zmeny
- Pridana admin stranka `admin/traceability.php` – report nad lokalni kopii databaze `traceability2` (prod. data filtrů z LB100021).
- Stranka obsahuje: statisticke karty, denni graf skenu (Chart.js), rozpad dle tabulky, top 20 operatoru, 100 poslednich skenu.
- Filtry: datum od–do, zdrojova tabulka, cislo SN, uzivatel (traceability2).
- Navigacni polozka `Traceability²` pridana do sidebaru (admin/superadmin).
- Pridany DB helpery `trace_db()`, `trace_db_row()`, `trace_db_all()`, `trace_db_val()` v `includes/config.php`.
- Hlavicka stranksy zobrazuje cas posledni synchronizace; pro superadmina je odkaz pro rucni spusteni syncu.
- Pojmenovani polozky v menu zmena na `Traceability²` (HTML `<sup>2</sup>`).
- Cronical job v Laragonu: sync traceability2 kazde pul hodiny (`0,30 * * * *`).
- Doplneny prekladove klice `nav_traceability`, `last_sync`, `sync_run_now`, `traceability_*` a `user` v cs/en/uk.
- Zaloha obou databazi (`dvd_vyroba` + `traceability2`) ulozena do `backupSQL/`.



### Zmeny
- Pridany skript `scripts/sync_traceability.php` pro prirustkovou i uplnou synchronizaci databaze `traceability2` ze serveru `LB100021` do lokalni MySQL kopie.
- Do superadmin/admin dashboardu `admin/scripts.php` pridana tlacitka pro prirustkovy a uplny sync Traceability2.
- Doplneny prekladove klice pro sync Traceability2 v `lang/cs.php`, `lang/en.php`, `lang/uk.php`.
- `includes/config.local.php.example` rozsiren o sablonu `TRACEABILITY_REMOTE_*` konfigurace.
- Do `backupSQL/` byl ulozen lokalni initial dump databaze `traceability2` pro manualni obnovu mimo git.
- Dokumentace aktualizovana v `README.md` a `scripts/README.md`.

## v4.0.1 (2026-04-20)

### Zmeny
- Pridan SQL validacni skript `scripts/sql/validate_zapisy_jmeno_format.sql` pro kontrolu formatu sloupce `zapisy.jmeno` (stredniky, koncove stredniky, vice mezer).
- Agregacni report `scripts/sql/validate_all.sql` nově spousti i validaci formatu jmen.
- Do superadmin dashboardu `admin/scripts.php` pridano tlacitko pro spusteni validace formatu jmen.
- Doplneny prekladove klice pro novy skript v `lang/cs.php`, `lang/en.php`, `lang/uk.php`.
- Dokumentace aktualizovana v `README.md` a `scripts/README.md`.

## v4.0.0 (2026-04-20)

### Zmeny
- Přejmenován changelog soubor z `versions.md` na `changelog.md`.
- Zvýšena verze aplikace na `4.0` v `includes/config.php`.
- Do README promítnuta nová verze (`v4.0`) a aktualizován stav release.
- Sidebar compact mode přepracován na stejný pill switch pattern jako přepínač light/dark tématu.
- Opravena funkčnost compact toggleru: robustní navázání změn (`change`) a fallback přes `onchange`.
- Přidáno cache-busting načítání `assets/css/main.css` a `assets/js/main.js` přes `?v=APP_VERSION`.
- Vyladěny barvy obou switcherů pro dark/light režim (theme-aware CSS proměnné), aby v dark režimu nepůsobil toggler světlý.
- V compact režimu se na ikonových položkách menu automaticky nastavuje `title` tooltip s názvem položky.
- Jazykový switcher ve zmenšeném sidebaru přepnut na vertikální skládání položek, aby nepřetékal mimo panel.
- V rozbaleném menu skryt text `Pouze ikony menu`; v compact režimu zůstává čisté ikonové UI.
- Opraveno tlačítko odhlášení ve zmenšeném sidebaru: nyní je vždy viditelné jako ikona a neztrácí se.
- Doplněny překladové klíče `menu_icons_only` v `lang/cs.php`, `lang/en.php`, `lang/uk.php`.
- `schema.sql` aktualizováno o seed překlady pro `menu_icons_only` (cs/en/uk).
- README doplněn o krok synchronizace překladů přes `php scripts/setup_languages.php`.
- Provedena inicializace jazykových tabulek skriptem `scripts/setup_languages.php` (3 jazyky, 2163 překladů).

## v3.6.5 (2026-04-20)

### Zmeny
- Pridana moznost zmensit sidebar menu pouze na ikony (compact mode) s ulozenim volby do localStorage.
- Doplneny prekladove klice `menu_icons_only` v `lang/cs.php`, `lang/en.php` a `lang/uk.php`.
- README doplnen o krok inicializace/prepisu jazyku pomoci `php scripts/setup_languages.php`.
- `schema.sql` aktualizovano o translation seed hodnoty pro klic `menu_icons_only` (cs/en/uk).

## v3.6.4 (2026-04-20)

### Zmeny
- Konfigurace DB hesla byla upravena tak, aby nebylo drženo v plaintext fallbacku v `includes/config.php`.
- Přidána podpora lokálního souboru `includes/config.local.php` (necommitovaný) a šablona `includes/config.local.php.example`.
- Do `.gitignore` přidáno `includes/config.local.php`.
- README aktualizováno na bezpečný postup konfigurace přes `config.local.php` nebo environment proměnné `DVD_DB_*`.

## v3.6.3 (2026-04-19)

### Zmeny
- Opravena globalni nedostupnost ikon napric projektem: CSP hlavička v `includes/config.php` nově povoluje `font-src` z `https://cdn.jsdelivr.net`, odkud se načítá Bootstrap Icons font.
- Doplnen maintenance skript `scripts/consolidate_pracovisite.php` pro konsolidaci duplicit pracovišť (presun vazeb `zapisy` na kanonicke ID, deaktivace duplicit, audit log).
- Opravena import logika v `includes/import_runtime.php`, aby nevytvářela nové duplicity pracovišť při rozdílech jen v projektu/verzi.
- Rozšířen admin dashboard skriptů o spuštění konsolidace pracovišť (`admin/scripts.php`) a doplneny prekladove klice v `lang/cs.php`, `lang/en.php`, `lang/uk.php`.
- V `admin/pracovisite.php` je seznam standardne filtrován na aktivní pracoviště s možností přepnutí na zobrazení všech.
- Z tabulky `pracovisite` byly odstraneny přebytečné neaktivní záznamy (`1984` řádků), po předchozí konsolidaci a ověření nulových referencí ze `zapisy`.
- Před mazáním byla vytvořena záloha databáze `backupSQL/backup_before_delete_inactive_pracovisite_2026-04-19_225654.sql`.

## v3.6.2 (2026-04-19)

### Zmeny
- Rozsireny uzivatelske role na `superadmin`, `admin`, `write`, `read`; nova role `admin` ma stejna opravneni jako puvodni `superadmin` v admin casti aplikace.
- Pridana superadmin-only dashboard stranka `admin/scripts.php` pro spousteni kontrolnich/validacnich skriptu a `setup_languages.php`.
- Sekce `admin/scripts.php` a polozka menu `Skripty` byly lokalizovany; nove prekladove klice jsou doplneny v `lang/*.php` a pres SQL skript `scripts/sql/upsert_scripts_translations.sql` i v tabulce `translations`.
- Do vstupniho formulare na indexu pribyla moznost vlozit vice pracovniku a pozic do jednoho zapisu smeny.
- Tabulka `pracovnici` byla vycistena od duplicit podle jmena a sprava/import pracovniku byly upraveny tak, aby stejne jmeno nevkladaly opakovane.
- Importy v `admin/import.php` a `scripts/auto_import_dvd.php` byly sjednoceny nad novou sdilenou vrstvou `includes/import_runtime.php`.
- Pri importu se nove dopocitavaji odvozene hodnoty, normalizuje OEE a automaticky se dohledava nebo vytvari odpovidajici `pracovisite`.
- Do `zapisy` byl doplnen `import_hash` a ochrana proti opakovanemu vlozeni stejnych importovanych radku.
- Pridany servisni SQL skripty `scripts/sql/cleanup_import_data.sql` a `scripts/sql/validate_import_data.sql` pro cleanup a kontrolu konzistence dat.
- Pridany servisni SQL skript `scripts/sql/cleanup_pracovnici_duplicates.sql` pro opakovatelny cleanup duplicitnich pracovniku.
- Pridany servisni SQL skript `scripts/sql/validate_pracovnici_data.sql` pro validacni report nad tabulkou pracovniku a jejich vazbami.
- Pridany agregacni SQL skript `scripts/sql/validate_all.sql` pro spusteni importniho i personalniho reportu v jednom behu.
- Nad stavajici databazi byl proveden cleanup importovanych dat: odstraneni duplicit, doplneni `pracovisite_id`, oprava `vyrobeno_dobre_ks` a normalizace `oee_procent`.

## v3.6.1 (2026-04-19)

### Zmeny
- Upraven iOS-like prepinac motivu (velikost, vzhled, korektni mapovani svetly/tmavy).
- V topbaru a sidebaru sjednoceno umisteni tlacitka Odhlasit napric admin strankami.
- Do spravy uzivatelu pridany import uzivatelu z CSV (vcetne validace a hashovani hesla).
- Do typu prostoju pridany import z CSV (vcetne validace, kodovani a prekladovych klicu).
- V postrannim menu upraveny cas a datum (stabilni vykresleni bez blikani na indexu).
- OEE dashboard doplnen o fallbacky pro importovana data bez detailniho rozpisu NOK/prostoju.
- TV mode upraven: denni widgety podle zvoleneho dne, tydenni prehled beze zmeny; pridana volba data + reset filtru tlacitkem Dnes.
- Zaktualizovana dokumentace: MANUAL.md, README.md, LANGUAGES.md, scripts/README.md a tento changelog.

## v3.6.0 (2026-04-19)

### Zmeny
- Pridana moznost prepinani mezi tmavym a svetlym rezimem v sidebaru.
- Volba motivu se uklada do localStorage (dvd_theme) a nacita pri startu aplikace.
- Doplneny nove prekladove klice theme_light a theme_dark pro cs/en/uk.
- schema.sql doplnen o nove translation seed hodnoty pro nove klice.
- Zvysena aplikacni verze na 3.6 v includes/config.php.

## v3.5.0 (2026-04-18)

### Zmeny
- Pridany RCH (Release Checklist Helper) skript scripts/release_check.php.
- Doplnen release postup a branch-protection doporuceni do scripts/README.md.
- Upraven RCH na standalone beh bez zavislosti na DB bootstrapu.

### Commity
- 8beb723 Make RCH standalone without DB bootstrap
- d6c152c Add RCH release checklist helper

## v3.4.0 (2026-04-18)

### Zmeny
- Inicializace repozitare a prvni verzovaci snapshot projektu.
- Cisteni repozitare od docasnych upload souboru.
- Pridani .gitignore a uploads/tmp/.gitkeep pro stabilni verzovani.

### Commity
- 6bc362f Ignore temporary upload artifacts
- 3819f33 Initial private versioning snapshot

## pre-git (do 2026-04-18, pred prvnim push)

### Poznamka
- Tato cast je zpetna rekonstrukce podle dostupnych podkladu.
- Presne poradi a granularita jednotlivych kroku pred zavedenim gitu nemusi byt 100%.

### Rekonstruovane zmeny
- Opraven import Excelu v adminu: vyber funkcniho Python interpreteru na Windows.
- Opraven parsing vystupu Pythonu: normalizace kodovani (Windows-1250 -> UTF-8) pred json_decode.
- Opraveno vkladani importovanych radku do DB: filtrovani pouze SQL parametru (: klice).
- Rozsiren import na podporu CSV v includes/import_excel.py i v UI importu.
- Pridana CSV sablona assets/templates/import_template.csv a odkaz ke stazeni v import formulari.
- Opraven import pracovniku z CSV: BOM + CP1250/ISO-8859-2 -> UTF-8.
- Pridan webovy auto-import ze sdileneho souboru DVD-2026.xlsx (scripts/auto_import_dvd.php).
- Auto-import doplnen o ochrany: superadmin-only (web) a denni lock s force prepinacem.
- Do import stranky pridana karta auto-importu a prehled posledniho behu.
- Do lang/cs.php, lang/en.php, lang/uk.php doplneny nove klice pro import/auto-import.
- Do schema.sql promitnuty nove/aktualizovane prekladove klice souvisejici s importem.

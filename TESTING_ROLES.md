# Testovací plán oprávnění rolí

Verze test planu: v5.6.6

## NOK matice
- Otevřít správu NOK bez výběru a ověřit povinný modal; matice se před výběrem nevykreslí.
- Vyhledat pracoviště podle AFO, kódu i názvu a ověřit správné filtrování seznamu.
- Vybrat 1 až 5 různých AFO; šestá volba se musí zablokovat.
- Ověřit, že se vykreslí jen zvolené sloupce a po uložení zůstane výběr zachovaný.
- Změnit hodnotu ve vybraném AFO a ověřit, že nastavení nevybraných pracovišť zůstane stejné.
- Ověřit, že modal při filtrování seznamu nemění výšku a tlačítko Přestavět z Excelu není viditelné.

## Hlavní formulář a tisk
- Vybrat pracoviště, začít měnit text a ověřit vymazání skrytého ID i předvyplněných údajů.
- Po zrušení výběru spustit přípravu tisku a ověřit, že tiskopis neobsahuje údaje předchozího pracoviště.
- Ověřit, že NOK položky bez odpovídající AFO mapy nejsou zobrazeny.
- Znovu vybrat platné pracoviště a ověřit správné údaje na obou stranách tiskopisu.

## Přehled záznamů
- Nastavit filtry, otevřít detail nebo editaci záznamu a vrátit se; filtry mají zůstat zachované.
- Použít Reset a ověřit, že uložené filtry jsou vymazány.

## Import pracovníků
- Ověřit pořadí CSV `interni_id;jmeno;ukonceni;smennost;role` a import volného textu role bez whitelistu.
- Ověřit prázdnou i chybějící hodnotu role: v obou případech se musí uložit `D`.
- Ověřit, že duplicate review zachová roli vybraného řádku a full-replace import uloží roli pro každé interní ID.
- Ověřit, že IKOS refresh ponechá role existujících pracovníků a nové pracovníky založí s rolí `D`.
- Ověřit CSV se 3 i 4 sloupci; prázdné nebo neplatné datum ukončení se nesmí interpretovat jako budoucí.
- Ověřit filtry osobního čísla, jména, směnnosti, třídy a stavu zaměstnání.
- Ověřit řazení každého datového sloupce v cyklu ASC → DESC → vypnuto a zachování filtrů.
- Ověřit, že duplicity se zobrazí v modalu a u každé skupiny lze vybrat jednoho kandidáta nebo žádného.
- Ověřit automatický skip duplicitní skupiny pouze tehdy, když mají všichni kandidáti datum ukončení před dneškem.
- Ověřit, že formulář `index.php` nenabízí zaměstnance s dnešním, minulým nebo chybějícím datem ukončení.

## Přihlášení

### Simulátor rolí
- Přihlásit se jako skutečný `superadmin` a otevřít `admin/scripts.php`.
- Zvolit každou roli a ověřit, že sidebar odpovídá stejným permission podmínkám jako skutečný účet.
- Při simulaci `fsk_print` ověřit Nový zápis, tisk bez pracoviště, profil, jazyk, téma a odhlášení.
- Při simulaci `writer` ověřit Traceability menu, Nový zápis, read-only tisk a nemožnost odeslat výrobní data.
- Ověřit zobrazení menu pro `read`, `write`, `rework`, `serizovac` a `traceability` proti `includes/layout.php`.
- Ověřit, že během simulace jsou skripty, release a deploy akce skryté a POST volání serverově odmítnuté.
- Kliknout na Scripty a simulaci ukončit bez odhlášení; původní superadmin menu se musí obnovit.
**Heslo pro všechny role**: `InitAdmin123+`

---

## Role a testovací uživatelé

### 1. `superadmin_test` - Super administrátor
Nejvyšší oprávnění, má přístup ke všemu.

#### Očekávané přístupy:
- ✅ Menu: Nový zápis → viditelný a funkční
- ✅ Menu: Záznamy/OEE → viditelný a funkční
- ✅ Menu: Záznamy a dashboardy → Týdenní výroba
- ✅ Menu: Traceability → Traceability report
- ✅ Menu: Traceability → Traceability editor
- ✅ Menu: Traceability → Traceability přehled
- ✅ Menu: TV módy → TV (součást menu)
- ✅ Menu: TV módy → Traceability TV
- ✅ Menu: Správa → Users, Pracoviště, Pracovníci, NOK kódy, Prostoje, Import, Překlady, Audit log
- ✅ Menu: Správa → Scripts (pouze superadmin!)
- ✅ Menu: Profil

#### Formuláře a akce:
- ✅ Tvorba nového zápisu: **MOŽNÉ**
- ✅ Editace zápisu: **MOŽNÉ**
- ✅ Traceability editor - čtení: **MOŽNÉ**
- ✅ Traceability editor - editace: **MOŽNÉ**
- ✅ Traceability terminál - skenování: **MOŽNÉ**
- ✅ Traceability terminál - reset: **MOŽNÉ**
- ✅ Scripts stránka: **VIDITELNÁ**
- ✅ Scripts -> Deploy release sekce: **VIDITELNÁ A FUNKČNÍ**

#### Test:
1. Přihlášení jako `superadmin_test`
2. Ověřit, že je viditelná složka **Správa** v menu
3. Kliknout na **Správa > Scripts** → měla by být viditelná
4. Přejít na **Traceability > Terminal** → měl by vidět tlačítka pro skenování
5. Ověřit, že může editovat všechny položky
6. Otevřít `Správa > Scripts`, vybrat release tag a ověřit dostupnost tlačítka `Nasadit release`

---

### 2. `admin_test` - Administrátor
Téměř stejné právo jako superadmin, **MIMO Scripts stránka**.

#### Očekávané přístupy:
- ✅ Menu: Nový zápis → viditelný a funkční
- ✅ Menu: Záznamy/OEE → viditelný a funkční
- ✅ Menu: Záznamy a dashboardy → Týdenní výroba
- ✅ Menu: Traceability → všechny subsekce
- ✅ Menu: TV módy → viditelné
- ✅ Menu: Správa → Users, Pracoviště, Pracovníci, atd. (bez Scripts)
- ❌ Menu: Správa > Scripts → **SKRYTÁ nebo chyba 403**
- ✅ Menu: Profil

#### Formuláře a akce:
- ✅ Tvorba zápisu: **MOŽNÉ**
- ✅ Editace zápisu: **MOŽNÉ**
- ✅ Traceability editace: **MOŽNÉ**
- ✅ Traceability terminál - skenování: **MOŽNÉ** (přístup má, ale mohou být omezení)
- ❌ Traceability terminál - reset: **SKRYTÁ/zakázána** (vidí read-only mód)

#### Test:
1. Přihlášení jako `admin_test`
2. Jít do menu **Správa** → **Scripts** by neměla být viditelná
3. Pokus o přímý přístup: `admin/scripts.php` → měla by vrátit chybu 403
4. Jít na **Traceability > Terminal** → měl by vidět warning, že nemůže skenovat

---

## Doplňující test deploy workflow (superadmin)

1. Přihlášení jako `superadmin_test`.
2. Otevřít `admin/scripts.php`.
3. Najít sekci **Deploy release**.
4. Ověřit, že je vidět selector release tagů a tlačítko `Nasadit release`.
5. Provést deploy testovacího tagu a ověřit stavový výstup `OK`/`ERR`.
6. Ověřit, že v produkčním configu je `APP_URL` nastaveno na `http://10.67.100.69/`.

---

### 3. `write_test` - Operátor (formulář)
Pracovník, který vyplňuje **hlavní formulář** (nový zápis). Má přístup na stránku "Nový zápis".

#### Očekávané přístupy:
- ✅ Menu: Nový zápis → viditelný a funkční
- ❌ Menu: Záznamy/OEE → **SKRYTÁ**
- ❌ Menu: Traceability → **SKRYTÁ**
- ❌ Menu: TV módy → **SKRYTÁ**
- ❌ Menu: Správa → **SKRYTÁ**
- ✅ Menu: Profil

#### Formuláře a akce:
- ✅ Vyplnění nového zápisu: **MOŽNÉ**
- ✅ Editace vlastního zápisu: **LIMITOVANÉ** (jen svého?)
- ❌ Čtení Traceability: **ZAKÁZÁNO**
- ❌ Editace Traceability: **ZAKÁZÁNO**
- ❌ Přístup do Admin sekce: **ZAKÁZÁNO**

#### Test:
1. Přihlášení jako `write_test`
2. Domovská stránka → měla by zobrazit jen "Nový zápis"
3. Pokus o přímý přístup: `admin/zaznamy.php` (OEE) → chyba 403
4. Pokus o přístup: `admin/traceability.php` → chyba 403
5. Ověřit, že může vyplnit formulář v "Nový zápis"

---

### 4. `read_test` - Čtenář (čtení)
Obecný čtenář. Vidí všechny reporty a data, ale **NEMŮŽE editovat**.

#### Očekávané přístupy:
- ❌ Menu: Nový zápis → **SKRYTÁ**
- ✅ Menu: Záznamy/OEE → viditelný, **jen čtení**
- ✅ Menu: Záznamy a dashboardy → Týdenní výroba (jen čtení)
- ✅ Menu: Traceability → viditelný, **jen čtení**
- ✅ Menu: TV módy → viditelný, **jen čtení**
- ❌ Menu: Správa → **SKRYTÁ**
- ✅ Menu: Profil

#### Formuláře a akce:
- ❌ Tvorba zápisu: **ZAKÁZÁNO**
- ❌ Editace zápisu: **ZAKÁZÁNO**
- ✅ Čtení OEE/Záznamů: **MOŽNÉ**
- ✅ Čtení Traceability reportu: **MOŽNÉ**
- ❌ Editace Traceability: **ZAKÁZÁNO**
- ❌ Terminál: **ZAKÁZÁNO**

#### Test:
1. Přihlášení jako `read_test`
2. Přístup do **Záznamy/OEE** → mělo by být viditelné, ale bez tlačítka pro editaci
3. Pokus o klik na "Edit" → měla by být tlačítka zakázána/skrytá
4. Přístup do **Traceability > report** → mělo by být viditelné bez editace
5. Přístup do **Traceability > Terminal** → mělo by být readonly

---

### 5. `traceability_test` - Traceability čtenář
Specialista na Traceability, ale **jen čtení**. Vidí Traceability a OEE, ale nemůže editovat.

#### Očekávané přístupy:
- ❌ Menu: Nový zápis → **SKRYTÁ**
- ✅ Menu: Záznamy/OEE → viditelný, **jen čtení**
- ✅ Menu: Záznamy a dashboardy → Týdenní výroba (jen čtení)
- ✅ Menu: Traceability → viditelný, **jen čtení**
- ✅ Menu: TV módy → viditelný
- ❌ Menu: Správa → **SKRYTÁ**
- ✅ Menu: Profil

#### Formuláře a akce:
- ❌ Tvorba zápisu: **ZAKÁZÁNO**
- ✅ Čtení OEE/Záznamů: **MOŽNÉ**
- ✅ Čtení Traceability: **MOŽNÉ**
- ❌ Editace Traceability: **ZAKÁZÁNO**
- ❌ Terminál skenování: **ZAKÁZÁNO**

#### Test:
1. Přihlášení jako `traceability_test`
2. Přístup do **Traceability > Editor** → mělo by být viditelné, ale v read-only módu
3. Pokus o editaci → měla by být formuláře zakázána
4. Přístup do **Traceability > Terminal** → mělo by být viditelné, ale bez možnosti skenovat
5. Přístup do **Správa** → měla by vrátit chybu 403

---

### 6. `writer_test` - Traceability editor
Edituje pouze Traceability. **NEMÁ přístup** na hlavní formulář (Nový zápis) nebo OEE.

#### Očekávané přístupy:
- ❌ Menu: Nový zápis → **SKRYTÁ**
- ❌ Menu: Záznamy/OEE → **SKRYTÁ**
- ✅ Menu: Traceability → viditelný
- ✅ Menu: TV módy → viditelný
- ❌ Menu: Správa → **SKRYTÁ**
- ✅ Menu: Profil

#### Formuláře a akce:
- ❌ Tvorba zápisu (hlavní formulář): **ZAKÁZÁNO**
- ❌ Čtení OEE: **ZAKÁZÁNO**
- ✅ Čtení Traceability: **MOŽNÉ**
- ✅ Editace Traceability: **MOŽNÉ**
- ❌ Terminál skenování: **ZAKÁZÁNO** (jen čtení)
- ❌ Správa: **ZAKÁZÁNO**

#### Test:
1. Přihlášení jako `writer_test`
2. Domovská stránka → měla by vidět jen Traceability + TV módy
3. Přístup do **Traceability > Editor** → měla by být možná editace
4. Pokus o přístup: `admin/zaznamy.php` (OEE) → chyba 403
5. Pokus o formulář "Nový zápis" → přístup zakázán
6. Přístup do **Traceability > Terminal** → read-only

---

## Shrnutí

| Role | Nový zápis | Záznamy/OEE | Traceability (edit) | TV módy | Správa | Terminal (scan) |
|---|---|---|---|---|---|---|
| **superadmin** | ✅ | ✅ | ✅ | ✅ | ✅ (+ Scripts) | ✅ |
| **admin** | ✅ | ✅ | ✅ | ✅ | ✅ (bez Scripts) | ⚠️ (jen čtení) |
| **write** | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ |
| **read** | ❌ | ✅ (čtení) | ✅ (čtení) | ✅ | ❌ | ❌ |
| **traceability** | ❌ | ✅ (čtení) | ✅ (čtení) | ✅ | ❌ | ❌ |
| **writer** | ❌ | ❌ | ✅ (edit) | ✅ | ❌ | ⚠️ (jen čtení) |

Poznamka k TV modum ve v4.7.0:
- V menu je pouze jedna tydenni polozka vyroby (`Týdenní výroba`), puvodni polozka `Denní výroba LH a EMV` byla odstranena.

---

## Poznámky k testování

### Přístup na chráněné stránky
- Pokud role nemá přístup, měl by vidět chybu **403 Forbidden** nebo být přesměrován na domovskou stránku
- Menu položky by měly být skryté (nikoli viditelné, ale deaktivované)

### Zápisy (formulář)
- Pole "Zápis" by měla být dostupná, ale tlačítko "Uložit" by mělo být zakázáno

### TV obrazovky
- `admin/tv.php` a `admin/traceability_tv.php` jsou **veřejné** (bez loginu)
- Ale v rámci přihlášených uživatelů by se měly zobrazovat v menu

### Traceability terminál
- Přístup na stránku mají víc rolí
- **Zápis (skenování) a Reset** jsou pouze pro `superadmin`
- Ostatní role vidí read-only mód s warning banerem

---

Vytištěné uživatelské jméno a heslo by měly být **bezpečně distribuovány testovacímu týmu**.

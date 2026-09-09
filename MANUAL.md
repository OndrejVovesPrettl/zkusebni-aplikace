# DVD Sledování výroby – Uživatelský manuál (v5.4.0)

## Poznamka k verzi v5.4.0

- Release `v5.4.0` sjednocuje aktuální stav aplikace, dokumentaci a produkční metadata pro finální verzi `v3.4`.
- Uzivatelske ovladani v aplikaci zustava stejne; zmena je primarne technicka synchronizace chovani mezi vetvemi.

## Obsah
1. [Přehled aplikace](#1-přehled-aplikace)
2. [Přihlášení](#2-přihlášení)
3. [Mobilní a malá zařízení](#3-mobilní-a-malá-zařízení)
4. [Hlavní formulář – Krok za krokem](#4-hlavní-formulář--krok-za-krokem)
5. [Jednotlivé sekce](#5-jednotlivé-sekce)
6. [Keyboard zkratky](#6-keyboard-zkratky)
7. [Tipy a triky](#7-tipy-a-triky)
8. [Traceability Kiosk](#8-traceability-kiosk)
9. [Traceability Terminál](#9-traceability-terminál)
10. [Přehled záznamů a export](#10-přehled-záznamů-a-export)
11. [OEE Dashboard](#11-oee-dashboard)
12. [Týdenní výroba](#12-týdenní-výroba)
13. [TV Mode](#13-tv-mode)
14. [Traceability2](#14-traceability2)
15. [Číselník směn (Správa -> DVD)](#15-číselník-směn-správa---dvd)
16. [Správa uživatelů a CSV import](#16-správa-uživatelů-a-csv-import)
17. [Deploy release do produkce](#17-deploy-release-do-produkce)
18. [Uživatelský manuál v aplikaci](#18-uživatelský-manuál-v-aplikaci)
19. [FAQ](#19-faq)

---

## 1. Přehled aplikace

Aplikace DVD slouží k **zaznamenávání výrobních dat** během pracovní směny. Umožňuje:
- Zaznamenání vyrobených kusů, NOK, prostojů
- Automatický výpočet OEE (dostupnost × výkon × kvalita)
- Historii záznamů a analýzu výroby
- Přehled na TV displeji pro management

**Hlavní obrazovka** – formulář s 10 sekcemi:
```
01. Identifikace
02. Směna
03. Pracovník
04. Výroba
05. Plánované prostoje
06. Neplánované prostoje
07. Technické prostoje
08. NOK záznamy
09. Hodinové sledování
10. OEE Preview
```

---

## 2. Přihlášení

### První přihlášení

1. Otevřete aplikaci
2. Pokud nejste přihlášeni, klikněte na **"Přihlásit se"** (pravý horní roh)
3. Zadejte přihlašovací údaje:
4. Pokud je heslo iniciální, systém vás pustí pouze na stránku **Můj profil**.
5. Dokud heslo nezměníte, ostatní stránky administrace nejsou dostupné.
6. Po uložení nového hesla se odemkne standardní přístup podle vaší role.


> Pokud vidíte **"Přístup omezen"**, nemáte práva pro zápis. Kontaktujte administrátora.

---

## 3. Mobilní a malá zařízení

Aplikace je **plně optimalizovaná pro mobilní telefony, tablety a malé displeje**.

### Responsivní design

Aplikace se automaticky přizpůsobuje velikosti obrazovky:

| Velikost | Popis | Optimalizace |
|----------|-------|--------------|
| **Počítač (>768px)** | Plná navigace, sidebar, všechny prvky | Standardní zobrazení |
| **Tablet (600–768px)** | Možnost skrytí sidebaru, zmenšené fonty | Optimální pro landscape režim |
| **Mobilní telefon (<600px)** | Kompaktní layout, skrytá sidebar hlavně | Vertikální režim |
| **Velmi malé telefony (<480px)** | Minimální design, pouze essentials | Malé obrazovky, slabá připojení |

### Mobilní tipy

1. **Plné obrazovky** – Otočte telefon na landscape pro větší prostor
2. **Touch-friendly** – Všechna tlačítka a pole mají minimální velikost 44×44 px
3. **Kolapsovatelné sekce** – Klikněte na nadpis sekce pro rozbalení/sbalení
4. **Prostoje bez labelu** – Na mobilu se zobrazuje jen kód a input pole (label je skryt)
5. **Automatické scrollování** – Snadnější navigace přes formulář

### Příklad na mobilním telefonu

```
┌─────────────────────────────────┐
│ ☰ Formulář         🖨  📋    │ ← Topbar (compact)
├─────────────────────────────────┤
│                                 │
│ ⚙ SÉRIE ⚡ FILTRY          │ ← Typ-switcher (stackovaný)
│                                 │
│ ┌─ 01 Identifikace ─────────┐ │
│ │ Pracoviště *    [↓]        │ │
│ │ Zakazník        —          │ │
│ │ Verze           —          │ │
│ └────────────────────────────┘ │
│                                 │
│ ┌─ 05 Plánované prostoje ───┐ │
│ │ P1  [15]               │ │
│ │ P2  [0]                │ │
│ │ O1  [30] Lékař…   │ │
│ └────────────────────────────┘ │
│                                 │
│      [Reset]  [Uložit zápis] │
└─────────────────────────────────┘
```

### Známé omezení

- Na velmi malých obrazovkách (< 320px) se mohou některá pole překrývat
- Komplexní tabely (Záznamy, OEE) jsou na mobilu obtížnější – doporučujeme počítač
- TV mode není optimalizován pro telefony



## 4. Hlavní formulář – Krok za krokem

### Příprava formuláře (Sekce 01 – 02)

1. **Zvolte typ výroby** (horní tlačítka):
   - **SÉRIE** – standardní sériová výroba
   - **FILTRY** – EMV filtry (v nabídce pracovišť uvidíte jen `EMV Filter` + `Ostatní`/`Šrot`)

2. **Zaměřte se na Sekci 01 – Identifikace**:
   - Začněte psát do pole **Pracoviště** a vyberte položku z našeptávače (povinné *)
   - Režim `SÉRIE`: dostupná všechna pracoviště
   - Režim `FILTRY`: dostupný jen projekt `EMV Filter` + položky `Ostatní`/`Šrot`
   - Ostatní údaje se doplní automaticky:
     - Zákazník, Projekt, Verze, AFO, NOK středisko
     - Názvů pracoviště, dílu, SAP číslo

3. **Sekce 02 – Směna**:
   - Zadejte **Datum** (povinné *)
   - Zvolte **Směnu** (R8, O8, N8, R12, N12)
   - Časy se doplní automaticky – upravte je dle potřeby
   - **Pracovní doba** se vypočítá sama z příchodu/odchodu

4. **Poznámka zapisujícího** – volitelné, pro speciální poznámky

### Pracovník (Sekce 03)

1. Vyberte pracovníka **ze seznamu** (pokud je v systému)
2. Nebo zadejte **ručně** (Příjmení Jméno)
3. **Pracovní pozice** – Operátor, Seřizovač, Mistr, Jiná
4. **Zapsal** – Auto-vyplní se jménem přihlášeného uživatele (needitovatelné)

### Výroba (Sekce 04)

1. Zadejte **Vyrobeno** kusů (povinné *)
2. **NOK kusů** – nedobré kusy
3. **K posouzení** – kusy na kontrolu
4. Zaškrtněte speciální značky (Tech, Blok, TNZ)
5. Volitelná **Poznámka**

> Počet **OK kusů** se vypočítá automaticky: Vyrobeno − NOK

### Prostoje (Sekce 05 – 06)

#### Plánované prostoje (P-kódy)
Zadejte minuty pro:
- P1–P10: Různé činnosti (změna výroby, rozjezd, školení, atd.)
- O1: Lékař, nevolnost

#### Neplánované prostoje
Skupiny:
- **Logistické (L)** – Chybí materiál, čekání
- **Výrobní (V)** – Org. problémy, chybí pracovník, seřízení
- **Kvalitativní (Q)** – Nekvalitní materiál, sorting, uvolnění

### Technické prostoje (Sekce 07)

1. Klikněte na **"+ Přidat prostoj"**
2. Zvolte typ prostoje (dropdown)
3. Zadejte:
   - Čas od/do
   - Trvání v minutách
   - Počet záznamů (1, 2, atd.)
   - Poznámka (volitelně)
4. Smazání: klikněte na ikonu **🗑**

### NOK záznamy (Sekce 08)

1. Vyhledejte relevantní NOK kód
2. Zadejte počet kusů s danou chybou
3. Kódy jsou seskupeny:
   - Manipulace, Materiál, Plast, Kov, Drát, Montáž, Svár, Testy, Busbar, Kondenzátor, Ventil, CAPA, Ostatní

> Badge v sekci 08 ukazuje **celkový počet NOK kusů**

### Hodinové sledování (Sekce 09)

Zadejte pro každou hodinu (06:00–06:00 příští den):
- **Vyrobeno** – kusy za hodinu
- **P minuty** – plánované prostoje
- **T minuty** – technické prostoje

### OEE Preview (Sekce 10)

Zde vidíte automatické výpočty v reálném čase:
- **Dostupnost (A)** – podíl čistého času na dostupné časy
- **Výkon (P)** – kolik procent normy (norma_ks_min)
- **Kvalita (Q)** – (vyrobeno − nok) / vyrobeno
- **OEE** = A × P × Q
- **Cíl**: 85% (zelená = OK, oranžová = málo, červená = kriticky)

---

## 5. Jednotlivé sekce

### Sekce 01 – Identifikace ⚙️

**Co vyplňuji:**
- Pracoviště (povinné, přes vyhledávací pole)
- Ostatní se doplní samo

**Pravidla podle typu výroby:**
- SÉRIE: zobrazuje všechna pracoviště
- FILTRY: zobrazuje pouze projekt `EMV Filter` + položky `Ostatní`/`Šrot`

**Podpůrné údaje:**
- Zakazník – správní údaje
- Projekt – označení projektu
- Verze – verze přípravy
- AFO – identifikace
- Nákladové středisko
- Názvů dílu / SAP číslo

**Poznámka zapisujícího** – pro speciální situace

---

### Sekce 02 – Směna 🕐

**Povinné:**
- Datum (default = dnešek)
- Směna (R8, O8, N8, R12, N12)

**Automatické výpočty:**
- **KT (Kalendářní týden)** – ISO týden
- **Čistý čas** = Pracovní doba − Pauza
- Vše se mění podle příchodu/odchodu

**Příklad:**
```
Smena: O8 (Odpolední 8h)
Příchod: 14:00 → Odchod: 22:00
Pauza: 30 min
Čistý čas: 450 min (7h 30m)
```

---

### Sekce 03 – Pracovník 👤

**Automatické:**
- **Zapsal** – jméno přihlášeného uživatele (read-only)

**Vybírám:**
- Pracovníka ze seznamu (pokud je zapsaný)
- Nebo ručně zadám jméno
- Pracovní pozici (Operátor, Seřizovač, atd.)

---

### Sekce 04 – Výroba 📦

**Povinné:**
- Vyrobeno (počet kusů)

**Automatické:**
- **OK kusů** = Vyrobeno − NOK

**Speciální značky:**
- **Tech** – Technická změna (zaškrtnu, pokud se provádí)
- **Blok** – Chybí komponenta, výroba zablokovaná
- **TNZ** – Testování, není žádoucí

---

### Sekce 05 – Plánované prostoje 📅

Směny mají **plánované pauzy a prostoje**:

| Kód | Popis | Příklad |
|-----|-------|---------|
| P1 | Změna verze | 15 min |
| P2 | Rozjezd linky | 20 min |
| P3 | Vzorkování | 10 min |
| P4 | Údržba | 30 min |
| P5 | Schůzka, školení | 45 min |
| P6 | Školitel | 60 min |
| P7 | Zaškolovaný | 40 min |
| P8 | Změna pracoviště | 10 min |
| P9 | Inventura | 20 min |
| P10 | Plánovaná odstávka | 60 min |
| O1 | Lékař, nevolnost | 30 min |

---

### Sekce 06 – Neplánované prostoje ⚠️

Rozděleny do tří skupin:

#### 🚚 Logistické (L)
- **L1** – Chybí materiál na skladě (čekání na doplnění)
- **L2** – Čekání na manipulanta

#### ⚙️ Výrobní (V)
- **V1** – Org. problémy (chybí pracovník)
- **V2** – Chybí materiál od předchozího pracoviště
- **V3** – Seřízení linky

#### 🔍 Kvalitativní (Q)
- **Q1** – Nekvalitní vstupní materiál
- **Q2** – Změna šarže (požadavek kvality)
- **Q3** – Sorting (třídění)
- **Q4** – Uvolnění výroby

**Příklad:**
```
Krátili jsme 45 minut za kvalitativní problem → Q1: 45 min
```

---

### Sekce 07 – Technické prostoje 🔧

Technické výpadky linky.

**Postup:**
1. Klikněte **"+ Přidat prostoj"**
2. Vyberte typ (T1–T22)
3. Čas od/do nebo trvání v minutách
4. Počet záznamů (např. kvůli vícenásobným výpadům)
5. Poznámka (např. "Ucpané tryskylo")

**Je tam 22 typů technických prostojů** (překladač, dopravník, elektrika, atd.)

---

### Sekce 08 – NOK záznamy ❌

Zaznamenáte všechny chyby a vady.

**Kategorie:**
- Manipulace, Materiál
- Plast, Kov, Drát
- Montáž, Svár
- Testy, Busbar, Kondenzátor
- Ventil, CAPA
- Ostatní

**Příklad:**
```
Našli jsme 3 chyby v montáži a 2 ve sváru
→ Montáž: 3
→ Svár: 2
```

> **Badge** v hlavičce = Celkový počet NOK kusů

---

### Sekce 09 – Hodinové sledování 📊

Volitelné: Zadejte výrobu a prostoje **pro každou hodinu**.

Každá hodina má 3 buňky:
- **Ks od** – vyrobené kusy
- **P minuty** – plánované prostoje
- **T minuty** – technické prostoje

```
06:00–07:00: Vyrobeno 45 ks, P=0, T=5
07:00–08:00: Vyrobeno 52 ks, P=15, T=0
```

---

### Sekce 10 – OEE Preview 📈

**Sledování výkonnosti v reálném čase**

**Složení OEE:**
```
OEE = Dostupnost (A) × Výkon (P) × Kvalita (Q)
```

| Metrika | Vysvětlení | Vzorec |
|---------|-----------|--------|
| **A – Dostupnost** | Kolik procent času je linka k dispozici | Čistý čas / Netto čas |
| **P – Výkon** | Kolik dosáhnu normy | Vyrobeno / (Čistý čas / Norma) |
| **Q – Kvalita** | Procento DobrýchKusů | (Vyrobeno − NOK) / Vyrobeno |
| **OEE** | Celkový výkon | A × P × Q |

**Barvy:**
- 🟢 **Zelená** – ≥85% (Cíl dosažen)
- 🟡 **Oranžová** – 70–84% (Pod cílem)
- 🔴 **Červená** – <70% (Kriticky nízko)

**Další info:**
- Norma KS/min
- Cycle time (minuty)
- Shrnutí (celkový přehled)

---

## 6. Keyboard zkratky

| Zkratka | Akce |
|---------|------|
| **Ctrl+Alt+Enter** (2× stisk) | 1. otevře potvrzovací dialog, 2. uloží záznam |
| **Enter** v textovém poli | Přesun na další pole (ne odeslání) |
| Tab | Standard. přechod mezi poli |

---

## 7. Tipy a triky

### Urychlení vyplňování

---

## 8. Traceability Kiosk

Traceability Kiosk je určen pro scanner terminál a má pevný průchod:

1. Přihlášení heslem na `admin/traceability_kiosk_login.php`
2. Výběr projektu na `admin/traceability_kiosk_project.php`
3. Produkční scan na `admin/traceability_kiosk_scan.php`

### Chování pro scanner provoz

- Input je automaticky focusnutý po načtení stránky.
- Pokud input ztratí focus klikem mimo ovládací prvky, focus se automaticky vrátí.
- Přepnutí jazyka a light/dark režimu se provádí okamžitě v aktuálním requestu.

### Příkazy v kiosk inputu

#### Přihlášení (`traceability_kiosk_login.php`)

- `cz`, `en`, `ua` -> přepnutí jazyka bez přihlášení.
- `light`, `dark` -> přepnutí tématu bez přihlášení.
- Jakýkoli jiný vstup -> standardní pokus o přihlášení heslem.

#### Výběr projektu (`traceability_kiosk_project.php`)

- `<nazev_projektu>` -> výběr projektu a přechod na scan stránku.

#### Produkční scan (`traceability_kiosk_scan.php`)

- `project` -> návrat na výběr projektu.
- `editor` -> otevře editor záznamů (jen uživatel s právy >= 90).
- `users` -> otevře správu uživatelů (jen uživatel s právy >= 100).
- `logout` -> odhlášení kiosku.
- `resetpalety` -> reset počítadla palety.
- `cz`, `en`, `ua` -> přepnutí jazyka.
- `light`, `dark` -> přepnutí tématu.
- Ostatní hodnota -> uloží se jako nové sériové číslo.

1. **Auto-fill pracoviště** – Stačí zvolit pracoviště, vše se doplní samo
2. **Směna defaulty** – Po výběru směny se vyplní časy automaticky
3. **Pracovníka ze seznamu** – Vyberte ze seznamu, nemusíte psát jméno
4. **Quick submit** – Stiskněte **Ctrl+Alt+Enter** dvakrát

### Opravy

1. **Chyba v čase** – Jednoduše opravte, vše se přepočítá
2. **Chyba v NOK** – Zvýšovací/snižovací tlačítka, nebo ručně
3. **Prostoj zapomenuté** – Addujte nový řádek, OEE se aktualizuje

### Validace

- **Povinná pole** (*) – Bez nich se nelze zacháat (Pracoviště, Datum)
- **OEE se počítá** – Reálný čas, vidíte výsledek hned
- **Kontrola před uložením** – Modální okno si ji zobrazuje

---

## 9. Traceability Terminál

Navigace: **Admin → Traceability → Terminál** (`admin/traceability_terminal.php`)

### Účel

Terminál pro skenování DMC kódů přes čtečku. Záznamy se ukládají přímo do `traceability2`. Podporuje správu palety (počítadlo, limit, reset) a příkazy zadávané čtečkou.

### Postup

1. Vyberte projekt ze seznamu nebo ho změňte naskenováním přesného názvu tabulky (např. `48w200`, `gen3`).
2. Skenujte DMC kódy jeden po druhém – každý sken se automaticky uloží, počítadlo palety se zvýší.
3. Sledujte počítadlo kusů a limit – při dosažení limitu terminál zablokuje skenování.
4. **Reset palety** – naskenujte `ResetPalety`, zadejte do inputu nebo klikněte na tlačítko. Systém uloží log palety na síťový disk, vytiskne štítek (u projektů s tiskem) a vynuluje počítadlo.
5. Sledujte Live stav – zelené **Live** = API odpovídá, červené **Not Live** = výpadek.

### Příkazy přes čtečku

| Příkaz | Akce |
|--------|------|
| `ResetPalety` | Reset palety (uloží log, vynuluje počítadlo) |
| `Logout` | Odhlášení |
| `en` / `cz` / `ua` | Přepnutí jazyka |
| `light` / `dark` | Přepnutí tématu |
| Název projektu (přesný) | Přepnutí projektu |

### Časté chyby

- **Duplicitní DMC** – kus byl na aktuální paletě již naskenován; scan je odmítnut.
- **Not Live** (červeně) – výpadek API nebo sítě; záznamy se neukládají.
- **Paleta plná** – skenování zablokováno, dokud není proveden reset.

### Tipy

- Reset palety uloží celý log; pro projekty s tiskem se automaticky vytiskne štítek.
- Jazyk lze přepnout přímo čtečkou (naskenujte `en`, `cz` nebo `ua`) bez klikání na UI.

---

## 10. Přehled záznamů a export

### Zobrazení záznamů

1. Klikněte na **"Záznamy"** v navigaci (admin panel)
2. Vidíte seznam všech záznamů s filtry

### Filtry

- Datum (od–do)
- Typ (Série, Filtry)
- Směna
- Pracoviště
- Jméno pracovníka
- Kalendářní týden
- Rok
- OEE minimum (filtr)

### Akce

- **Klik na řádek** – Detail záznamu
- **Export CSV** – Stáhne se tabulka Do Excelu (UTF-8, správné znaky)
- **Tisk** – Filtrované záznamy vytisknete

### Detail záznamu

- Všechna data - strukturovaně
- Technické prostoje, NOK kódy
- Hodinové sledování (graf/tabulka)
- Tisk jednoho záznamu tiskáře

---

## 10. OEE Dashboard

Navigace: **Admin → OEE**

### Co tam vidím?

1. **OEE Chart** – Graf denní OEE (včera, dnes, trend)
2. **Struktura prostojů** – Donut diagram:
   - Plánované prostoje (šedé)
   - Technické (modré)
   - Kvalita (červené)
   - Logistika (zelená)
3. **Žebříček pracovišť** – Která pracoviště mají nejlepší OEE?
4. **Top 10 NOK kódů** – Nejčastější vady

### Použití

- **Analýza výroby** – Která pracoviště selhávají?
- **Plánování** – Kde investovat do údržby?
- **Trendy** – Zlepšuje se kvalita?

---

## 11. Týdenní výroba

Navigace: **Admin → Záznamy a dashboardy → Týdenní výroba**

### Co stránka řeší

- Jednotný týdenní report pro dashboard i TV variantu
- Filtrace podle **KT** a **roku**
- Volitelný výběr konkrétních pracovišť

### Chování filtrů

- Pokud není zvolené žádné pracoviště, dashboard nabídne jen pracoviště s výrobou v daném týdnu
- V TV variantě se zobrazují pouze pracoviště s výrobou v posledních 7 dnech
- Pole KT podporuje kolečko myši (inkrement/dekrement 1..53)

### Uložené filtry (presety)

- Pevné presety: **KT report** a **KT report 2** (nelze mazat)
- Uživatelské presety jsou individuální pro každého uživatele
- Trvalý filtr ukládá jen seznam pracovišť (ne KT/rok)
- Vytvoření i smazání probíhá přes potvrzovací dialog

## 12. TV Mode

Navigace: **Admin → TV**

### Účel

- **Fullscreen displej** pro TV
- **Živé statistiky** – Auto-refresh každých 60 sekund
- Bez administračního menu

### Co se zobrazuje?

- Dnešní výroba (počty kusů, OEE)
- NOK kusů (kategorie)
- Top chyby (nejčastěji se opakující)
- Živé hodiny (06:00–06:00)
- Archiv posledních 7 dní

### Jak jej připojit na TV?

1. Otevřete v *Google Chrome fullscreen*:
   ```
   Adresa: http://server/dvd/admin/tv.php
   F11 → Fullscreen
   ```
2. Přípojte se pomocí Chromecast nebo HDMI
3. Nechte běžet – živý feed vám ukazuje výrobu

---

## 13. Traceability2

Navigace: **Admin → Traceability²**

Dostupné pro role: **admin**, **superadmin**.

### Účel

Zobrazuje produkční data skenování z databáze `traceability2` – kopie vzdálené DB ze serveru `LB100021`. Každý záznam reprezentuje sken sériového čísla dílu (filtr) na příslušné výrobní stanici.

Zdrojové tabulky:
| Tabulka | Popis |
|---------|-------|
| `gen3` | Generace 3 – skenování SN |
| `gen3manual` | Generace 3 – ruční zadání SN |
| `48w` | 48W – skenování SN |
| `48w200` | 48W 200 – skenování SN |
| `48w200_2` | 48W 200 – druhá varianta |

### Filtry

- **Od / Do** – časové rozmezí (výchozí: aktuální měsíc)
- **Tabulka** – filtr konkrétní zdrojové tabulky, nebo „Vše"
- **Sériové číslo** – fulltextové hledání v poli `snDil` (lze zadat část SN)
- **Uživatel** – filtr dle operátora

### Co se zobrazuje

1. **Statistické karty** – Celkový počet skenů, unikátní SN, aktivní uživatelé, první/poslední sken
2. **Denní graf** – Počty skenů a unikátních SN za den (Chart.js)
3. **Rozpad dle tabulky** – Přehled skenů, SN a uživatelů pro každou zdrojovou tabulku
4. **Top 20 operátorů** – Nejaktivnější uživatelé v daném období
5. **Posledních 100 skenů** – Nejnovější záznamy s detailem tabulky, SN, času a jména operátora

### Synchronizace dat

Data se automaticky synchronizují každých **30 minut** z produkčního serveru `LB100021` (Cronical cron job na Laragon serveru). V hlavičce stránky je vidět **čas poslední synchronizace**.

**Ruční spuštění synchronizace** (pouze superadmin):
- Klikněte na odkaz „↻ Poslední synchronizace: ..." v hlavičce stránky
- Nebo spusťte manuálně z admin sekce **Skripty**: `Traceability sync (přírůstkový)` / `Traceability sync (úplný)`

---

## 15. Číselník směn (Správa -> DVD)

Od verze `v5.3.1` je směna spravována přes databázový číselník.

### Kde jej najdu

Navigace: **Správa -> DVD -> Směny** (`admin/smeny.php`)

### Co stránka umí

- Přidat novou směnu (kód, název, čas od/do, pracovní doba, pauza, pořadí)
- Upravit existující směnu
- Aktivovat/deaktivovat směnu
- Smazat směnu

### Jak se změny projeví

- Hlavní formulář (`index.php`) načítá směny přímo z DB.
- Po uložení změn v číselníku se výběr směn aktualizuje bez potřeby zásahu do kódu.

---

## 16. Správa uživatelů a CSV import

Tato část je dostupná pouze pro roli Superadmin.

### Operátor s přihlášením kartou (role `read`)

Účty s rolí `read` (Operátor) jsou určeny pro přihlášení přes čtečku karet:

1. Při vytváření uživatele zadejte do pole **Heslo** číslo karty (naskenujte ho čtečkou).
2. Systém uloží kód jako plain-text do `dvd_vyroba.users.traceability_card_code` a `traceability2.user.password`.
3. Přihlášení kiosku probíhá porovnáním kódu 1:1 – bez hashování.
4. Účet s rolí `read` **nevyžaduje změnu hesla** při prvním přihlášení (je okamžitě aktivní).

### Kde import najdu

1. Otevřete Správa → Uživatelé.
2. V horní části stránky je karta Import CSV – uživatelé.
3. Nahrajte soubor a spusťte import.

### Formát CSV pro uživatele

Použijte oddělovač středník ; a hlavičku:

```
username;skutecneJmeno;role;aktivni;heslo
```

Příklad:

```
novakj;Jan Novak;write;1;BezpecneHeslo123
svobodap;Petr Svoboda;read;0;JineBezpecneHeslo456
```

### Pravidla importu

- username musí mít aspoň 3 znaky.
- heslo musí mít aspoň 5 znaků.
- role: superadmin, admin, write, read, traceability (jiná hodnota se bere jako read).
- aktivni: 1/true/yes/ano = aktivní, ostatní hodnoty = neaktivní.
- duplicitní username se přeskočí.

Po importu se zobrazí souhrn: importováno / přeskočeno.

---

## 17. Deploy release do produkce

Od verze `v5.1.1` je release deploy součástí stránky `Správa -> Skripty`.

### Kdo může deploy spustit

- Pouze role `superadmin`.

### Postup

1. Otevři `Správa -> Skripty`.
2. V sekci **Deploy release** vyber release tag (např. `v5.1.1`).
3. Klikni na **Nasadit release**.
4. Zkontroluj výstup s výsledkem (`OK`/`ERR`).

### Co deploy udělá

- Nasadí vybraný tag do produkčního rootu `C:/laragon/www`.
- V produkčním configu upraví `APP_URL` na `http://10.67.100.69/`.
- Před úpravou vytvoří zálohu produkčního `includes/config.php`.

### Důležité

- Test prostředí je `C:/laragon/www/pisek/v3.4`.
- Produkce běží z rootu serveru `C:/laragon/www`.
- Pokud deploy selže, nejdřív ověř dostupnost gitu a práva zápisu do produkční složky.

---

## 18. Uživatelský manuál v aplikaci

Od verze v5.2.4 je v levém menu položka **Uživatelský manuál** (`admin/manual.php`).

### Co zobrazuje

- Pouze sekce a moduly dostupné pro roli přihlášeného uživatele (DVD, Traceability, Opravy, Naladčci, Správa, Účet).
- Pro každý modul: účel, kroky, pravidla zadávání, časté chyby, tipy.

### Режиmy detailu

- **Stručně** (`?manual_mode=concise`) – jen první kroky a základní tipy.
- **Podrobně** (`?manual_mode=detailed`) – kompletní průvodce s chybami.

### Přepínání jazyků

Manuál se zobrazí v jazyce nastaveném v přihlášení (cs/en/uk). Vše je přeloženo ve všech třech jazycích.

### Tisk

Při tisku stránky je sekce „Obsah manuálu" automaticky skryta; obsah jednotlivých karet se formátuje bez přetečení.

---

## 19. FAQ

### Jak se přihlásím?

Klikněte na **"Přihlásit se"** vpravo nahoře. Zadejte přihlašovací jméno a heslo.

### Zapomněl jsem heslo

Kontaktujte administrátora (superadmin). On to resetuje.

### Povinné pole chybí – co mám dělat?

Podívejte se na **hvězdičku (*)** u pole. Bez nich **se nelze uložit**:
- Pracoviště
- Datum

### Čím se liší OEE od výkonu?

- **Výkon (P)** – Kolik procent normy jste dosáhli
- **OEE** – Kombinace dostupnosti × výkonu × kvality

Např.: Když máte 100% výkon, ale 100 minut je prostoj → OEE bude nižší.

### Mohu si změnit jazyk?

Ano! V pravém horním rohu je přepínač jazyka (Čeština / Angličtina / Ukrajinština).

### Jak exportuju data?

1. Jděte na **Záznamy**
2. Vyplňte filtry
3. Klikněte na **Export CSV**
4. Soubor se stáhne → otevřete v Excelu

### Mohu se přihlásit z telefonu?

Ano, ale formulář je optimalizován pro **počítač/tablet**. Na mobilním telefonu bude obtížnější.

### Čím je limitovaný počet řádků v Tech prostojích?

Neomezený – vždy si můžete přidat nový řádek.

### Co když uložím prázdný záznam?

Formulář vás upozorní – musíte vyplnit povinná pole (Pracoviště, Datum).

### Mám práva pro čtení (Read) – mohu přidávat záznamy?

Ne. **Read role** vidí jen TV mode. Pro přidávání potřebujete **Write** nebo **Superadmin**. Role **Traceability** má readonly přístup ke Záznamům, OEE, TV a Traceability² reportu.

### Jak se dostanu do správy uživatelů?

Musíte být **Superadmin**. Pak najdete **Správu uživatelů** v navigaci.

---

## Kontakt a podpora

Problém? Kontaktujte:
- **Admin** – Reset hesel, přístup
- **IT** – Technické problémy

---

**Verze:** 5.2.4
**Poslední aktualizace:** 7. 7. 2026

# FAQ

## Jak se přihlásím?
Klikněte na Přihlásit se vpravo nahoře a zadejte uživatelské jméno a heslo.

## Zapomněl jsem heslo
Kontaktujte administrátora (superadmin), který může heslo resetovat.

## Jak exportuji data?
1. Otevřete Záznamy.
2. Nastavte filtry.
3. Klikněte na Export CSV.

## Které role mohou zapisovat?
Role `write`, `admin` a `superadmin` mohou vytvářet zápisy. Role `read` a `traceability` mají pouze čtení.

## Jak spustím deploy release?
1. Přihlaste se jako `superadmin`.
2. Otevřete Správa → Skripty.
3. V sekci Deploy release vyberte tag.
4. Klikněte na Nasadit release.

## Kde najdu manuál aplikace?
V levém menu klikněte na položku **Uživatelský manuál** (ikona knihy). Stránka zobrazuje pouze sekce dostupné pro vaši roli. Přepínačem Stručně/Podrobně nastavíte úroveň detailu.

## Jak přihlásím operátora přes čtečku karet?
Operátor má roli `read`. Při vytváření účtu se do pole Heslo zadá číslo karty (naskenuje se přes čtečku). Systém ukládá kód v plain-textu – přihlášení pak probíhá porovnáním 1:1.

## Proč operátor (role `read`) není vyzván ke změně hesla?
Účty s rolí `read` jsou vytvořeny jako okamžitě aktivní (bez iniciálního hesla). Přihlašují se kartou, nikoli klasickým heslem.

## Co dělá příkaz ResetPalety na traceability terminálu?
Příkaz naskenovaný čtečkou (nebo tlačítko) uloží kompletní log aktuální palety na síťový disk, vytiskne štítek (u projektů s tiskem) a vynuluje počítadlo kusů. Po resetu lze pokračovat ve skenování nové palety.

## Kde najdu správu směn?
V menu otevřete Správa → DVD → Směny. Stránka `admin/smeny.php` umožňuje přidávat, upravovat, deaktivovat i mazat směny. Změny se ihned promítnou do výběru směny v hlavním formuláři.

## Jak změním jazyk na traceability terminálu?
Naskenujte příkaz `en`, `cz` nebo `ua` přímo čtečkou. Jazyk se přepne okamžitě bez nutnosti klikat na UI.

## Co obsahuje release v5.6.6?

Editor NOK matice umožňuje vyhledat a vybrat až pět pracovišť. Načítá pouze jejich AFO sloupce a změny uloží jen pro vybraná pracoviště; ostatní nastavení zachová.

## Co obsahoval release v5.6.5?

Release `v5.6.5` bezpečně ruší stará data při změně pracoviště a před tiskem ověřuje jeho platný výběr. Přidává paměť filtrů v přehledu záznamů, jejich vymazání tlačítkem Reset a responzivnější detail pracovníka.

## Co obsahoval release v5.6.4?

Release `v5.6.4` přidává do číselníku pracovníků datum ukončení, stavové a sloupcové filtry, selecty směnnosti/třídy, řazení ASC/DESC/vypnuto a výběrový modal pro duplicity CSV. Hlavní formulář nabízí pouze ručně aktivní pracovníky s datem ukončení v budoucnosti.

## Co obsahoval release v5.6.3?

Release `v5.6.3` přidává read-only tiskový režim pro Traceability Lineleadera (`writer`), stabilnější přepínání simulovaných rolí, opravu OEE Top NOK collation a aktualizovaný uživatelský manuál.

## Co obsahoval release v5.6.2?

Release `v5.6.2` přidává výhradní roli `fsk_print` s tiskovým režimem, omezeným sidebar menu a možností změny iniciačního hesla. Superadmin může v `admin/scripts.php` simulovat libovolnou roli a vrátit se zpět bez odhlášení.

## Co obsahoval release v5.6.0?
Release `v5.6.0` umožňuje nastavit vyloučení NOK z operátorského Scrap/OEE pro každé pracoviště a NOK kód zvlášť. Výchozí stav je v NOK/AFO matici a v konkrétním zápisu jej lze změnit; celkový fyzický NOK zůstává zachován.

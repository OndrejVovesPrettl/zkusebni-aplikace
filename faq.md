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

## Co obsahuje release v5.4.0?
Release `v5.4.0` je finální stabilní release pro aktuální stav aplikace, včetně aktualizovaného verzování, dokumentace a potvrzení nulové výroby v hlavním formuláři.

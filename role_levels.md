# Report rolí a oprávnění

Datum: 2026-10-06
Poslední update: výběr až pěti AFO v editoru NOK matice (v5.6.6)
Zdroj: aktuální implementace v `includes/config.php`, `includes/layout.php` a guardy v `admin/*.php`.

Legenda: Ano = má přístup/oprávnění, Ne = bez přístupu/oprávnění.

## Matice rolí

| Role | Menu: Nový zápis | Menu: Záznamy/OEE | Menu: Traceability | Menu: TV módy | Menu: Správa | Hlavní formulář (zápis) | Traceability editor (editace) | Traceability terminál (zápis) | Poznámka |
|---|---|---|---|---|---|---|---|---|---|
| `superadmin` | Ano | Ano | Ano | Ano | Ano (vč. `scripts`) | Ano | Ano | Ano | Nejvyšší práva |
| `admin` | Ano | Ano | Ano | Ano | Ano (bez `scripts`) | Ano | Ano | Ne | Prakticky skoro superadmin |
| `write` | Ano | Ne | Ne | Ne | Ne | Ano | Ne | Ne | Operátor hlavního formuláře |
| `writer` | Ne | Ano | Ano | Ano | Ne | Pouze výběr pracoviště/okruhu a tisk FSK | Ano | Ne | Traceability Lineleader; bez zápisu do hlavního formuláře |
| `traceability` | Ne | Ano | Ano | Ano | Ne | Ne | Ne (jen čtení) | Ne | Traceability read-only |
| `read` | Ne | Ano | Ano | Ano | Ne | Ne | Ne (jen čtení) | Ne | Obecný read-only |
| `fsk_print` | Ano | Ne | Ne | Ne | Ne | Pouze výběr pracoviště/okruhu a tisk | Ne | Ne | Výhradní role; bez navigace, zápisu a přímého přístupu k ostatním routám |

## Poznamka k menu v4.7.0

- V sekci dashboardu je pro KT reporty pouzita sjednocena stranka `Týdenní výroba` (`admin/kt_report_all.php`).
- V TV menu je ponechana tydenni varianta (`admin/kt_report_all_tv.php`) a puvodni denni polozka LH/EMV byla odstranena.

## Přehled klíčových helperů

| Helper | Role |
|---|---|
| `canWrite()` | `superadmin`, `admin`, `write` |
| `canAdmin()` | `superadmin`, `admin` |
| `canTraceabilityEdit()` | `superadmin`, `admin`, `writer` |

## Důležité výjimky a technické poznámky

| Oblast | Aktuální chování |
|---|---|
| `requireRole('superadmin')` | V implementaci povoluje i `admin` (volá `requireAnyRole(['superadmin', 'admin'])`) |
| `requireSuperAdmin()` | Opravdu pouze `superadmin` |
| Traceability terminál | Přístup na stránku má více rolí, ale zápis scanů/reset je pouze pro `superadmin` |
| TV obrazovky | `admin/tv.php` i `admin/traceability_tv.php` jsou veřejné (bez loginu) |

## Admin stránky a jejich ochrana

| Stránka / oblast | Guard | Reálně dostupné role |
|---|---|---|
| `admin/users.php`, `admin/pracovisite.php`, `admin/pracovnici.php`, `admin/nok_kody.php`, `admin/prostoj_typy.php`, `admin/import.php`, `admin/translations.php`, `admin/audit_log.php` | `requireRole('superadmin')` | `superadmin`, `admin` |
| `admin/scripts.php` | `requireSuperAdmin()` | pouze `superadmin` (vcetne Deploy release do produkce) |
| `admin/profile.php` | `requireLogin()` | všechny přihlášené role |

## Traceability oblasti

| Stránka | Přístup | Editace/Zápis |
|---|---|---|
| `admin/traceability.php` (Traceability report) | `superadmin`, `admin`, `traceability`, `writer`, `read` | dle konkrétních akcí na stránce |
| `admin/traceability_records.php` (Traceability editor) | `superadmin`, `admin`, `traceability`, `writer`, `read` | jen `superadmin`, `admin`, `writer` |
| `admin/traceability_shift.php` (Traceability přehled) | `superadmin`, `admin`, `traceability`, `writer`, `read` | report (read) |
| `admin/traceability_terminal.php` | `superadmin`, `admin`, `traceability`, `writer`, `read` | zápis pouze `superadmin` |

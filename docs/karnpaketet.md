# Kärnpaketet (core/)

Alla verktyg har en egen, identisk kopia av kärnpaketet i `core/`. Det ändras aldrig inne i ett verktyg;
nya versioner läggs in i alla verktyg samtidigt. Versionen står i `core/VERSION` (nu 1.9.0) och syns i
verktygets `ping`.

## Filer

| Fil | Innehåll |
|---|---|
| `bootstrap.php` | Laddar config och kärnan, startar session, felhantering och CSRF-kontroll |
| `fel.php` | Global felhantering – AJAX och API får alltid JSON (`kc_json`) |
| `hjalp.php` | Hjälpfunktioner: `kc_h` (escaping), `kc_uuid`, `kc_nu`, `kc_tid`, `kc_url`, `kc_get`/`kc_post`, `kc_flash`, `kc_omdirigera` |
| `db.php` | MySQLi: `kc_tabell`, `kc_hamta_alla`, `kc_hamta_en`, `kc_hamta_varde`, `kc_kor`, `kc_transaktion` |
| `sakerhet.php` | Säkerhetshuvuden, session, CSRF, inloggning och spärr, lösenordsregler och bcrypt |
| `lista.php` | `kc_lista`: sortering, filter, paginering och export |
| `layout.php` | `kc_sidhuvud` och `kc_sidfot` |
| `installera.php` | Hjälpare för `install.php`: `kc_inst_tabell`, `kc_inst_sys_tabell`, `kc_inst_seed` |
| `verktyg.php` | API-klient, användarsynk, inloggning i verktyg, verktygets endpoints (`kc_verktyg_api`), push och pull |
| `import.php` | Importmotorn: `kc_import_kor` med mall, förhandsvisning och signerat genomförande |
| `egenskap.php` | Egenskapsmotorn: egendefinierade egenskaper på valfria nivåer, inkl. import |
| `kollen.css` | Det gemensamma, DOS-inspirerade utseendet |

Allt i kärnpaketet har prefixet `kc_`/`KC_`. Verktygets egna funktioner har verktygets prefix (`fas_`, `mat_` …).

## Ett nytt verktyg

1. Välj ett ledigt prefix (tre bokstäver) och lägg till rollen i CentralKollens `install.php`.
2. Skapa mappen med standardstrukturen:
   ```
   <verktyg>/
     config.php       (från config.example.php – aldrig incheckad)
     .htaccess        spärrar config.php
     index.php        startsida
     login.php        inloggning
     meny.php         menyn som array
     <prefix>.php     verktygets hjälpfunktioner och tabelldefinitioner
     install.php      skapar tabeller via kc_inst_tabell (idempotent, låst via KOLL_INSTALL)
     demo_data.php    exempeldata (valfri)
     api/index.php    definierar KC_API och anropar kc_verktyg_api
     core/            kärnpaketet
   ```
3. Varje sida börjar med `require __DIR__ . '/core/bootstrap.php';` och bygger sidan med `kc_sidhuvud()`
   och `kc_sidfot()`. Listor görs med `kc_lista()`.
4. Tabellnamn hämtas alltid via `kc_tabell('md_xxx')` – aldrig hårdkodade. Varje `md_`-tabell har
   standardfälten uuid, last_changed, is_deleted och source_tool.
5. Deklarera delade tabeller i `<prefix>_delade_tabeller()` och prenumerationer med lokala `ld_`-tabeller.
6. Använd `kc_push_alla` och `kc_pull_alla` på en Synk-sida.

## Gemensamt utseende

DOS-inspirerad stil: monospace, ljus bakgrund, svart text, röda länkar och svarta rundade ramar.
Alla listor via `kc_lista()`; formulär följer POST–redirect–GET med flash-meddelanden.

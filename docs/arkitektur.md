# Arkitektur

## Principer

- **En master per begrepp.** Information administreras där den hör hemma, av den roll som har ansvaret,
  och kan användas på många ställen. Fastigheter ägs av FastighetsKollen, mätare av MätarKollen osv.
- **Verktygen pratar bara med CentralKollen.** Ett verktyg kan därför bytas mot ett annat system
  (ev. via en adapter) som följer API-kontraktet.
- **Prefix står för en roll, inte en produkt.** `fas` betyder "mastern för fastighetsdata", oavsett vilket
  system som fyller rollen.
- **API-kontraktet beskriver datan** (fältnamn, format), inte verktygens interna tabeller.
- **Ansvar knyts till roller, aldrig till personer.** Inloggningskonton (CentralKollen) och personer i
  organisationen hålls isär.
- **Saknad master.** När ägaren av ett begrepp inte är byggd än får ett annat verktyg tillfälligt lagra
  värdet som text eller kod (t.ex. team och kostnadsställe i FastighetsKollen). När mastern finns väljs
  värdet i stället ur masterns lista – samma kod, ingen omflyttning av data.
- **Identifierande nummer** (fastighets-, byggnads-, objektnummer m.fl.) är unika i hela ekosystemet och
  lagras som text, så att nollor i början och bokstäver fungerar.

## Namnregler

| Namn | Betydelse | Exempel |
|---|---|---|
| `<prefix>_md_<tabell>` | Verktygets egen masterdata | `fas_md_objekt` |
| `<prefix>_ld_<master>_<tabell>` | Lokal kopia av ett annat verktygs data | `mat_ld_fas_objekt` |
| `<prefix>_sys_<tabell>` | Lokal teknisk tabell (loggar, räknare); synkas aldrig | `prj_sys_raknare` |
| `cen_md_<tabell>` | CentralKollens egen data | `cen_md_anvandare` |
| `ck_<prefix>_<tabell>` | Central kopia av ett verktygs masterdata (i CentralKollen) | `ck_fas_objekt` |
| `demo_…` | Samma tabeller för en demoinstallation | `demo_fas_md_objekt` |

- Prefix: exakt tre gemena bokstäver. `ck`, `cen` och `demo` är reserverade; prefix som börjar med `x`
  är reserverade för externa system.
- Tabell- och kolumnnamn: bara a–z, 0–9 och understreck. Tabellnamn i singular.
- Verktygens namn skrivs `<Namn>Kollen`.

## Datamodell

Varje rad i `md_`-, `ld_`- och `ck_`-tabeller har:

| Fält | Betydelse |
|---|---|
| `uuid` | Globalt id (CHAR(36)); det lokala auto-increment-id:t används bara internt |
| `last_changed` | Senaste ändring, UTC, DATETIME(6); uppdateras bara vid verklig ändring |
| `is_deleted` | Mjuk radering – rader tas aldrig bort, så att raderingar kan synkas |
| `source_tool` | Vilket verktyg som senast skrev raden |

- All tid lagras i UTC och visas i `KOLL_TIDSZON`.
- Inga främmande nycklar i databasen; kaskader och unikhet (bland ej raderade rader) hanteras i PHP.
- En borttagen relation återaktiveras med samma uuid i stället för att dupliceras.
- Valbara typer (objekttyper m.m.) är redigerbara listor, förifyllda vid installation. En typ som används
  kan inte raderas, bara inaktiveras.

## Synk

- **Push:** mastern skickar rader med `last_changed` efter senaste push, i ordning `last_changed, id`, 500
  per anrop, även raderade rader. Verktyget sparar senast skickade tid och börjar nästa push 5 sekunder
  före den; dubbletter ignoreras av CentralKollen. Upsert på uuid; en rad som inte är nyare än den
  lagrade ignoreras.
- **Pull:** verktyget hämtar prenumererade tabeller efter senast hämtade `sync_seq`, 500 per anrop, till
  sina `ld_`-tabeller. Kolumner fylls via fältnamn.
- **sync_seq** stämplas i efterhand i CentralKollen från en låst räknare: före varje pull får rader där
  `last_changed ≠ seq_tid` nästa nummer. Skrivare behöver bara sätta `last_changed`.
- **Användare** synkas automatiskt (inte via prenumeration) till de verktyg de har behörighet till,
  med bcrypt-hash. Borttagen behörighet synkas som raderad användare. Inloggningen sker lokalt i verktyget;
  är CentralKollen nåbar hämtas användaren därifrån först.
- CentralKollen administrerar vilka tabeller varje verktyg är master för och vilka det prenumererar på.

## Inloggning och behörighet

- Användare och behörigheter administreras bara i CentralKollen. Behörighet per verktyg är "får logga in: ja/nej";
  behörighet till CentralKollen = administratör.
- Läsning är öppen för alla. Icke inloggade får hämta data (pull) och exportera listor, men inte ändra,
  importera eller pusha. CentralKollen visar bara en informationssida för icke inloggade.
- Lösenord: 8–72 tecken med versal, gemen och siffra. Spärr efter 5 fel per användarnamn eller 20 per IP inom 15 minuter.
- Inloggad användare kontrolleras mot databasen vid varje sidvisning.
- Varje verktyg har eget sessionsnamn och en sessionscookie begränsad till verktygets mapp.

## Teknik

- PHP 8.5 + MariaDB/MySQL via MySQLi med prepared statements överallt.
- Varje verktyg i en egen mapp med egen `config.php` och egen kopia av kärnpaketet (`core/`).
- Installation via `install.php` (idempotent: skapar saknade tabeller, kolumner och index).
- Inga temporära filer på servern: export strömmas direkt, import bearbetas i minnet.
- Inga externa bibliotek på servern. QR-koder, PDF och zip genereras med egen kod. Kameraläsningen i
  ProjektKollen använder i webbläsaren polyfillen `barcode-detector` (ZXing/WebAssembly) från jsdelivr.

## Externa system (planerat)

Ett externt system blir en roll i CentralKollens verktygsregister med prefix som börjar med `x`. Det får
bara data, aldrig användare, och kan aldrig skriva i någon annans masterdata.

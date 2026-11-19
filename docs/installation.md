# Installation

Installationen görs i ordningen **CentralKollen → FastighetsKollen → MätarKollen → ProjektKollen**,
eftersom varje verktyg bygger på data från de föregående.

## 1. Förberedelser

1. Lägg de fyra mapparna på webbservern, t.ex.:
   ```
   https://exempel.se/kollen/centralkollen/
   https://exempel.se/kollen/fastighetskollen/
   https://exempel.se/kollen/matarkollen/
   https://exempel.se/kollen/projektkollen/
   ```
2. Skapa en databas (MariaDB/MySQL). Alla verktyg kan dela samma databas – varje verktyg har sitt
   eget tabellprefix (`cen_`, `fas_`, `mat_`, `prj_`).
3. Kopiera `config.example.php` till `config.php` i varje mapp och fyll i:

| Konstant | Betydelse |
|---|---|
| `KOLL_PREFIX`, `KOLL_NAMN` | Verktygets roll och namn (redan ifyllda – ändra inte prefixet) |
| `KOLL_VARIANT` | `github` för en vanlig installation; `demo` ger prefixet `demo_` på alla tabeller (en demo kan då dela databas med en skarp installation) |
| `KOLL_URL` | Verktygets egen adress, slutar med `/` |
| `DB_HOST`, `DB_NAMN`, `DB_ANVANDARE`, `DB_LOSENORD` | Databasen |
| `CEN_URL` | CentralKollens adress (i verktygen) |
| `NYCKEL_TILL_CEN`, `NYCKEL_FRAN_CEN` | API-nycklar som genereras i CentralKollen (i verktygen) |
| `KOLL_INSTALL` | `true` öppnar `install.php` och `demo_data.php` – sätt `false` när du är klar |
| `KOLL_DEBUG` | `true` visar feldetaljer – ska vara `false` på en server som nås från internet |
| `KOLL_TIDSZON`, `KOLL_SESSION_MINUTER` | Visningstidszon (lagring sker alltid i UTC) och utloggning vid inaktivitet |

`config.php` spärras av `.htaccess` och ska aldrig checkas in (se `.gitignore`).

## 2. CentralKollen

1. Öppna `centralkollen/install.php` och installera. Tabellerna skapas och verktygsregistret fylls i.
2. Logga in som `admin` / `123Qwe!!` och **byt lösenord direkt** (startsidan varnar annars).
3. Valfritt: kör `centralkollen/demo_data.php` – skapar demokontot `demo` / `Demo2026!` med behörighet till
   de tre verktygen (inte till CentralKollen) och fyller i verktygens bas-URL.
4. Under **Användare**: ge dina konton behörighet till de verktyg de ska använda.
5. Under **Verktyg**, för varje verktyg du installerar: ange bas-URL, generera nycklar, kopiera de färdiga
   config-raderna (`CEN_URL`, `NYCKEL_TILL_CEN`, `NYCKEL_FRAN_CEN`) till verktygets `config.php` och aktivera
   verktyget. `NYCKEL_TILL_CEN` visas bara en gång.

## 3. FastighetsKollen

1. Kör `fastighetskollen/install.php`.
2. I CentralKollen → Verktyg → FastighetsKollen: hämta tabellerna från verktyget och registrera alla.
3. Logga in i FastighetsKollen. Valfritt: `demo_data.php` → exempeldata.
4. **Synk → Skicka**.

## 4. MätarKollen

1. Kör `matarkollen/install.php`.
2. I CentralKollen → Verktyg → MätarKollen: registrera tabellerna och prenumerera på FastighetsKollens
   `fastighet`, `byggnad`, `objekt`, `objektgrupp`, `adress`, `byggnadstyp`, `objekttyp`, `objektgruppstyp`.
3. Logga in i MätarKollen, **Synk → Hämta**. Valfritt: `demo_data.php`.
4. **Synk → Skicka**.

## 5. ProjektKollen

1. Kör `projektkollen/install.php` (lägger även in exportmallen för MätarKollen).
2. I CentralKollen → Verktyg → ProjektKollen: registrera tabellerna och prenumerera på samma åtta
   fas-tabeller som ovan plus `objektgrupp_koppling`, samt MätarKollens `matpunkt`, `matpunkt_storhet`,
   `storhet` och `matare`.
3. Logga in i ProjektKollen, **Synk → Hämta**. Valfritt: `demo_data.php`.
4. **Synk → Skicka**.

## 6. Avslutning

- Sätt `KOLL_INSTALL = false` i alla `config.php`.
- Kontrollera **Synkstatus** i CentralKollen.

## Exempeldata

`demo_data.php` finns i varje verktyg och lägger in påhittade data via verktygets egna importer och
funktioner (samma validering som i gränssnittet). Skripten nekas när `KOLL_VARIANT = 'labb'`, är öppna
bara när `KOLL_INSTALL = true` och gör ingenting om det redan finns data.

- **FastighetsKollen:** 3 fastigheter, 8 byggnader, 14 adresser, 54 objekt, 5 objektgrupper, egenskaper på alla nivåer.
- **MätarKollen:** 31 mätpunkter (klimatgivare, vattenmätare med huvudmätare och virtuell mätpunkt, värme, el),
  30 mätare med nycklar, monteringar och ställningar.
- **ProjektKollen:** mätarlager med 30 givare, ett pågående och ett planerat projekt, en egen exportmall.

## Uppgradering

Lägg de nya filerna på plats, sätt `KOLL_INSTALL = true`, kör `install.php` (skapar saknade tabeller,
kolumner och index men ändrar aldrig befintlig data) och sätt `KOLL_INSTALL = false` igen.

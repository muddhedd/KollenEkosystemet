# Ekosystemet Kollen

Kollen är en samling små, fristående webbverktyg för fastighetsförvaltning som delar data med varandra
via en central nod. Varje verktyg äger sin egen data – fastigheter, mätare, projekt – och hämtar det
övriga från de verktyg som äger det. Allt är byggt i PHP och MariaDB/MySQL, utan ramverk och utan
externa bibliotek, så att det går att köra på ett vanligt webbhotell.

> Koden är fri att använda, ändra och sprida utan villkor (se [LICENSE](LICENSE)).

## Verktygen

| Verktyg | Prefix | Äger | Beskrivning |
|---|---|---|---|
| [CentralKollen](centralkollen/) | `cen` | användare, behörigheter, register | Den centrala noden: inloggningskonton, vilka verktyg som finns, vilka tabeller de delar och prenumererar på, synk och övervakning. |
| [FastighetsKollen](fastighetskollen/) | `fas` | fastigheter, byggnader, objekt, adresser, objektgrupper | Fastighetsregistret: fastighet → byggnad → objekt, med objektgrupper och egna egenskaper på alla nivåer. |
| [MätarKollen](matarkollen/) | `mat` | mätpunkter, mätare, monteringar | Alla mätare och givare: var de sitter, vad de mäter för, nycklar, monteringar och mätarställningar. |
| [ProjektKollen](projektkollen/) | `prj` | projekt, planerade mätpunkter, mätarlager | Drifttagning av mätare i projekt: mätpunktslista, etiketter med QR-kod, provisionering och monteringskontroll med mobilkameran, exporter. |

Fler verktyg är planerade (nycklar, avtal, ärenden, budget, dokument m.fl.). Alla bygger på samma
kärnpaket och samma API-kontrakt, och vilket verktyg som helst kan bytas mot ett annat system som följer
kontraktet.

## Så hänger det ihop

```
   FastighetsKollen ──push──►                ◄──pull── MätarKollen
                              CentralKollen
   ProjektKollen    ──push──►  (ck_-kopior)  ◄──pull── ProjektKollen
```

- Verktygen pratar aldrig direkt med varandra, bara med CentralKollen.
- Den som äger data (mastern) **pushar** sina tabeller till CentralKollen; andra verktyg **pullar** de
  tabeller de prenumererar på till lokala kopior.
- Användare och behörigheter administreras bara i CentralKollen och synkas ut automatiskt; inloggningen
  sker sedan lokalt i varje verktyg.

Mer i [docs/arkitektur.md](docs/arkitektur.md) och [docs/api-kontrakt.md](docs/api-kontrakt.md).

## Krav

- PHP 8.5 eller senare (mysqli, mbstring, zlib)
- MariaDB eller MySQL
- HTTPS (krävs för kameraläsningen i ProjektKollen)
- Alla verktyg kan ligga i samma databas; varje verktyg har sitt eget tabellprefix.

## Snabbstart

1. Lägg mapparna på webbservern, t.ex. `https://exempel.se/kollen/centralkollen/` osv.
2. Kopiera `config.example.php` till `config.php` i varje mapp och fyll i databas och adresser.
3. Installera och koppla ihop i ordningen CentralKollen → FastighetsKollen → MätarKollen → ProjektKollen.
4. Vill du ha exempeldata: kör `demo_data.php` i varje verktyg i samma ordning.

Steg för steg i [docs/installation.md](docs/installation.md).

Standardkontot som skapas vid installationen är `admin` / `123Qwe!!` – **byt lösenord direkt**.
Startsidan varnar så länge standardlösenordet används.

## Dokumentation

| Dokument | Innehåll |
|---|---|
| [docs/installation.md](docs/installation.md) | Installation, konfiguration, nycklar, registrering och exempeldata |
| [docs/arkitektur.md](docs/arkitektur.md) | Principer, roller, namnregler, datamodell och synk |
| [docs/api-kontrakt.md](docs/api-kontrakt.md) | API v1 mellan verktygen och CentralKollen |
| [docs/import-export.md](docs/import-export.md) | Listexport, mjuk och hård import |
| [docs/karnpaketet.md](docs/karnpaketet.md) | Kärnpaketet (`core/`) och hur man bygger ett nytt verktyg |

Varje verktyg har dessutom en egen README.

## Säkerhet i korthet

- Lösenord hashas med bcrypt; spärr efter upprepade felinloggningar.
- CSRF-skydd på alla formulär, prepared statements överallt, all utdata escapas.
- API-nycklar genereras i CentralKollen; verktygets nyckel lagras bara som SHA-256-hash.
- Krypteringsnycklar för mätare ligger i egna tabeller, syns bara maskerade för inloggade och delas
  aldrig via synk eller API.
- `config.php` är spärrad via `.htaccess` och ska aldrig checkas in.

## Licens

Fri programvara utan villkor – se [LICENSE](LICENSE) (The Unlicense). Du får kopiera, ändra, sälja
och sprida koden hur du vill, utan att nämna upphovet.

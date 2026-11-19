# MätarKollen (`mat`)

Registret över alla mätare och givare i ekosystemet Kollen – temperatur, fukt, vatten, värme, el, gas m.m.
MätarKollen är master för mätpunkter, mätare och monteringar. Mätvärdena själva hör till ett eget,
planerat verktyg.

## Begrepp

- **Mätpunkt:** platsen och det som mäts – det som mätvärden och förbrukning knyts till.
  - **Placering:** var mätaren sitter – ett objekt, en byggnad, en fastighet eller en objektgrupp.
  - **Betjäning:** vad mätpunkten mäter för (förvalt samma som placeringen).
  - Typ fysisk eller virtuell (virtuell = beräknad, utan mätare), status projekt / drifttagen / avvecklad,
    förväntade storheter och högst en **föregående** mätpunkt (för flödesstrukturer, t.ex. huvudmätare).
  - Mätpunktsnamnet är unikt i hela ekosystemet. Mätpunkter raderas aldrig – de avvecklas.
- **Mätare:** den fysiska enheten med systemets eget mätarnummer (M000001 …), mätarId (det mätaren skickar;
  samma mätarId på samma gateway ger en varning), mätartyp, fabrikat, modell, kommunikation och gateway.
- **Nycklar:** DevEUI, JoinEUI/AppEUI, AppKey, AES-nyckel m.fl. i en egen tabell. Visas bara maskerade för
  inloggade (med kopieraknapp) och ingår aldrig i listor, exporter, synk eller API. Kommer in via import och
  lämnar aldrig verktyget. En borttagen nyckel töms på sitt värde.
- **Montering:** vilken mätare som sitter i vilken mätpunkt, med datum. Perioder får inte överlappa;
  "Byt mätare" avslutar och startar samma dag. Mätarställning per storhet vid montering och demontering.
- **Egenskaper:** egna fält på mätpunkt och mätare.

## Import

En fil "Mätpunkter och mätare" där varje rad är en mätpunkt med monterad mätare, nycklar och montering
(max 100 rader per fil) – samma fil som ProjektKollen exporterar. Okända typer (fabrikat, modell,
mätartyp, kommunikation, storhet, nyckeltyp) läggs till automatiskt och flaggas i förhandsvisningen.
Typlistorna har egna importer. Se [docs/import-export.md](../docs/import-export.md).

## Delning och beroenden

- Delar 14 tabeller (aldrig nycklar). Mätpunkten bär placeringens och betjäningens nummer, uppdaterat före push.
- Prenumererar på FastighetsKollens fastighet, byggnad, objekt, objektgrupp, adress och typlistor
  (används bara för sökning och val).

## Exempeldata

`demo_data.php` (kräver FastighetsKollens exempeldata): klimatgivare i lägenheter, vattenmätare med
huvudmätare, föregående och virtuell mätpunkt, värme på en objektgrupp, el, en planerad och en avvecklad
mätpunkt, nycklar, monteringar och ställningar.

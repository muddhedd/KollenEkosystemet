# Export och import

## Export från listor

Alla listor har **Exportera urval** (aktuellt filter och sortering, alla sidor) och **Exportera allt**.

- CSV med semikolon, UTF-8 med BOM – öppnas rätt i svenska Excel.
- Råvärden, inklusive uuid och tider i UTC, så att filen kan importeras tillbaka.
- Text som börjar med `=`, `+`, `-` eller `@` (men inte tal) får en apostrof framför (skydd mot formler i Excel);
  importen tar bort den igen.
- Alla som kan se datan får exportera den. Krypteringsnycklar ingår aldrig.

## Import

Varje verktyg som har import erbjuder en mall och **Exportera för import** (samma format som importen läser).

| Läge | Nya rader | Befintliga rader |
|---|---|---|
| **Mjuk** (förval) | läggs till | tomma fält kompletteras, befintliga värden skrivs aldrig över |
| **Hård** | läggs till | skrivs över med filens värden |

- Ingen import raderar rader som saknas i filen. Massborttagning görs i gränssnittet.
- En tom cell ändrar aldrig något; `(tom)` tömmer fältet vid hård import.
- Matchning på uuid om filen har det, annars på verktygets nyckel (t.ex. objektnummer).
- Referenser anges med nummer (fastighets-, byggnads-, objektnummer), typer med namn. Okända typer ger fel,
  om inte verktyget anger att de läggs till automatiskt (flaggas då i förhandsvisningen).
- Dubbletter inom en fil fångas på både uuid och nyckel.
- Alltid förhandsvisning (nya, kompletterade, uppdaterade, oförändrade, fel) innan något skrivs. Filens
  innehåll skickas signerat mellan förhandsvisning och genomförande; inget sparas på servern.
  Genomförandet sker i en transaktion.
- Max 1 000 rader och 2 MB per fil (en import kan ha lägre gräns, t.ex. 100 i MätarKollen). Läser
  semikolon, komma eller tab, UTF-8 eller Windows-1252. Decimaltal med komma eller punkt.
- Import kräver inloggning och skriver bara till verktygets egen masterdata (`md_`).
- Egenskaper importeras som kolumner `egenskap:<namn>` (ja/nej, valets namn, datum ÅÅÅÅ-MM-DD).

## Importer per verktyg

| Verktyg | Importer |
|---|---|
| FastighetsKollen | fastigheter, byggnader, adresser, objekt, objektgrupper, kopplingar, typlistor |
| MätarKollen | "Mätpunkter och mätare" (mätpunkt + mätare + nycklar + montering per rad, max 100 rader), typlistor |
| ProjektKollen | inköpta mätare till mätarlagret (med nycklar) |

## Exportmallar i ProjektKollen

ProjektKollen har dessutom **exportmallar** för filer till andra system: avgränsare, teckenkodning,
rubrikrad, rader per fil, urval och kolumner väljs i gränssnittet. En export skapar en exportomgång
där delarna fastställs vid skapandet; filerna laddas ner var för sig eller som zip. Den färdiga mallen
"MätarKollen – Mätpunkter och mätare" ger exakt den fil som MätarKollens import läser.

# FastighetsKollen (`fas`)

Fastighetsregistret i ekosystemet Kollen: fastighet → byggnad → objekt, kompletterat med adresser,
objektgrupper och egendefinierade egenskaper. FastighetsKollen är master för fastighetsdata och delar
alla sina tabeller via CentralKollen.

## Innehåll

- **Fastighet:** fastighetsnummer, namn, team, bolag och kostnadsställe (som text tills ägarverktygen finns),
  adresser.
- **Byggnad:** byggnadsnummer, namn, byggnadstyp, adresser. Nivån heter Byggnad även för utomhusområden
  (typen Utomhusområde). Byggnader kan flyttas mellan fastigheter.
- **Objekt:** objektnummer, objekttyp (lägenhet, lokal, garage, förråd, tvättstuga …), kvadratmeter, antal rum,
  kök (typlista), våningsplan (BV = 0), beskrivning och en av byggnadens adresser. Objekt kan flyttas.
- **Adresser:** registreras på fastighet och byggnad; varje ägare har exakt en huvudadress. Postnummer
  lagras som "123 45".
- **Objektgrupper:** t.ex. trapphus, tvättstuga, soprum eller värmesystem, med unikt objektgruppsnummer.
  Kopplas till fastigheter, byggnader och objekt som **resurs** (själva saken) eller **användare** (de som
  använder den). Koppling till en byggnad eller fastighet omfattar alla objekt där.
- **Egenskaper:** egna fält på alla nivåer (fastighet, byggnad, objekt, objektgrupp) med datatyperna text,
  heltal, decimaltal, datum, ja/nej och val ur lista.
- **Typlistor:** byggnadstyper, objekttyper, objektgruppstyper och kökstyper – redigerbara, förifyllda.

## Regler i korthet

- Fastighets-, byggnads-, objekt- och objektgruppsnummer är unika i hela ekosystemet och lagras som text.
- En fastighet med byggnader, eller en byggnad med objekt, kan inte raderas.
- En typ som används kan inte raderas, bara inaktiveras.
- Radering tar med postens kopplingar och egenskapsvärden (med bekräftelse när det finns några).

## Import

Fastigheter, byggnader, adresser, objekt, objektgrupper, kopplingar och typlistor, med mall och "Exportera
för import". Egenskaper som kolumner `egenskap:<namn>`. Se [docs/import-export.md](../docs/import-export.md).

## Delning

Alla 13 `md_`-tabeller delas via CentralKollen (registreras utan `md_`, t.ex. `objekt`). MätarKollen och
ProjektKollen prenumererar på dem. Push startas med knappen under Synk.

## Exempeldata

`demo_data.php`: Hjorten 1, Räven 4 och Lärkan 2 med 8 byggnader, 54 objekt, objektgrupper och egenskaper.

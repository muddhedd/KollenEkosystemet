# ProjektKollen (`prj`)

Projektverktyget i ekosystemet Kollen, byggt för att driftta mätare – först temperaturgivare – i
fastigheter. Mätpunkter och mätare planeras och provisioneras här och blir riktiga i MätarKollen när
exportfilen importeras där.

## Flödet

1. **Projekt:** projektnummer (P00001 …), projekttyp, status Planering / Pågående / Avslutat.
2. **Omfattning:** fastigheter, byggnader, objekt eller objektgrupper. Ger en **objektlista** med antal
   givare per objekt (förval 1, 0 = ingen).
3. **Mätpunktslista** enligt namnstandarden `<objektnummer>_<funktion>_<X><Y>` (t.ex. `1301-01-0001_Temp_11`;
   X = löpnummer, Y = antal i objektet). Befintliga mätpunkter i MätarKollen och upptagna namn visas som infotext.
4. **Etiketter som PDF** (A4, 3 × 8 à 70 × 37 mm) med QR-kod med mätpunktsnamnet. Utskrift låser namnen.
   Dessutom **objektetiketter** med objektnumret.
5. **Mätarlager:** inköpta mätare importeras med nycklar till ett gemensamt lager (inte per projekt).
6. **Provisionering** med mobilkameran: läs etikettens QR-kod, läs mätarens QR-kod (LoRaWAN-formatet
   `LW:D0:…` tolkas) – kopplingen sparas direkt. Manuell inmatning och foto som reserv.
7. **Monteringskontroll** på plats: läs objektnumret (etikett, iPhones "Skanna text" eller manuellt) och
   mätarens QR-kod. Rätt mätare sparar monteringsdatum; fel mätare ger röd varning och inget sparas.
8. **Exporter** via exportmallar, bl.a. den låsta mallen för MätarKollens import. En export skapar en
   exportomgång med fasta delar; "Ladda ner alla" ger en zip.
9. **Avslut** med kontrollista. Nycklar rensas för mätare som exporterats med nycklar. Ett avslutat
   projekt kan återöppnas; nycklarna läses då in igen med mjuk import.

## Egen kod

QR-kodare (`prj_qr.php`), PDF-skrivare (`prj_pdf.php`) och zip-skrivare (`prj_zip.php`) är skrivna utan
bibliotek. Kameraläsaren (`prj_lasare.js`) använder i webbläsaren den inbyggda BarcodeDetector eller
polyfillen `barcode-detector` (ZXing/WebAssembly) från jsdelivr. Kameran kräver HTTPS.

## Säkerhet

Nycklar syns bara maskerade för inloggade, delas aldrig via synk eller API och lämnar verktyget bara via
exporter med en mall som tillåter nycklar (kräver inloggning, loggas).

## Delning och beroenden

- Delar projekt, projekttyp, funktion, omfattning, projektobjekt, mätpunkt och mätare (aldrig nycklar,
  exportmallar eller exportloggar).
- Prenumererar på FastighetsKollens fastighet, byggnad, objekt, objektgrupp, objektgruppskoppling, adress och
  typlistor samt MätarKollens mätpunkt, mätpunktsstorhet, storhet och mätare.

## Exempeldata

`demo_data.php` (kräver exempeldata i FastighetsKollen och MätarKollen): mätarlager med 30 givare, ett
pågående projekt med provisionerade, monterade och exporterade mätare, ett projekt under planering och
en egen exportmall.

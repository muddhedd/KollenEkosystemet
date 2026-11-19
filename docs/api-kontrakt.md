# API-kontrakt, version 1

## Transport

- `POST` med JSON till `<bas-URL>api/?e=<endpoint>`. Varje anrop innehåller `"kontrakt": 1`.
- Svar alltid `{"ok": true|false, "data": …, "error": null|"text"}` med HTTP-status 200, 400, 401, 403,
  404, 409 eller 500.
- Autentisering med headers `X-Kollen-Prefix` och `X-Kollen-Nyckel` (inte `Authorization`, som kan strippas
  av webbhotell).
  - Verktyg → CentralKollen: verktygets prefix och `NYCKEL_TILL_CEN`.
  - CentralKollen → verktyg: prefix `cen` och `NYCKEL_FRAN_CEN`.
- Inaktiva verktyg och tabeller nekas (403); `ping` svarar även för inaktiva verktyg.
- Data skickas direkt i anropet och skrivs i en transaktion. Hela batchen valideras innan något skrivs.
- Max 500 rader per anrop och 64 kB data per rad.

## Radformat

```json
{
  "uuid": "1b9d6bcd-bbfd-4b2d-9b5d-ab8dfbbd4bed",
  "last_changed": "2026-11-18 13:19:13.616292",
  "is_deleted": 0,
  "data": { "objektnummer": "1301-01-0001", "kvadratmeter": "61.5" }
}
```

`last_changed` är UTC. `source_tool` sätts av mottagaren. Pull-svar har dessutom `sync_seq` per rad.

## Endpoints i CentralKollen

| Endpoint | Indata | Svar |
|---|---|---|
| `ping` | – | verktyg, aktiv, tid, kontrakt |
| `konfig` | – | tabeller verktyget är master för och prenumererar på |
| `push` | `{tabell, rader}` | `{mottagna, sparade, ignorerade}` – bara mastern får pusha en tabell |
| `pull` | `{master, tabell, efter_seq}` | rader + `mer` (true/false) + `senaste_seq`; kräver prenumeration |
| `anvandare` | `{efter_seq}` eller `{anvandarnamn}` | användare filtrerat på behörighet; saknad behörighet = `is_deleted` 1 och tom hash |

## Endpoints i verktygen

| Endpoint | Indata | Svar |
|---|---|---|
| `ping` | – | verktyg, namn, variant, tid, kontrakt, kärnpaketets version |
| `tabeller` | – | verktygets delade tabeller `{tabell, beskrivning}` (fyller CentralKollens register) |
| `export` | `{tabell, sida}` | alla rader inkl. raderade, 500 per sida: `{tabell, sida, totalt, mer, rader}` |

## Lagring

- `ck_<prefix>_<tabell>` i CentralKollen: standardfält + `sync_seq` + `seq_tid` + `data` (JSON). Skapas vid första push.
- `<prefix>_ld_<master>_<tabell>` i verktygen: standardfält + de kolumner verktyget behöver. Kolumner med
  samma namn som fält i `data` fylls; en ev. kolumn `data` får hela objektet.
- `<prefix>_ld_cen_anvandare`: uuid, anvandarnamn, fornamn, efternamn, epost, telefon, losenord_hash, is_active.

## Loggning

Alla anrop loggas i `cen_sys_synklogg` (rensas efter 90 dagar); senaste status per verktyg, tabell och
riktning i `cen_sys_synkstatus`.

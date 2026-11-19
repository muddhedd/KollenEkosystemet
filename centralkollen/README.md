# CentralKollen (`cen`)

Den centrala noden i ekosystemet Kollen. CentralKollen äger inloggningskonton och behörigheter, håller
registret över verktyg, delade tabeller och prenumerationer, tar emot verktygens data (push) och lämnar
ut den till prenumeranterna (pull).

## Funktioner

- **Användare:** användarnamn, namn, e-post, telefon, lösenord (bcrypt), spärrad/aktiv. Användarnamn och
  e-post är unika bland ej raderade användare. En administratör kan inte låsa ute sig själv.
- **Behörigheter:** "får logga in: ja/nej" per verktyg. Behörighet till CentralKollen = administratör;
  övriga kan logga in här bara för att byta eget lösenord.
- **Verktyg:** bas-URL, aktivering, API-nycklar (genereras här; verktygets nyckel lagras som SHA-256-hash
  och visas en gång), färdiga config-rader att kopiera och test av verktygets API.
- **Tabellregister:** vilka tabeller varje verktyg är master för – hämtas helst från verktygets endpoint
  `tabeller` – och vilka tabeller varje verktyg prenumererar på. Tabellnamnet låses efter registrering.
- **Dataflöde:** översikt över vem som delar vad med vem; tabellsidan visar innehållet i den centrala kopian.
- **Synkstatus och synklogg:** senaste status per verktyg, tabell och riktning, och historik över alla
  API-anrop (rensas efter 90 dagar).
- **API v1:** `ping`, `konfig`, `push`, `pull`, `anvandare` (se [API-kontraktet](../docs/api-kontrakt.md)).

## Tabeller

| Tabell | Innehåll |
|---|---|
| `cen_md_anvandare` | Användare |
| `cen_md_verktyg` | Verktygsregistret (förifyllt med alla roller i ekosystemet) |
| `cen_md_behorighet` | Användare ↔ verktyg |
| `cen_md_tabell` | Delade tabeller per master |
| `cen_md_prenumeration` | Verktyg ↔ tabell de prenumererar på |
| `cen_sys_sekvens`, `cen_sys_synkstatus`, `cen_sys_synklogg`, `cen_sys_inloggning` | Teknik |
| `ck_<prefix>_<tabell>` | Centrala kopior av verktygens data (skapas vid första push) |

## Filer

`index.php` (information/översikt), `login.php`, `losenord.php`, `anvandare.php`, `anvandare_redigera.php`,
`verktyg.php`, `verktyg_redigera.php`, `tabell_redigera.php`, `dataflode.php`, `synkstatus.php`,
`synklogg.php`, `api/index.php`, `install.php`, `demo_data.php`, `cen.php` (hjälpfunktioner).

## Installation

Se [docs/installation.md](../docs/installation.md). Standardkontot `admin` / `123Qwe!!` skapas om inga
användare finns – byt lösenord direkt. `demo_data.php` skapar demokontot `demo` / `Demo2026!`.

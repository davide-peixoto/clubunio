# Branches pro ClubUnio-Umgebung

| Branch | Umgebung | Schema beim Start |
| --- | --- | --- |
| `main` | ClubUnio DEV | `clubora` |
| `stage` | ClubUnio Stage | `clubunio` |
| `prod` | Zukuenftiges ClubUnio PROD | Vor Erstdeployment verbindlich festlegen |

Am 10.11.2026 wurde `stage` aus dem geprueften Stage-Stand `3ad35f4`
erstellt. Der bisherige Branch `codex/stage-clubunio-schema` bleibt als
Nachweis erhalten. `prod` startet auf dem gemeinsamen Stand `8aeb91d`
als Ausgangspunkt, nicht als abgenommenes Produktionsrelease. Bestehendes
PHPMaker DEV/PROD gehoert nicht zu diesem Deployment.

Entwicklung und Tests beginnen auf `main`. Gepruefte Aenderungen gezielt
nach `stage` uebernehmen und dort abnehmen. Wegen der unterschiedlichen
Schemata keine pauschalen Branch-Merges oder Cross-Deployments; beim
Uebernehmen SQL und Migrationen gegen das jeweilige Ziel pruefen. Erst nach
separater Freigabe nach `prod` uebernehmen und produktiv deployen.

## Deployment-Zuordnung

- ClubUnio DEV: `main`.
- RunCloud-App `ClubUnioStage`: auf `stage` umstellen. Bis diese
  Einstellung bestaetigt ist, darf die App nicht automatisch von `main`
  deployen. Vor Aktivierung den Schema-Zugriff mit der App-Verbindung
  pruefen; HTTP 200 auf der Loginseite allein reicht nicht.
- ClubUnio PROD: `prod` erst bei freigegebenem Erstdeployment verbinden.

Das Anlegen der Branches stellt keine Hosting-App um. Keine Datenbank,
Snapshot-Schemas, Zugangsdaten oder laufende Umgebung werden dabei geaendert.

## Vereinsbranding auf Stage

Der Hinweis «Powered by ClubUnio» bleibt bei eigenem Vereinslogo und eigenen
Farben sichtbar. Nach dem Push auf `stage` den ausgelieferten Commit und
den Hinweis auf Login, Anmeldeformular und Signage pruefen. Keine Migration.

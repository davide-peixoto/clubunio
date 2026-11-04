# ClubUnio

Kanonischer lokaler Projektordner: C:\ClubUnio.
Das eigene Repository ist https://github.com/davide-peixoto/clubunio.git.
C:\Projects\SVS\backend\laravel ist nur eine Kompatibilitaets-Junction.
Keine zusaetzlichen Projektkopien dort oder in OneDrive erstellen.

Vor Arbeiten README.md, deploy/CLUBUNIO-ROADMAP.md und deploy/PI.md lesen.
Gemeinsamer SVS-Kontext bei Bedarf: C:\Projects\SVS\PROJECT-CONTEXT.md.
PHPMaker-Quellen fuer reine Migration/Metadaten liegen weiterhin separat unter
C:\Projects\SVS\backend. Nie ungefragt importieren oder Datenbanken auffrischen.

Die eigene ClubUnio-API ist seit 25.10.2026 freigegeben, siehe deploy/CLUBUNIO-API.md.
App-Neubau, SaaS und Betrieb bleiben ohne weitere Freigabe ausgeschlossen.
Bestehendes PHPMaker DEV/PROD unveraendert lassen. Keine echten Bankzahlungen.
Nach Releases Doku und Migrationsplan nachfuehren, gezielt testen, Commit&Sync.
Keine Secrets, .env-Dateien, Uploads, Laufzeitdateien oder vendor committen.
Vorhandene fremde Aenderungen erhalten und nicht pauschal mitcommitten.

## Verbindliche Mehrsprachigkeit

Bei jeder neuen oder geaenderten Funktion alle aktivierten Sprachen aus
config/clubunio-languages.php beruecksichtigen; aktuell Deutsch und Englisch.
Sichtbare UI-Texte, Validierung, Barrierefreiheitslabels, Exporte und Nachrichten
ueber Uebersetzungsschluessel pflegen, keine neuen fest verdrahteten deutschen
Texte. Beide Kataloge und Platzhalter pruefen: php tools/check-translations.php.
Status-/Lookup-IDs, API-Schluessel, Berechtigungen und Benutzereingaben bleiben
sprachunabhaengig. Freitexte niemals automatisch ersetzen. Dynamische Templates
und Lookups brauchen getrennte Sprachvarianten mit dokumentiertem Fallback.
Neue Sprache erst aktivieren, wenn ihre Kataloge und betroffenen Ablaufe getestet
sind. Stand und offene Bestandsuebersetzungen: deploy/CLUBUNIO-LANGUAGES.md.

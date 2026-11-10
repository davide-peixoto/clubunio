# SV Sissach: privater Stage-Dokumentenspeicher

Am 10.11.2026 wurde der private Container `svs-stage-private` im selben
Azure-Speicherkonto wie `svs-prod-private` angelegt. Einmalige serverseitige
Kopie der aktuellen Blobs, keine automatische Synchronisation. Dateipfade
bleiben identisch. Vorhandene Ziele werden nicht ueberschrieben, die Quelle
wird ausschliesslich gelesen. Kein Aendern der Spesen- oder Dokumentreferenzen.

Geprueft: 1865 Blobs und 1979457595 Bytes. Quelldaten beim verifizierten
Abgleich unveraendert; Pfade, Groessen, erfolgreicher Azure-Kopierstatus und
vorhandene Content-MD5-Werte verglichen. Kein Import alter Blob-Versionen oder
geloeschter Dateien. Das lokale CLI-Werkzeug
`tools/copy-svs-stage-blobs.php` verwendet die private Azure-Konfiguration
(`CLUBUNIO_AZURE_CONFIG` als Dateipfad fuer separate Checkouts). Erst
`--inspect`, danach nach Freigabe `--copy`. Token-Export nur in eine
geschuetzte Pipe; keine Credentials in Ausgabe, Git oder Arguments.

Stage liest historische SV-Sissach-Referenzen ueber
`svs-stage-private`, mit HTTPS-Container-SAS nur fuer Lesen und 30 Tagen
Laufzeit. Kein Azure-Kontoschluessel auf dem VPS. Token in der privaten
`.env.clubunio-integrations.local` pflegen und vor Ablauf erneuern.
Anwendungsberechtigungen und Dateipfadpruefung bleiben erhalten. Neue
Uploads sind damit nicht freigeschaltet; sie brauchen ein eigenes
Stage-Schreibprofil. Bexio-PDFs sind davon unabhaengig.

Nur Branch `stage`; DEV, PROD und Snapshot-Schemas unveraendert.
Rueckweg: Stage-Leseflag in der privaten Konfiguration deaktivieren und
Stage-Konfigurationscache erneuern. Nicht auf den PROD-Container zurueckfallen.

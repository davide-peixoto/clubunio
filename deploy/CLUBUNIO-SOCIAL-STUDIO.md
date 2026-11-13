# Social Media V1

Im Web unter **Anlagen & Kommunikation → Social Media**. Keine App-Änderung.

- **Spielübersicht:** Datum wählen, Wettkämpfe auswählen und Spieltagsgrafik
  erzeugen. Fremdspiele werden nicht automatisch ausgewählt. Nur Datum, Zeit,
  Paarung, Platz und bei Resultatposts der Spielstand werden übernommen.
- **Schlussresultate:** Personen mit Freigaberecht erfassen und bestätigen den
  Spielstand nach Spielende. Keine automatische SFV-Resultatübernahme. Die
  Bestätigung wird bei geänderter Paarung, Zeit oder Platz ungültig.
- **Vorlagen:** Standardlayouts für Spieltag, Resultat und freie Beiträge;
  Hochformat 1080 × 1350 oder Quadrat 1080 × 1080. Text, Formen, dynamische Felder,
  Bilder und Hintergründe lassen sich verschieben, drehen, skalieren, sperren
  und umsortieren. Zuschneiden und Spiegeln sind nicht destruktiv. Historische
  Versionen lassen sich öffnen und als neue Version speichern. Eine freigegebene
  Grafik wird dadurch nicht verändert.
- **Medien:** JPEG/PNG/WebP bis 8 MB, maximal 16 Megapixel und 6000 Pixel je Seite.
  Der serverseitig normalisierte Bildbestand ohne Metadaten liegt privat
  unter `storage/app/private/social-studio/<tenant>`; Datei und Ausschnitt
  bleiben getrennt. V1 verwendet bewusst nicht das allgemeine Dokumentenprofil.
  Archivierte Medien bleiben für bestehende Layouts lesbar.
- **Beiträge:** Entwurf mit JPEG-Vorschau und separater Freigabe. Änderungen
  setzen den Beitrag auf Entwurf zurück. Beim Freigeben und Senden prüft das
  Backend erneut die gespeicherten Spielangaben. Es übernimmt keine privaten
  Bemerkungen, Garderoben, Aufgebote oder Spielerdaten. Eigene Fotos nur mit
  passender Freigabe verwenden.
- **Rechte:** `social_media` mit Sicht-, Bearbeitungs-, Upload-, Download-,
  Freigabe- und separatem Versandrecht. Bestehende Profile werden nicht
  umgeschrieben; Admin/Geschäftsstelle folgen der bestehenden Rollenlogik.
  Keine Schreib- oder Leseaktionen im Simulationsmodus.

## Meta-Test

Standardmässig deaktiviert. Keine Wiederverwendung der Signage-Lesezugänge.
Nur die genaue HTTPS-DEV-Domain und ein fest konfigurierter ClubUnio-Mandant
können nach expliziter Freigabe an die serverseitig hinterlegten Ziel-IDs senden.
Die Ziel-IDs und OAuth-Tokens müssen ausschliesslich einem isolierten
professionellen Instagram-Testkonto bzw. einer isolierten Facebook-Testseite
zugeordnet und vor dem Einschalten manuell geprüft sein. Kein produktives Ziel
konfigurieren. Es gibt keine im Browser frei wählbare Ziel-ID.

Private Serverkonfiguration: `CLUBUNIO_SOCIAL_META_TEST_ENABLED`,
`CLUBUNIO_SOCIAL_META_TEST_TENANT`, `CLUBUNIO_SOCIAL_META_TEST_IG_ID`,
`CLUBUNIO_SOCIAL_META_TEST_IG_TOKEN`, `CLUBUNIO_SOCIAL_META_TEST_FB_ID`,
`CLUBUNIO_SOCIAL_META_TEST_FB_TOKEN`. Instagram verwendet Instagram Login;
Facebook verwendet einen Page Token. Kein automatischer oder geplanter Versand.
Instagram erhält nur das freigegebene JPEG über eine maximal 15 Minuten
gültige signierte URL, solange die betreffende Testzustellung läuft.
Facebook erhält das JPEG als Upload. Beide Begleittexte tragen den Testhinweis.
Kanalstatus und externe ID werden einzeln gespeichert. Unbestätigte Zustellungen
vor einer manuellen Klärung bei Meta nicht erneut senden.

## Migration und Rückweg

- Genau `2026_11_13_000100_social_studio` ist additiv: sechs neue Tabellen,
  keine Änderungen an Wettkämpfen, Benutzern oder Bestandsdateien.
- `npm ci --ignore-scripts` und `npm run build`; Vite-Build in das
  Releasepaket aufnehmen. Fabric.js ist lokal gebündelt, ohne externes CDN.
- Nach verschlüsselter, verifizierter DEV-Sicherung erzeugt
  `php deploy/emit-vps-dev-social-studio-migration.php clubunio_dev` die
  gezielte VPS-Migration. Einmal als PostgreSQL-Eigentümer gegen
  `clubunio_dev` ausführen; Transaktion und Ledger sind enthalten. Die
  Webrolle erhält nur Tabellenrechte, keine Schema-Erstellrechte.
  Kein Import und keine Migration anderer Instanzen.
- Beim Code-Rollback Tabellen und private Medien erhalten. Die aktuelle
  Storage-Sicherung muss den neuen Ordner einschliessen.

Redaktionskalender, zeitgesteuerter Versand, automatische SFV-Endresultate und
Meta-Kontoverwaltung per OAuth-Oberfläche sind nicht Teil dieser ersten Version.

## DEV-Abnahme (13.11.2026)

- Playwright mit synthetischen Fixtures auf Desktop- und Mobilbreite: Zuschnitt,
  gespeicherte Vorlage, Matchday und Resultat. Beide JPEGs sind nicht leer und
  exakt 1080 × 1350 Pixel gross.
- DEV-Kernel mit angemeldeter synthetischer Sitzung: Seitenrendering, CSRF,
  Mandantenwechsel, kein Export interner Notizen, Upload, Vorlagenrevision,
  Ergebnisbestätigung, Freigabe und gesperrter Meta-Versand. Synthetische
  Daten zurückgerollt und Testbilder entfernt.
- HTTPS: Login, Health und Editor-Bundle erreichbar; Editor und Daten ohne
  Anmeldung gesperrt. Meta-HTTP im automatisierten Test simuliert. Echte
  Meta-Testkonten noch nicht eingerichtet; keine Publikation ausgelöst.

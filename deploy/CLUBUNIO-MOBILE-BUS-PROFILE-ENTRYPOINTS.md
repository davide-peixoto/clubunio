# Bus, Profil, Schluesselbox und Challenge (04.11.2026)

## Korrigierte Ursachen

- Die Busliste lieferte bei leerer Treffermenge `counts: []` statt eines
  JSON-Objekts. Der persoenliche Adapter erwartet zu Recht ein Objekt. Der Server
  serialisiert die Zaehler nun auch ohne Treffer als `{}`; keine Rechteaenderung.
- Bus- und Keybox-Repositories sind an die SDK-Sitzungsgeneration gebunden.
  Nach Konto-/Mandantenwechsel werden alte Optionen, Snapshots und Polls
  verworfen, bevor neue Requests oder Codeanforderungen entstehen koennen.
- Die persoenliche Schluesselboxansicht ist auch bei legalem Leerstand erreichbar.
  `index` prueft den aktuellen Mandantenzutritt ausdruecklich vor der Boxschleife;
  jede vorhandene Box bleibt weiterhin an die bestehenden Einzelrechte gebunden.
  Profilcapabilities erzeugen keine Box, Freigabe oder PIN. Geschlossene Flags
  und widerrufener Zutritt liefern weiterhin keinen Profileinstieg.
- Die Challenge prueft neben dem konfigurierten Vereinsmodul auch das persoenliche
  sichtbare Modulprofil. Ein ausgeblendetes Spiel wird durch den urspruenglichen
  Langdruck-/Loader-Einstieg nicht umgangen.
- Profilwerkzeuge zeigen ausgeschriebene Sprachen und lesbare lokale
  Sitzungsablaufzeiten. Ungueltige Zeiten werden nicht roh dargestellt.
  Sprachwahl passt bei 320 und 390 Pixeln auch mit Portugiesisch. Asynchrone
  Aenderungen pruefen den aktuellen Kontext; doppelte Aktionen sind gesperrt.

## Reale Konfiguration und Shell

Die lesende DEV-Pruefung durch den Releaseverantwortlichen ergab fuer den
betroffenen Verein keine migrierte Keybox und keinen aktiven Katalogeintrag
`Penalty`. Das ist kein Anlass fuer automatische Rechte oder erfundene Daten.
Die Keybox zeigt einen ehrlichen Leerstand. Boxen/Freigaben werden ueber die
bestehende Keyboxverwaltung konfiguriert; physische Igloo-Ausgabe bleibt separat
geschlossen. Ein fehlender `Penalty`-Katalogeintrag erfordert eine ausdrueckliche
Vereinskonfiguration; das vorhandene App-Modulprofil kann nur vorhandene
Katalogoptionen auswaehlen. Diese Korrektur fuehrt keine Konfiguration aus.

Root integriert die praesentationsgebundenen Profilwidgets in die persoenliche
Shell und entfernt die ohne freigegebene Public-Identity-Capability angebotene
Recoverykarte. Die bisherigen Authentifizierungsdienste bleiben unveraendert.
Der originale Spielscreen und der erlaubte Langdruckeinstieg bleiben erhalten.

## Nachweis

- PostgreSQL, ausschliesslich synthetische Rollbackfixtures:
  neue Einstiegsfaelle 3/49, Busregression 2/57, Keybox 6/122,
  Challenge 4/134 (Tests/Assertions); alle bestanden, kein Providerversand.
- Flutter: Profil-/Bus-/Keybox-/Challenge-Shell-Kombination 23 Tests bestanden,
  einschliesslich Originalscreens, leerer Busliste, leerer Keybox, Sitzungswechsel,
  Sprachwahl 320/390 und bestehendem erlaubten Challengeeinstieg.
- Native Geraete- und externe Igloo-/FCM-/SMTP-Abnahmen sind davon getrennt und
  werden hier nicht behauptet. Kein Datenimport, keine echten Codes oder Pushs.

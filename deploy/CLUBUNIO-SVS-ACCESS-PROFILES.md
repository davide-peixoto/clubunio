# SV Sissach: vorbereitete Verwaltungsprofile

Stand Anlage 18.10.2026, Platzwart eingeschraenkt am19.10.2026.
Auf ClubUnio angelegt, **keinem Benutzer zugeordnet**.
Bestehende Benutzer, Rollen, Zuordnungen und Profile unveraendert.

| Profil | ID | Rechteumfang |
| --- | --- | --- |
| Geschäftsstelle | 268 | Mitglieder/Familien/Zuordnungen/Dokumente pflegen, Anmeldungen und Aenderungsantraege entscheiden, Rechnungen/Spesen bearbeiten, Kommunikation und Vorlagen pflegen. Keine Finanzfreigabe, Finanzzustellung, Stornierung oder Bexio-Synchronisation. |
| Geschäftsstelle nur lesen | 269 | Alle 24 aktuellen Module ansehen. Keine Downloads, Exporte oder Mutationen. |
| Finanzen bearbeiten | 270 | Rechnungen, Spesen, Beitraege und Gutschriften bearbeiten, Finanzdateien/Exporte; Mitglieder, Teams und Saisons lesen. Keine Freigabe, Stornierung, Zustellung oder Synchronisation. |
| Finanzen verantworten | 271 | Wie Finanzbearbeitung, zusaetzlich Finanzfreigabe, Versand, Stornierung und Abgleich. Bexio nur ansehen/synchronisieren; keine Verbindungsgeheimnisse. |
| Sportadministration | 272 | Saisons, Teams, Zuordnungen, Trainingszeiten, Planung und Absenzen verwalten; Saisonuebernahme. Mitglieder nur lesen. Keine Finanzrechte. |
| Anlagen & Signage | 273 | Wettkaempfe/SFV-Abgleich, Garderoben, Anlagenstatus, Busentscheide und Signage; Teams/Saisons lesen. Keine Mitgliederverwaltung, Finanzen oder allgemeinen Rundnachrichten. |
| Platzwart | 274 | Ausschliesslich `field_availability.view` und `field_availability.update`. Nur Verfuegbarkeit und Bemerkungen bestehender Plaetze bearbeiten, keine Status-Wertetabelle. |

## Wichtige Grenzen

- Platzwart: Seit19.10.2026 eigenes schmales Modul. Keine Status-Wertetabelle,
  Platzumordnung oder Aktivierung/Deaktivierung. Details und Rollback:
  CLUBUNIO-CARETAKER-SCOPE.md.
- Verwaltungsprofile wirken vereinsweit. Bestehende TL-/Trainer-/Spieler-/
  Elternrollen und deren Teamscopes bleiben bestehen, keine automatische Umstellung.
- Admin bleibt eine separate Systemrolle. Benutzerverwaltung, technische
  Einstellungen und Verbindungsgeheimnisse werden nicht delegiert.
- Bestehende Sandbox-, Zahlungs-, Revisions- und Datenschutzregeln gelten weiter.
  Finanzverantwortung ist kein technisch erzwungenes Vieraugenprinzip.
- Persoenliche Ansichtsfilter bleiben gemaess bestehender Nur-Lesen-Semantik
  erlaubt. Lookupauswahl folgt bestehenden Fachformularen, keine pauschale
  Stammdatenfreigabe fuer schreibende Profile.
- Explizite Liste statt automatisch aller zukuenftigen Funktionen: die genaue
  Modul-/Funktionsmatrix steht in `tools/svs-access-profile-definitions.php`.

## Pruefung und Ausfuehrung

- Definitionstest: 1 Test / 311 Assertions bestanden (Katalog, Abhaengigkeiten,
  Nur-Lesen, Finanztrennung, Platzwart und ausgeschlossene Aktionen).
- Vorschau durch den regulaeren AccessProfileService in einer zurueckgerollten
  Transaktion erfolgreich; anschliessend auf dem ClubUnio-Server ausgefuehrt.
- Sieben aktive Profile, jeweils null Benutzerzuordnungen. Anlage mit Grund
  und beauftragendem Admin im bestehenden Aenderungsprotokoll dokumentiert.
- Fingerprint aller bestehenden Konten-Aktivierungen/Revisionen, Rollen,
  Benutzerzuordnungen und Profile vor/nach Anlage identisch.
- Wiederholte Vorschau: siebenmal `unchanged`, keine Dubletten oder Updates.

## Migrationsplan / Wiederholung

Keine Schema- oder Anwendungsmigration. Vereinsbezogene Einrichtung auf der
separaten ClubUnio-Datenbank, kein Import aus PHPMaker. Serverbasis bei Anlage:
`20261018-clubunio-training-performance`. Kein Releasewechsel notwendig.

CLI-Helfer `tools/create-svs-access-profiles.php` und Definitionsdatei gemeinsam
bereitstellen, `CLUBUNIO_BASE` auf die bestehende Installation setzen.
Standard ist `--preview` (Rollback), nur `--apply` schreibt. Der Helfer prueft
DB, Vereinskennung, aktiven Admin und unveraenderte bestehende Rechte.
Gleichnamige abweichende Profile werden niemals ueberschrieben: kompletter
Rollback. Spaetere bewusste Anpassungen ueber Rollen & Berechtigungen vornehmen.
Bei einem kuenftigen Datenbankwechsel Profile ueber das Zielsystem bewusst
anlegen; obige IDs nicht als portable Kennungen verwenden. Keine automatische
Benutzerzuordnung als Teil einer Migration.

PHPMaker DEV/PROD, API, SaaS und Betrieb unveraendert. Keine echten Nachrichten,
Bankzahlungen oder externen Buchungen. Kein Cache-Reset erforderlich.

# Mitgliederantraege und Dokumentverwaltung: Release59

## Web-Fachweg

- Portal: Mitglieder > Stiftsymbol (nur eigene Identitaet/berechtigte minderjaehrige
  Kinder) > Aenderungsantrag. Keine Schreibrechte allein durch Trainer-/TL-Sicht.
- Beantragbar: Namen, Geburtsdatum, Adresse, Laender-/Geschlechtslookups,
  Kontakt-/Elterndaten, Rechnungsempfaenger. Keine Rollen, Status, Austritt,
  Passnummer, IBAN, Mitgliedernummer, Finanzflags oder technischen IDs.
- Verwaltung > Verein > Mitgliederaenderungsantraege: Alt-/Neuvergleich mit
  Lookupnamen, Statusfilter, Suche, Genehmigen/Ablehnen mit Rueckmeldung.
- Dashboard mit Anzahl offener Mitgliederaenderungsantraege und Direktlink.
- Ein offener Antrag pro Mitglied; Rueckzug durch Antragsteller, erneute
  Einreichung nach Abschluss. Ablehnung braucht Begruendung.
- Antragssnapshot verschluesselt; Audit enthaelt Operation/IDs/Revision, keine
  beantragten Personendaten. Das etablierte Mitgliederaudit bleibt erhalten.
- Genehmigung prueft Identitaets-/Elternrecht und aktiven Mitgliedsstatus erneut.
  Jede zwischenzeitliche Mitgliederaenderung sperrt die Uebernahme. Kein blindes
  Ueberschreiben: ablehnen und aktualisiert neu erfassen lassen. Ablehnung bleibt
  auch nach Rechteentzug moeglich. Entscheidungen revisionsgeschuetzt/atomar.

## Dokumente

- Verwaltung > Mitgliederdokumente sowie Mitglieder > Details > Anhaenge:
  Upload, Dokumenttyp aus aktiver Hilfstabelle, Vorschau/Download,
  Portal-Freigabe und Aktivstatus. Pflege ausschliesslich Admin/Geschaeftsstelle.
- PDF/JPEG/PNG/WebP; Filament maximal12MiB, Service maximal20MiB.
  MIME aus Inhalt, UUID mit passender Erweiterung, Originalname nur Metadatum,
  SHA256 und Dublettenschutz pro Mitglied/Typ, Ersteller/Revision/Audit.
- Separater privater Azure-Praefix:
  `clubunio/members/{tenant_id}/{member_id}/{uuid}.{ext}` im bestehenden privaten
  Container. Keine oeffentlichen SAS-Links, keine lokalen Fallbacks.
- Bestehende Dokumente bleiben alle standardmaessig intern. Keine automatische
  Migration/Veroeffentlichung von Identitaetsdokumenten. Registrierungspfade und
  urspruengliche PHPMaker-Dateien unveraendert.
- Im Portal nur aktiv+explizit freigegeben+aktuelles Vertretungsrecht.
  Deaktivierung entzieht Zugriff, bewahrt Datei und Historie. Kein physisches
  Loeschen/Ersetzen. Neue Version per neuem Upload und Deaktivieren des alten.
- Upload vor DB-Commit: scheitert danach die DB, kann ein unreferenzierter privater
  Blob verbleiben. Keine automatische Loeschroutine; kontrolliert abgleichen.

## Nachrichten und Grenzen

Vier neue bearbeitbare Push-Vorlagen: member.change.requested/approved/rejected
und member.document.available. Persistente interne Notifications an Verwaltung,
Antragsteller bzw. berechtigte Dokumentempfaenger; keine personenbezogenen
Aenderungsgruende im Nachrichtentext. Keine externe FCM-Zustellung, keine Mails.
Es gibt keine neuen App-API-Endpunkte. API/SaaS/Betrieb und PHPMaker DEV/PROD
bleiben unveraendert. Manuelle Ankuendigungen/Quittungsfachvertrag separat offen.

## Migration / Ruecksprung

Eigene PostgreSQL-Migration59: member_change_statuses (vier Statuswerte),
member_change_requests mit zusammengesetzten eigenen Fremdschluesseln,
partiellem Pending-Uniqueindex; Dokumentrevision/Hash/Ersteller/portal_visible.
Nur eigene ClubUnio-DB db_acdf8d_dclubora / Schema clubora.
Keine Altantraege importiert, keine Bestandsdateien verschoben.
Vor spaeterem Cutover Antraege/Links/Dokumentfreigaben fachlich abgleichen und
neuen Produktionssnapshot separat planen; kein Rueckschreiben nach PHPMaker.
Release58 ist mit den additiven Feldern kompatibel; Migration nicht blind down.

## Abnahme

Urspruengliche Transaktionstests mit synthetischen Daten und gemocktem Azure.
Nachtest15.10.2026: Spieler-/Eltern-Webtests und echter privater Azure-PUT/GET
erfolgreich, inklusive Zugriffsentzug und bestaetigter Testdatei-Bereinigung.
Details, Testgrenzen und Wiederholung: CLUBUNIO-MEMBER-ROLE-AZURE-ACCEPTANCE.md.
Angemeldete Verwaltungspruefung15.10.2026 erfolgt: Dashboard-Zaehler,
Antragsliste mit Standardfilter In Pruefung, Dokumentliste mit70 Eintraegen,
Upload-SlideOver, geladene Dokumenttypen und Freigabemaske erreichbar.
Portal-Freigabe standardmaessig aus; alle Masken ohne Speicherung geschlossen.
Keine echten Mitgliederdaten oder Dokumentberechtigungen veraendert.
Kein offener Antrag vorhanden: Genehmigungsansicht nur automatisiert geprueft.
Spieler-/Elternrollen wurden per Laravel-HTTP-Testkernel authentifiziert und
geprueft, Azure dabei real angesprochen. Kein manueller Rollen-Browsertest
oder externer HTTPS-Upload ueber den Pi-Reverse-Proxy wird damit behauptet.
44 Tests/426 Assertions erfolgreich:33/320 fuer Antraege/Dokumente/Anmeldungen,
Azure-Adapter, PMP-Inventar und Navigation,11/106 fuer bestehende Mitgliederdossiers,
Dashboard und Mitgliederregeln. Routen-/View-Cache erfolgreich erstellt.
189 verschluesselte Einstellungen und46 Vorlagen verifiziert, Outbound aus.
Fachrelease auf ClubUnio-Pi:20261015-clubunio-59-member-changes, Ruecksprung58.
Bestandszaehlung unveraendert:2438 Mitglieder,69 Anmeldungen,70 Konten.
HTTP nach Deployment:Login200, Verwaltungslisten/Portal ohne Anmeldung302.
SHA256 der beiden neuen Fachservices und Webrouten identisch lokal/Pi.
Die Verwaltungspruefung ersetzt keine vollstaendige Rollen-/End-to-End-Abnahme.

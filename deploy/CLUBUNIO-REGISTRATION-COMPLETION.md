# Fachpaket57: Mitgliederanmeldungen und Wiedereintritt

Stand 15.10.2026. Nur ClubUnio-Web und eigene PostgreSQL-Datenbank.
PHPMaker DEV/PROD, App-API, SaaS und Betrieb bleiben unveraendert.

## Bedienung

Verein > Mitgliederanmeldungen bietet Erfassung, Formulardaten, Details,
Dublettenpruefung, Anhaenge, Pruefung und Benachrichtigungsprotokoll.
Statusfarben verwenden die gemeinsame, bearbeitbare Farbzuordnung.
Statusknopfe mit Anzahl sowie kombinierte Suche/Filter und eigene gespeicherte
Ansichten bleiben erhalten. Sortierung nach Eingangsdatum statt leerer Alt-ID.

Aktive Statuswerte werden aus registration_statuses mit workflow_key gelesen:
submitted, review, trial, pool, accepted, rejected. Namen bleiben pflegbar.
Pruefung kann ohne externe E-Mail erfolgen. Die Geschaeftsstelle ordnet in
Pruefung einen aktiven TL zu; dieser erhaelt eine interne Benachrichtigung.
TL sehen nur direkt zugewiesene oder ihren Teams zugehoerige Anmeldungen im
Webportal. Sie duerfen Status und berechtigte aktive Mannschaft wechseln,
aber keine Antragstellerdaten, fremde Mannschaften oder TL-Zuweisung aendern.
Probetraining verlangt eine Mannschaft. Akzeptierte Anmeldungen sind geschlossen.

## Annahme und Wiedereintritt

Moegliche Dubletten werden anhand Name/Geburtsdatum oder E-Mail gefunden,
einschliesslich ausgetretener, nicht archivierter Mitglieder. Keine automatische
Zusammenfuehrung. Die Verwaltung waehlt und bestaetigt den vorhandenen Datensatz;
sein Versionshash verhindert eine veraltete Ueberschreibung. TL muessen einen
Dublettenfall zur Klaerung an die Geschaeftsstelle geben.

Neue Angaben ersetzen die entsprechenden Anmeldefelder des Mitglieds.
Genau ein aktiver Status mit restores_membership ist erforderlich. Dieser
wird gesetzt und exit_date geleert. Mitglied-ID, Passnummer, Bexio-Verknuepfung,
administrative/finanzielle Merkmale und bestehende Untertabellen bleiben erhalten.
Eine aktive Spielerzuordnung zur gewaehlten Mannschaft wird wiederverwendet
oder neu angelegt. Historische, etwa mit Rechnungen verknuepfte Zuordnungen
werden weder umgehaengt noch reaktiviert. Annahme, Dokumentverknuepfung,
Notizen, Mannschaft, Elternvorschlaege, Audit und Ereignisse sind atomar.

Bei Minderjaehrigen werden eindeutige vorhandene aktive Konten mit passendem
Eltern-Benutzernamen als pending-Elternzuordnung vorgeschlagen. Es entstehen
dadurch keine Leserechte. Freigabe erfolgt unter Verwaltung > Benutzerzuordnungen
mit Begruendung und Alters-/Mitgliedschaftspruefung. Bestehende genehmigte oder
widerrufene Zuordnungen bleiben unveraendert. Keine neuen Konten oder automatische
Freigabe allein aufgrund einer E-Mail-Uebereinstimmung.

## Anhaenge

Neue Uploads ausschliesslich im privaten Azure-Container svs-prod-private unter
clubunio/registrations/{tenant_id}/{registration_id}/{uuid}.{ext}. Kein Schreiben
in bestehende PHPMaker-Verzeichnisse, kein lokaler Fallback, keine oeffentlichen
Blob-URLs. SDK-signierte Create-SAS bleibt serverseitig; If-None-Match verhindert
Ueberschreiben. Originalname, MIME, Groesse, SHA256 und Bearbeiter werden erfasst.
Webupload maximal12 MiB, Service maximal20 MiB; PDF/JPEG/PNG/WebP, Foto ohne PDF.
Mehrere Ausweise sind moeglich; pro Anmeldung ein aktives Passfoto.
Identischer Inhalt wird je Dokumentart nicht doppelt erfasst. Entfernen
deaktiviert nur Metadaten, die Datei bleibt erhalten. Nach Annahme gesperrt.
Ausweise werden je Datei zu member_documents verknuepft, Passfoto separat.
Lesen bleibt autorisiert, nicht zwischengespeichert und ohne externe SAS-Ausgabe.
Fehler enthalten nur eine Referenz; keine signierte URL oder Providerantwort.
Ein Azure-Erfolg mit anschliessendem DB-Fehler kann eine unverknuepfte UUID-Datei
zuruecklassen. Sie wird nicht angezeigt und niemals automatisch geloescht.

## E-Mail, Push und Nachvollziehbarkeit

Vorlagen unter Nachrichtenvorlagen sind versioniert bearbeitbar.
Pool erzeugt Wartelisten-E-Mail, Probetraining eine Einladung mit vorgesehenem
Staff-CC. Annahme/Ablehnung bereiten E-Mail und Staff-Benachrichtigung vor.
TL-Pruefung und neue Anmeldung sind interne Benachrichtigungen, keine E-Mail
an den Interessenten bei Status Pruefung. Ereignis-/Vorlagensnapshot bleibt
verschluesselt; Protokoll zeigt Revision, Zustand, Zeit und Fehlercode.
Externes FCM wird mit diesem Paket nicht aktiviert.

Die Aktion E-Mail testen versendet nur genau das ausgewaehlte ready-Ereignis,
nie eine globale Warteschlange. Staging/Testschalter und TestMailGuard erzwingen
admin@svsissach.ch, ohne echte To/CC/BCC. Bei deaktiviertem Versand kein falscher
Erfolg. SMTP-Annahme ist kein Zustellnachweis. sending/failed wird nicht blind
wiederholt; zuerst pruefen. Die Aktion wird mit Bearbeiter protokolliert.

## Migration und weitere Uebernahme

Additive eigene Migration57: Dokument-Metadaten und Annahmezusammenfassung,
Index der Pruefwarteschlange. Bestehende Anmeldungen werden nicht nachtraeglich
akzeptiert oder veraendert, keine PHPMaker-Schemaaenderung. Bereits akzeptierte
Altdaten erhalten keine erfundenen Uebernahmeprotokolle. Ein spaeterer neuer
Produktionssnapshot und seine Konfliktklaerung bleiben eigener Migrationsschritt.
Keine Ablosung der bestehenden Anwendungen. Ruecksprung auf Release56 bleibt
moeglich; additive Daten erhalten, kein automatisches Down/Loeschen.

Azure-Vertrag: https://learn.microsoft.com/en-us/rest/api/storageservices/put-blob

## Pruefung und Deployment

Pi-Release: 20261015-clubunio-57-registration-completion. Ruecksprung56 erhalten.
Eigene Migration57 angewendet; sechs Workflow-Schluessel und genau ein
Wiedereintrittsstatus auf dem Pi bestaetigt. 69 bestehende Anmeldungen unveraendert.
189 verschluesselte Einstellungen/42 Vorlagen beim Deployment geprueft,
Outbound aus, keine echten Empfaenger oder FCM aktiviert.

Gezielte Regression: Anmeldung/Wiedereintritt, Rollen, Dubletten, atomarer
Rollback, Mannschaftshistorie, Elternvorschlaege/Widerruf, Uploadfehler,
Azure-Create-SAS/Ueberschreibschutz, Testmail-Sperre und Navigation.
Azure-PUT-Vertrag automatisiert mit HTTP-Doubles geprueft; kein echter Ausweis
als Test hochgeladen. Angemeldete Desktoppruefung: Liste, Pruef-/Uploadmaske,
Statusfilter und anschliessende Suche erfolgreich, ohne Datenaenderungen.

Ergebnis: 36 Tests /252 Assertions in zwei gezielten Laeufen erfolgreich
(22/142 plus14/110), einschliesslich aktivem Routen-Cache.

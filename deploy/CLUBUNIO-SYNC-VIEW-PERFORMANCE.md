# ClubUnio: Synchronisationsuebersicht und Protokolle

18.10.2026. Nur ClubUnio-Web; API, SaaS, Betrieb und bestehendes PHPMaker
DEV/PROD unveraendert. Keine externen Abgleiche, Wiederholungen oder Zustellung
waehrend der Pruefung. Keine bestehenden Auftraege oder Historien veraendert.

## Umsetzung

Die Uebersicht liest weiterhin20 Auftraege, Statuszaehler und dieselben Filter
mit stabiler updated_at-/ID-Sortierung. Nur sichtbare Metadaten selektieren;
lease_until/revision bleiben fuer die vorhandenen Aktionsbedingungen erhalten.
Keine Lease-Tokens, Verbindungs-Hashes oder Deduplizierungsschluessel laden.
Payload nur fuer SFV-Zeitraum, KI-Thema oder Azure-Objektkontext selektieren
und entschluesseln; fuer andere Operationen bleibt die Anzeige ohne Payload.
Die technische Ausfuehrung liest weiterhin den unveraenderten Originalpayload.

Kein Vorabladen/Entschluesseln aller sync_work_events beim Seitenaufruf.
Verlauf per Schaltflaeche fuer genau einen Auftrag oeffnen:20 neueste Ereignisse
mit separat benannter historyPage-Pagination und Gesamtzahl. Jeder aeltere
Eintrag bleibt erreichbar; niemals abschneiden, zusammenfassen oder loeschen.
Notizen nur fuer die geladene Seite entschluesseln und HTML-escaped anzeigen.
Zeitpunkt, Ereignis, Akteur bzw. oeffentliche Anmeldung, Fehlercode und
manuelle Begruendung bleiben erhalten. Fehlerklassifikation/Audit unveraendert.

Auswahl-ID ist Livewire-Locked und wird ausschliesslich serverseitig gesetzt.
Beim Oeffnen frische SyncWorkService-Adminpruefung und Vereinsbindung des
Auftrags. Bei jedem Render mit offener Historie erneut frische Autorisierung;
nur Elternauftrag aus der aktuellen, vereinsgebundenen Listenseite verwenden.
Keine oeffentlichen Livewire-Arrays mit entschluesselten Notizen. Filter-/
Hauptseitenwechsel schliesst den Verlauf und setzt dessen Seite zurueck.
Auftragwechsel beginnt wieder auf Seite1. Keine Rechte-/Historiencaches.

Retry, manuelles Abschliessen, Uebernahme offener Protokolle, Leases,
Revisionen, Idempotenz, Referenzen und Provider-Zustandsermittlung bleiben
unveraendert im bestehenden Fachservice. Kein neuer Scheduler, Worker,
HTTP-Endpunkt oder Zugriff fuer weitere Rollen.

## Tests und Messung

tools/clubunio-sync-view-performance-check.php erzeugt synthetischen Verein,
Admin,20 Auftraege und2000 verschluesselte Verlaufseintraege. Misst nur die
Datenaufbereitung der Seite, kein HTTP-/View-/Browser-Gesamtbenchmark.
Ausgabe aggregiert: Zeit, Abfragen, geladene Ereignisse und serialisierte
Datenbytes. HTTP gesperrt, Mail gefaked; Transaktion im finally zurueckrollen.
PostgreSQL-Sequenzen koennen Luecken erhalten; kein bestehender Auftrag beruehrt.

Pi vorher: Listenaufbereitung512ms/7 Queries/437ms DB,2000 Ereignisse,
4312288 Bytes. Zweiter Durchlauf415ms/7 Queries/346ms DB, gleiche Datenmenge.
Nach Delta: Listenaufbereitung192ms/6 Queries/167ms DB,0 Ereignisse,
4313 Bytes. Ein Verlauf offen303ms/11 Queries/299ms DB,20 Ereignisse,
46572 Bytes. Rund62% weniger Einstiegszeit in dieser Stichprobe und rund99,9%
weniger geladene Listenbytes. Oeffnen benoetigt bewusst zusaetzliche frische
Rechte-/Seitenzaehlpruefungen statt einer unbeschraenkten Sammelabfrage.
Einzelstichproben, kein Lasttest/SLA; nicht mit kompletter Webladezeit verwechseln.

Fuenf neue Featuretests/43 Assertions erfolgreich: keine Ereignisabfrage beim
Einstieg;20er-Pagination und alle46 Fixture-Eintraege erreichbar; Auswahlwechsel,
Filter und Hauptpagination; ungenutzter Payload und technische Tokens nicht
geladen; Fremdverein404, Rechteentzug403, clientseitige Auswahlmanipulation
abgewiesen. Auftragsrevision und Ereignisanzahl durch Lesezugriffe unveraendert.

Acht gezielte Bestandsfaelle/43 Assertions ebenfalls erfolgreich: Fehler/
Wiederholung, aktive/abgelaufene Lease, Revision, geaenderte Verbindung,
unbestaetigtes Providerergebnis, verschluesselter manueller Abschluss,
Fremdverein/Rolle/deaktiviertes Konto und idempotente Historienuebernahme.
Insgesamt13 fachliche Tests in getrennten Laeufen erfolgreich. Pint, PHP-Syntax,
Routen-/Viewcache sowie Tenant-/Dokument-/Profilchecks erfolgreich.

Release20261018-clubunio-sync-view-performance aktiv. Angemeldete Chrome-
Pruefung: neun bestehende erfolgreiche Auftraege sichtbar, Verlauf von Auftrag149
mit fuenf Ereignissen/zwei Versuchen einzeln geoeffnet, Zeitpunkte/Akteur korrekt.
Verbindungsfilter schliesst den Verlauf, leere Auswahl korrekt; keine erfassten
Browserfehler. Keine echte Wiederholung/Uebernahme/Abschlussaktion ausgefuehrt.

Bekannter unabhaengiger Bestands-Testbefund: SyncWorkTest::test_queue_page_
renders_and_test_pages_are_not_navigation_items erwartet fuer BexioDispatchTrials
keinen Menueeintrag. Die bestehende Klasse hat shouldRegisterNavigation=true
(Bexio-Versandpruefung). Das Rendering der Synchronisationsseite in diesem Test
ist erfolgreich; nur die alte Navigationsassertion scheitert. Keine produktive
Navigation oder Berechtigung zur Beseitigung dieser Erwartung veraendert.

## Migration und Rollback

Keine Schema-/Indexmigration, Settingsaenderung, Datenuebernahme oder Retention.
Seite und Blade gemeinsam gezielt bereitstellen, Caches und Guardchecks vor
Aktivierung. Ruecksprungbasis20261018-clubunio-training-performance; keine
Datenbankruecknahme erforderlich. Keine .env/Secrets/Uploads/vendor oder
Laufzeitdateien im Commit. Historische Auftrags-/Ereignis-IDs bei spaeteren
Migrationen unveraendert uebernehmen.

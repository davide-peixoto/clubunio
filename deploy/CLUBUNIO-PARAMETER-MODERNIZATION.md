# Integrationsbereinigung und profilbasierte App-Module

03.11.2026. ClubUnio lokal/Pi, kein PHPMaker DEV/PROD-Eingriff.

## Migrationsschritt000092

Nach Benutzerfreigabe werden107 explizit gepruefte Parameter entfernt:
15 WhatsApp/Chatwoot inklusive Begleitwerte,63 n8n-Werte,15 alte SFV-Werte,
12 alte Bexio-Sync-/Cronwerte und BEXIO_ACCOUNT_ID/BEXIO_OWNER_ID.
Verbindliche Allowlist mit Bereich: RetiredIntegrationParameters::KEYS.
Keine Praefixloeschung. Der Erstimport ueberspringt dieselbe Allowlist.
n8n wird spaeter neu evaluiert, nicht als bereits migrierte Integration behandelt.

Bexio-Pruefung lokal und im deployten app/config/routes-Code: kein Leser fuer
die entfernten Werte. Dynamische BEXIO_-Schluessel im PayloadBuilder gesondert
geprueft. OIDC_CLIENT/SECRET/SCOPES/CONNECTION und16 aktuelle Bexio-Werte bleiben.
Insbesondere BANK_ACCOUNT_ID/USER_ID sind nicht ACCOUNT_ID/OWNER_ID.
SFV_API_BASE_URL/APPLICATION_KEY/APPLICATION_PASS/CLUB_ID/LANGUAGE/SEASON_ID/
SEASON_ID_AUTO bleiben. Der Import authentisiert frisch und liest keinen der
alten SFV_SYNC_*, Cron- oder gespeicherten User-Tokenwerte.

Sicherung vor Loeschung: storage/app/private/migration-backups/
retired-integrations-<Zeit>-<Zufall>.json, Werte weiterhin verschluesselt,
einschliesslich setting_changes. Laenge/SHA256 geprueft,0600, Transaktion.
Rueckweg nur gepruefte Wiederherstellung settings dann changes; IDs und
Bereich/Schluessel vorher auf Konflikte pruefen. Kein automatisches down.

## Bewusst erhalten

FCM_*: fuer spaetere ClubUnio-DEV-Pushtests erhalten. Damit noch kein Firebase-
Versand aktiviert; aktive Provideranbindung und App-Tokenfluss sind ein eigenes
Abnahmepaket. PHPMaker-Push bleibt unberuehrt.
OPENAI_EXPENSE_*: derzeit kein Leser im neuen Backend. Die parallele lokale
OCR-Entwicklung nutzt Azure Document Intelligence / config/clubunio-expense-ocr.php.
Diese noch separat bearbeitete Entwicklung wird hier nicht mit deployed und
ihre Betriebsfreigabe nicht behauptet. Alte Parameter bis OCR-Abnahme erhalten.
OPENAI_API_KEY und OPENAI_TRAINING_MODEL/VECTOR_STORE_ID bleiben aktiv verwendet.
CRON_HUB_*, Instagram-Zusatzwerte und sonstige nicht freigegebene Kandidaten
bleiben erhalten. Keine fremden Arbeitsdateien mit aufnehmen.

## Migrationsschritt000093: App-Module nach neuen Profilen

Neue Lookup-Tabelle mobile_module_options(tenant_id,key,name,active,sort_order),
mit10 bestehenden App-Modulen pro vorhandenem Verein initialisiert.
access_profiles.mobile_modules: null = automatisch, [] = keine optionalen
App-Module, Liste = gezielte Auswahl. Bestehende Profile bleiben null.
Im Profildialog Such-/Mehrfachauswahl, inklusive Kopieren, Revision und Audit.

tenant_accounts.mobile_profile_id ist eine optionale, mandantengesicherte
Zuordnung eines dieser Profile fuer die App-Anzeige. Benutzerverwaltung bietet
dafuer den separaten Dialog App-Modulprofil mit Begruendung/Revision/Audit.
Bestehende Zuordnungen bleiben leer; kein Konto und keine Berechtigung werden
automatisch geaendert. Kein Wechsel der bisherigen Systemrolle erforderlich.
Systemrolle profile erhaelt hierdurch insbesondere NICHT automatisch Zugang
zum Mitgliederportal oder mobilen Login. Die bestehende Zugangskontrolle bleibt.

modules-Antwort: Schnittmenge aus aktivem Lookup, zugeordneter Profilauswahl,
bisherigen Datenrechten und bestehenden App-Faehigkeiten. Profil bleibt immer
verfuegbar. Ein ausgeblendetes Modul ist keine Berechtigungssperre; direkte
Endpunkte pruefen weiterhin ihre Rechte. Ein ausgewaehltes Modul erteilt keine
Rechte und aktiviert kein noch unimplementiertes natives App-Modul.
Deaktiviertes zugeordnetes Profil liefert keine optionalen Module.
Nicht-zugeordnete Benutzer behalten die bisherige berechtigungsbasierte Auswahl.

Die alten ModulesTrainer/ModulesSpieler/ModulesTechnischeLeiter werden als
abgeloester Altbestand schreibgeschuetzt angezeigt. Unter Einstellungen fuehrt
App-Module nach Profil zur neuen Auswahl. Andere alte App-Parameter werden
nicht mit wirkungslosen Dropdowns als aktive Steuerung ausgegeben; ihre
Wartungs-/Registrierungs-/Schlossfunktionen beduerfen eigener Anbindung.

Erweiterung: neuer App-Modulschluessel mit Verein/Label in den Lookup aufnehmen,
Backend-Zuordnung und App-Faehigkeit implementieren, dann in Profilen auswaehlen.
Allein ein neuer Lookupwert erfindet keine API/Funktion. Bei zukuenftiger
Vereinsanlage den Katalog mit initialisieren. Kein SaaS-Rollout dieses Pakets.

## Ausfuehrung und Tests

Nur000092 und000093 gezielt mit --database=clubunio --path=... --force ausfuehren.
Keine parallelen000088/000089 oder unbekannten Migrationen mit ausfuehren.
Lokale Instanz/Pi teilen dieselbe DB: additive000093 einmal vor Regressionstests,
Bereinigung000092 beim gezielten Pi-Release. Code-Rollback darf die additiven
Spalten behalten; vor Entfernung ausgewaehlte Profile/Zuordnungen sichern.

ParameterModernizationTest: Rollback-Fixtures fuer Allowlist, Erhalt von FCM/SFV/
KI, idempotente Bereinigung, Profilauswahl/Audit, bestehende Rechte, automatische
Auswahl, Lookup, Zuweisung/Revisionsschutz und Adminpflicht. Kein realer Versand.

Abnahme03.11.2026: ParameterModernizationTest + SettingOptionsTest11 Tests/80
Assertions; AccessProfileTest6 Tests/51 Assertions erfolgreich. Initiale
Parallellauefe mit DDL-/Fixture-Sperrkonflikten, danach Migration93 mit
Tenant-first-Sperrreihenfolge erfolgreich und beide Testsuiten erneut gruen.
Angemeldete Sichtpruefung offen: IAB-Verbindung unavailable, Chrome-CDP Timeout.
Keine erfolgreiche visuelle Abnahme behauptet und keine echten Profile geaendert.

Release115a932 aktiv auf20261103-clubunio-parameter-modernization, vorheriger
Release20261103-clubunio-retired-parameters bleibt vorhanden. Migration93 lokal
auf gemeinsamer DB,92 beim Pi-Release erfolgreich. Fuenf Servergates gruen;
0 Altparameter verbleibend,10 Moduloptionen,16 Bexio-Werte,4 FCM-Werte,
0 explizite App-Profilzuordnungen. Login HTTP200, PHP-Lint der neuen Oberflaechen
und des Zuordnungsservices erfolgreich. Sicherung auf gemeinsamem Storage
retired-integrations-20261103214628-4a90f30d5806.json mit Dateimodus0600.
Env, Composer-Lock, Shared-Storage-Verknuepfung identisch; Config-/Route-/View-
Caches neu aufgebaut. Nur gezieltes Commit-Delta deployt, keine fremden lokalen
App-/OCR-/Trainingsdateien, keine PHPMaker-DEV-/PROD-Aktion und kein Versand.

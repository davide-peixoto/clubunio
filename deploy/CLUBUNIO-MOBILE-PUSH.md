# Persoenliche Pushanbindung

Nachlauf05.11.2026: Serviceaccount privat installiert und iOS-Registry fuer
ClubUnio vorbereitet. Google OAuth funktioniert; beide Pushflags weiterhin
false, eigenes Konto ohne Geraetebindung. Kein FCM-/APNs-Versandnachweis.
Aktueller Konfigurationsstand: CLUBUNIO-FIREBASE-CREDENTIALS.md.

Aktueller DEV-Nachweis04.11.2026: Identity-/Push-/Challenge-/Keybox-Unterbau ist
auf DEV,400/500/600/700 atomar nach gepruefter verschluesselter Sicherung
angewendet.14 leere neue Tabellen/30 FKs, Elternzeilen unveraendert; nur
zusaetzliche Session-Unique wie geplant. Runtime-/Mail-/Zustellungs-/PINflags
bleiben geschlossen; externe Konfiguration/Abnahme und weitere Integration
bleiben offen. Details: PI.md und CLUBUNIO-MOBILE-IDENTITY-PUSH-SCHEMA.md.
Fruehere Vorbereitungsangaben unten sind historisch, keine aktuelle Aussage
ueber den inzwischen bestaetigten Schema-/DEV-Stand.


Stand:04.11.2026. Folgepaket nach den nativen Markenbuilds, in Umsetzung.

Migration500 ist ausschliesslich unter
`database/clubunio/2026_11_04_000500_mobile_push_devices.php` reserviert.
Additives Schema vorbereitet, nicht angewendet; kein Verzeichnis-Migrationslauf.
Root koordiniert spaeter nur feste gepruefte Einzelpfade. Vorher session-Spalten,
Indizes, Zeilendigest und Backup pruefen. Einzig bestehende DDL-Aenderung ist
UNIQUE(id,account_id) auf mobile_sessions; keine Sitzungsdaten werden geaendert.
Vier neue private Tabellen: mobile_push_devices, mobile_push_bindings,
mobile_push_outbox und mobile_push_commands. Kein automatisches down():
Wiederherstellung bedarf geprueften Restores; Sitzungs-/Zustellhistorie erhalten.

Vor Umsetzung werden vorhandene Chat-/Rechnungs-/Inboxdienste und Original-
SVS-Pushablaeufe abgeglichen. Persoenliche Geraetebindung und FCMv1 bleiben
standardmaessig geschlossen; keine Legacy-Pushendpunkte und keine echten Pushs
oder Provideraufrufe durch Tests/Tools. Konto-/Mandantenwechsel, Widerruf,
Logout/Reset und Tokenrotation gehoeren zum Vertrag. Pushpayloads duerfen keine
privaten Nachrichtentexte, Rechnungsbetraege, Tokens oder Ziel-URLs enthalten.
Native FCM-/APNs-/Geraeteabnahme bleibt eine getrennte externe Pruefung.

## Sourcevertrag und bisheriger Nachweis

Eigene Config clubunio-mobile-push: enabled und delivery_enabled=false,
leere operatorgepruefte Appregistry. Exakte native Kennung/Plattform/HTTPS-Origin
und feste Tenant-ID werden serverseitig geprueft, inklusive aktueller verifizierter
Domainzuordnung beim Versand. Clientwerte koennen keine Registry anlegen.

PUT push/devices bindet ausschliesslich das persoenlich angemeldete Konto und
seine frische Sitzung. Installations-UUID plus geheimer Installationsnachweis,
verschluesselter FCM-Token, Tokenhash, Geraete-/Bindungsversion. Der Nachweis liegt
im nativen SecureStorage pro Origin. POST push/devices/status ermoeglicht den
lesenden Versionsabgleich nach unklarem Ergebnis, Nachweis ausschliesslich JSON.
DELETE widerruft nur die eigene Bindung; weitere gespeicherte Konten bleiben
erhalten. Session-Widerruf/Ablauf, Kontosperre, Rechteentzug und Tokenrotation
werden vor Versand erneut geprueft. Kein beliebiger Empfaenger-/Textsendepfad.

ChatService und bestaetigte InvoiceNotifications erzeugen innerhalb ihrer
vorhandenen Transaktion ausschliesslich Outboxzeilen. Geschlossenes Feature
instanziiert keinen Pushdienst. Bestehende Inbox-/Replay-/Rechte bleiben erhalten.
Kaderquelle leitet aktuelle DABEI-Spieler und genehmigte Selbst-/Elternkonten ab;
keine Gaeste ohne Konto, kein freier Empfaengerparameter. Export- UND Update-Recht,
Aufgebotssnapshot, Empfaengerfingerprint und Caller-UUID sind erforderlich.

Worker persistiert einen Versandclaim vor HTTP. Danach frische private Rechte,
Sitzung, Bindungsversion und Appdomain. FCMv1-Antwort bedeutet nur provider_accepted,
keine bestaetigte Geraetezustellung. Timeout/Crash/unklarer Erfolg wird attention,
nicht automatisch erneut versendet. Abgelaufene Claims bleiben zur Pruefung.
Payload enthaelt ausschliesslich Version/Konto/Mandant/Zielart/Objekt/ZustellUUID
und allgemeine Hinweistexte. Keine Rechnungsbetraege, Mitgliedernamen oder Chats.

Offizielles google/auth exakt1.55.3 (PHP^8.1, lokal8.3) mit gezielter Composer-
Aufloesung --no-scripts: genau3 neue Pakete, keine bestehenden Paketversionen
geaendert; firebase/php-jwt7.2.1, psr/cache3.0.0. composer audit --locked:0 Hinweise.
Credentialdatei ausschliesslich operatorseitig, festes Google-Tokenziel und
firebase.messaging-Scope, keine ADC-/Metadaten-/Remotecredential-Fallbacks.
Quellen: [Google-Tag](https://github.com/googleapis/google-auth-library-php/tree/v1.55.3),
[FCMv1](https://firebase.google.com/docs/cloud-messaging/send/v1-api),
[Flutter-Empfang](https://firebase.google.com/docs/cloud-messaging/flutter/receive-messages).

Lokale Providerfaelle ohneDB:6 Tests/11 Assertions, echte synthetische RSA-
Signierung durch google/auth, ausschliesslich Http::fake; OAuth/Sendeziel,
ID-Payload, Timeout ohneRetry, UNREGISTERED und fremde TokenURI abgedeckt.
Persoenliche PostgreSQL-HTTP-Faelle:8 Tests/78 Assertions, ausschliesslich
TEMP-500-Tabellen mit synthetischen FK-Spiegeln und Transaktionsrollback;
mehrere Konten, CompositeFK, Origin, Verschluesselung, Rotation/Widerruf,
Outboxdedupe und unsicherer Claim abgedeckt. Flutter-Umschlag:2 Faelle gruen.
Nach Erweiterung:13 PostgreSQL-Faelle/150 Assertions gruen, anschliessend5 gezielte
G2-/Workerfaelle/69 Assertions gruen. Echter Source-Busauftrag, frische Aufloesung,
Widerruf, getrennte fehlende Dokumentquellen, Originalnotifications-Pagination,
Wasserzeichen und spaeter eintreffende ungelesene Mitteilung, echte persoenliche
Sourceinhalte, Kaderaenderung vor Zustellung und UNREGISTERED-Geraetesperre.
Keine realen Providerkontakte.500 weiterhin nicht dauerhaft angewendet.

## Vollstaendige persoenliche Ziele

Typen: chat/announcement, invoice, planning, expense, member, member_change,
registration, bus, document_receipt, document_file, document_archive und penalty.
Dokumentarten haben getrennte ID-Namensraeume und gehen durch die bestehenden
persoenlichen Dokumentendienste. Aenderungsantraege pruefen Sicht auf das echte
Mitglied und zusaetzlich Reviewrecht oder eigenen genehmigten Vertretungszugriff.
Bus-/Planungs-/Finanzziele beachten den aktuellen persoenlichen Saisonscope auch
im CLI-Worker, mit frischer Parameteraufloesung statt langem Requestcache.

MobilePushSources verarbeitet ausschliesslich reale ClubUnio-notifications
(ExpenseMessageService, RegistrationMessageService, BusReservationService,
MemberChangeService, TeamPlanningService). Kein PHPMaker-Fallback. Der Worker
uebernimmt nur Quellen der letzten24h, die nach einer aktiven Bindung entstanden;
keine nachtraegliche Pushflut fuer neu angemeldete Geraete. Dokumenttypen sind
als echte Fachziele vorbereitet; vorhandene Dienste erzeugen dafuer noch keinen
entsprechenden notifications-Trigger, deshalb wird keiner erfunden.
Challenge600 liefert einen eigenen dauerhaften Resetauftrag mit verschluesseltem
Text. Nach Commit wird nur seine ID an MobilePushSources::challenge gegeben;
der Worker kann offene Quellen nachziehen. Frische MobilePenaltyAccess::notice-
Rechte entscheiden pro Empfaenger, keine frei waehlbare Broadcastliste.

Kaderpublikation bindet angezeigte Event-/Teilnahmestand und Empfaengerfingerprint
bis in Originalmodell, Originalscreen und persoenlichen Adapter. Ungewisser
POST behaelt CallerUUID, erfolgreicher neuer Benutzerauftrag bekommt neueUUID.
Outboxevent bindet zusaetzlich den Autor. Vor Zustellung werden Publizierrecht,
voller Snapshot und aktueller Empfaenger erneut verglichen; geaendertes Aufgebot
wird verworfen. Abgesagte Termine bleiben als normale persoenliche Fachziele
sichtbar, ihre alten Kaderpublikationen werden nicht nachtraeglich versandt.

## Originalnotifications ohne Providerabhaengigkeit

GET clubs/{tenant}/notifications?start=0&recperpage=20&unreadOnly=0|1 liefert
notifications/unreadCount/totalRecordCount/watermark, eigenes GET unread-count.
POST notifications/{id}/read und notifications/read-all mit through_id. Read-all
prueft nur eigene aktuell autorisierte IDs bis zum geladenen Watermark; spaetere
Meldungen bleiben ungelesen. Bestehendes inbox behaelt sein altes Antwortformat.
G2 indexiert ausschliesslich eigene echte sichtbare notifications als ID-Verweis
in mobile_inbox, kopiert keine private Payload. Persoenlich geladene Titel/Texte
kommen nur aus der exakt passenden eigenen Sourcezeile bzw. entschluesseltem
autorisiertem Challengeauftrag, sonst ehrlich generisch. Lesestatus eigener
Quellen wird mitgefuehrt. Kein Versand beim Abruf, keine Abhaengigkeit von
Pushoptin oder FCM-Freischaltung. Stummschaltung verhindert Zustellung, entzieht
aber keine persoenlichen Leserechte an bereits vorhandenen Mitteilungen.

## Aktivierung und externe Abnahme

Neue mobile API-Routen werden ueber MobilePushRoutes::registerAccount/Tenant
unter bestehender persoenlicher Authentifizierung registriert. Rein lesende
Fachzielaufloesung/Notifications brauchen keine500-Tabelle; Geraeteregistrierung,
Outbox und Publisher bleiben geschlossen. Aktuelle App-Kontexte/Planungsrechte
zeigen Push nur bei personal-push-v1, beiden Serverflags und tatsaechlich
lesbarer/validierter serverseitiger Registry-/OAuthkonfiguration.

Worker ist ausschliesslich expliziter Operatoraufruf
clubunio:deliver-mobile-push --dispatch --limit=20 (max100). Ohne --dispatch oder
Flags kein Versand. Kein Scheduler eingeschaltet. Claims werden vor HTTP
committed, Geschaeftstransaktionen duerfen den echten Worker nicht aufrufen.
UNREGISTERED sperrt das Geraet und erhoeht Revision; derselbe alte Token kann
die Sperre nicht aufheben. Nur eine echte native Tokenrotation darf neu binden.

Clientdetails: mobile/docs/PUSH.md. Fehlende Registry-/FCM-/APNs-Konfiguration,
APNs-Entitlements/Provisioning und echte Initial-/Foreground-/Tap-Abnahme auf
Android/iOS bleiben explizit offen. Keine Storeveroeffentlichung oder echten
Pushs durch Tools, kein erfolgreicher Pushrelease behauptet.


## 05.11.2026: Persönliches iOS-Push auf DEV freigeschaltet

Auf ausdrücklichen Auftrag beide Pushflags true; vorhandene iOS-Core-Registry
und übrige Konfiguration byte-/arraygeprüft erhalten. www-data-Capability true,
unsupported Clients weiter false. Neuer Minutentimer/oneshotwww-data-Worker,
max10 Einträge und kein Parallellauf. Vorher0 Geräte/Bindungen/Outbox, erster
Lauf erfolgreich0 verarbeitet, kein künstlicher Testversand. FPM aktiv,
HTTPSup200/context401. Kein Runtime-/DB-/PHPMakerwechsel, noch keine physische
FCM-/APNs-Zustellungsabnahme. CLUBUNIO-DEV-PUSH-ACTIVATION.md.

# Persoenliche Spesenmigration

## Umsetzung03.11.2026

Die bestehenden SVS-Masken fuer Spesenliste, Belegdetails, neue Belege,
Positionseditor, Bankverwaltung und Beleganzeige verwenden einen injizierbaren
persoenlichen ExpenseRepository. Kein PHPMaker-/Integrationstoken-Fallback.
ClubUnio-Standarddesign bleibt zentral im App-Theme.

MobileExpenseRoutes::register() gehoert in die bestehende persoenliche
clubs/{tenant}-Gruppe. Alle Aktionen werden zusaetzlich durch
native_expenses_enabled geschuetzt; Quellstandardfalse. Vor Aktivierung ist
Migration88 erforderlich. Sie erzeugt ausschliesslich zwei neue Tabellen,
mobile_expense_receipts und mobile_expense_commands; keine vorhandenen Daten
werden aktualisiert oder importiert. Permanente Anwendung/DEV-Aktivierung
werden durch den Hauptauftrag koordiniert, nicht durch diese lokale Umsetzung.

## Rechte und Fachablaeufe

ExpenseService, BankAccountService, MemberAccessService und der persoenliche
aktuelle Saisonscope bestimmen alle Sicht-/Schreibrechte. Staff beantragt fuer
die eigene Mitgliedschaft und Mannschaft; Office mit entsprechendem Recht
erhaelt die aktuelle Mannschaftsauswahl. Technische Leitung sieht zustaendige
Belege und prueft Freigaben, erhaelt daraus aber keine fremde IBAN.
Bankdaten im Belegdetail werden nur bei unabhaengigem BankAccountService-Zugriff
ausgegeben. Kein AHV-, Storage- oder signierter Providerlink im DTO.

Vorhandene Statusuebergaenge bleiben versioniert, mit ausdruecklicher Begruendung
von10-500 Zeichen. Die App bietet nur serverseitig erlaubte Ziele an. Ein neuer
mobiler Write auf Bezahlt/B existiert nicht; bestehende bezahlte Belege bleiben
lesbar. Keine echte Bankzahlung oder Bexio-Zahlungsaktion.

Ein alter Formularstand behaelt seine angezeigten Beleg-/Positions-/Bankversionen.
Hintergrundleser duerfen sie nicht gegen eine neuere Version austauschen.
Entfernte Positionen/Bankkonten werden in dieser Repository-Instanz nicht durch
spaete Antworten wieder schreibbar. Neue Belegkoepfe und Positionen besitzen
persoenliche Caller-UUIDs, auch bei manueller Erfassung ohne Datei. Gleiche
tenant/account/UUID+Operation+normalisierte Werte liefern dieselben echten IDs;
abweichende Wiederholungen ergeben409. Ledger enthaelt nur Hashes/Referenzen,
keine IBAN, Notizen, Dateinamen oder Bytes. Der Client behaelt den UUID bei
ungewissem Ergebnis, bestaetigte neue Aktionen erhalten einen neuen UUID.
Es gibt keine automatische Schreibwiederholung.

## Belegentwuerfe und Wiederaufnahme

Der originale Vorab-Upload funktioniert ueber einen eigenen privaten Entwurf:
tenant+account+ClientUUID, maximal5MiB vor Lesen/Verschluesselung, PDF/JPEG/PNG,
Header-/Bildgroessenpruefung und den vorhandenen MalwareScanner. Nur eine
tatsaechliche clean-Pruefung mit Scannerversion wird gespeichert. Unbekannter
Scannerzustand503 und Schadcode422 legen keinen nutzbaren Entwurf an.
Dateiname und Bytes sind verschluesselt; Hash/MIME/Name-Abweichung beim selben
UUID ergibt409. Ein ungebundener Entwurf laeuft nach24 Stunden ab.
Ein Upload wird nur fuer den tatsaechlich konfigurierten privaten Azure-Mandanten
angeboten; andere Mandanten erhalten vor der Entwurfsanlage503.

Position und Belegbindung entstehen atomar unter Mandanten-/Sitzungssperre.
Der vorhandene DocumentUploadService uebernimmt die Datei versioniert; danach
werden die doppelten Cipherbytes im mobilen Entwurf entfernt. Gebundene Tokens
koennen nicht als neue Position wiederverwendet werden. Die anschliessende
Azure-Uebertragung laeuft ueber den bestehenden SyncWork-Prozess ausserhalb
der Bindungstransaktion. published und pending werden als echte Zustaende
ausgegeben. Pending blockiert weitere Positionsaenderungen und Freigaben,
damit die gebundene Elternrevision erhalten bleibt.

Der bestehende Detailheader zeigt offene Belege und erlaubt dem berechtigten
Uploader eine ausdrueckliche Wiederaufnahme. POST
expenses/{expense}/uploads/{upload}/retry prueft erneut persoenliche Sitzung,
Beleg-/Positionsscope und Upload-Eigentuemer beziehungsweise bestehende
Sync-Adminrechte. Private Dateien werden ueber den vorhandenen
MemberDocumentService gelesen; SAS/Storage-URLs bleiben auf dem Server.

Das automatisch erkannte Artisan-Kommando
clubunio:expire-mobile-expense-receipts entfernt abgelaufene clean-Cipherbytes.
Nur tatsaechlich betroffene Mandanten werden kurz gesperrt; UUID-Tombstones und
gebundene Finanzreferenzen bleiben erhalten. Scheduler nach Migration88:
stuendlich, withoutOverlapping. Keine automatische down-/Refresh-Aktion;
Rueckweg schliesst den nativen Schalter, neue Tabellen bleiben erhalten.

## Nachweise und offene Abnahme

Eigene Tests: tests/Feature/MobileExpensesTest.php sowie
mobile/test/personal_expense_repository_test.dart. PostgreSQL-Pruefung mit
synthetischen zurueckgerollten Fixtures, einschliesslich echter Servicepolicies,
Migration88 auf privaten leeren temporaeren Tabellen, Versions-/Scopesperren,
Idempotenz, Scanner/Ablauf, atomarer Bindung, Pending/Retry/Publish und privaten
Dateirechten. Providergrenze ausschliesslich synthetisch, keine echten Uploads,
Zahlungen oder E-Mails. Flutter prueft die originalen Listen-/Bank-/Positionsmasken,
persoenlichen Transport, ungewisse UUIDs und unbestaetigte Antworten.

Finaler Backendnachweis:3 Tests/129 Assertions bestanden. Produktive Original-
Index-/Optionsrouten delegieren bei nativer Freigabe nachweislich korrekt;
bei geschlossenem Schalter bleiben Legacy-Lesen200 und native Writes503.
Migration88 fuehrt
in den Tests nur DDL fuer leere private Temp-Tabellen aus, einschliesslich
tenantgebundener Ergebnis-FKs und echter Service-/Workflowaktionen.
Flutter:18 gezielte Faelle bestanden (7 neue persoenliche Adapter-/Original-
Maskenfaelle sowie11 bestehende Teamoptionen-/Dateiregressionen).

Der persoenliche optionale OCR-Vertrag ist separat implementiert und lokal
geprueft: CLUBUNIO-MOBILE-EXPENSE-OCR.md. Ohne ausdruecklich gepruefte
Mandanten-/Anbieter-/Kostenkonfiguration bleibt er503; der Originaleditor ist
manuell bedienbar. Analyse erfordert Einwilligung und Vorschauuebernahme. HEIC-Dateiuebernahme, reale persoenliche Azure-
Uebertragung auf DEV und native Kamera-/Datei-/Gesamtabnahme sind noch offen.
Diese Grenzen verhindern eine pauschale Vollabnahme des Spesenpakets.

Release-Gate:14 native gesperrte Routen plus2 vorhandene persoenliche Leserouten.
Index/Optionen behalten bei geschlossenem nativen Schalter den bisherigen
Lesevertrag; bei Freigabe delegieren sie an die nativen DTO-/Optionsdienste.

## Synthetisches DEV-Funktionsgate

`php deploy/check-mobile-expenses-functional.php --synthetic-rollback --foundation`
verlangt CLI, exakt `https://dev.clubunio.ch`, die freigegebene PostgreSQL-Datenbank
und den Migration88-Nachweis. Es verwendet echten HTTP-Kernel und persoenlichen
Login sowie echte lokale Bank-/Spesen-/Statusdienste. Synthetische Mandanten,
Konten und Datensaetze werden vollstaendig zurueckgerollt.

Foundation prueft die weiterhin erreichbaren Original-Leserouten und geschlossene
Writes. Danach wird der native Ablauf ausschliesslich im Gateprozess fuer seine
synthetischen Daten freigeschaltet und anschliessend zurueckgesetzt. Dies ist
keine Live-Aktivierungsabnahme. `--enabled` verlangt stattdessen eine bereits
aktivierte native Konfiguration. Geprueft werden Entwurf, CallerUUID-Replay,
Position, Stale-Versionen, persoenliche Bankgrenzen und Trainer-/TL-Statusrechte.
StatusB bleibt gesperrt; es erfolgt keine Zahlung.

Fremd-HTTP, Mailtransport und externe Blob-/Azure-/Bexio-Clients werden gesperrt.
Das Gate uebt bewusst keinen Upload aus und behauptet keine Providerabnahme.
Scanner/Upload/Blob und echte Geraete bleiben gesondert abzunehmen. Quelle ist
vorbereitet/lintgeprueft; Ausfuehrung erst im abgestimmten seriellen PG-Fenster.
Keine doppelten GET-Routen, die sich je nach Registrierungsfolge ueberschreiben.

DEV-Freigabe04.11.2026:20261103-clubunio-native-followups-v4,
persoenliche Originalvertraege aktiviert. Synthetische reale Kernelablaeufe
mit Rollback erfolgreich; externe Provider-/Dateigroessen-/Geraeteabnahme
weiter offen. Gemeinsamer Auslieferungsnachweis in PI.md.

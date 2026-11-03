# Mobile: bestehender SVS-Login, Profil und Menue

Stand03.11.2026. Benutzerauftrag: bestehende SVS-App vollstaendig migrieren,
mit Login/Profil/Modulmenue beginnen und offene Backendteile deployen.
Dies ist das erste Kernpaket, keine abgeschlossene Gesamtmigration.

## Backendvertrag

Neue MobileAccountController-Endpunkte auf /api/mobile/v1:
GET profile, POST password und GET clubs/{tenant}/modules. Vorhandene
MobileMiddleware fuer Featureflag, HTTPS-/Domain-/Mandantengrenze, persoenliche
Sitzung und Limits unveraendert. Profil nur eigenes Konto ohne Secrets.
Passwort verlangt aktuelles Passwort,12 Zeichen, maximal72 UTF-8-Bytes und
Bestaetigung; Kontozeile sperren, bestehendes Hashing und alle eigenen mobilen
Sitzungen widerrufen. Login sperrt dieselbe Zeile und prueft das aktuelle Hash
erneut. Rate-Limit fuer Passwortversuche. Keine fremden Konten veraendert.

Modulkatalog verwendet frische ModulePermissionService- und MobileAccess-
Teamrechte. Noch nicht persoenlich migrierte Module sind als available=false
gekennzeichnet; der Drawer darf daraus keine Arbeitsfaehigkeit ableiten.
Keine neuen Rechte, Provideraufrufe, echte Zahlungen oder PHPMaker-Aenderungen.

## Bereitstellung

Aktiv: /var/www/svs-laravel/releases/20261103-clubunio-mobile-account-shell.
Rueckweg: /var/www/svs-laravel/releases/20261103-clubunio-member-access.
Die parallele Mitgliederzugriffsbereitstellung wurde vor dem Umschalten erkannt
und nach normalisiertem Quellvergleich als Basis erhalten. Alle anderen lokalen
Arbeitsdateien/Importvorbereitungen sind ausgeschlossen.

Explizites6-Dateien-Archiv/Manifest ohne Konfiguration, Uploads oder vendor:
MobileAccountController, MobileController, MobileSessions, routes/mobile.php,
check-mobile-account-shell.php und check-mobile-account-functional.php.
Sechs Serverhashes nach Abschluss identisch. Keine neue Migration, kein Import.
Bestehende Env/Storage/Schluessel/Composer-Lock erhalten; Konfigurations-/Routen-
und Viewcache releasebezogen erstellt. Zwoelf Servergates erfolgreich.

Replay-Werkzeug release-clubunio-mobile-account-shell.sh verweigert vorhandenes
Ziel und geaenderten Ausgangsrelease. Rueckweg durch vorigen Code und FPM-Reload,
keine DB-Rueckmigration. Bereits erfolgte Passwortwechsel werden nicht rueckgaengig.

## Nachweis und Grenzen

38 lokale Tests/369 Assertions bestanden, darunter Profilgrenze, falsches
aktuelles Passwort, Widerruf mehrerer eigener Sitzungen, unbeeinflusstes anderes
Konto, erneuter Login und fremder Mandant. Lokale Haupttests nutzen isoliertes
SQLite mit bereits dokumentierter Dialektanpassung.

Zusaetzlich check-mobile-account-functional.php --synthetic-rollback auf echtem
DEV-PostgreSQL ausgefuehrt: synthetischer Account, persoenlicher Login, eigenes
Profil,11 Admin-Menueeintraege, Passwortwechsel, alte Sitzung401 und neuer Login.
Gesamte Testtransaktion zurueckgerollt; kein Testkonto oder Testtoken gespeichert.
Das prueft den echten HTTP-Kernel, nicht einen nativen Benutzerlogin.

HTTPS-Proben ohne Credentials: profile401, clubs/1/modules401, password401,
Weblogin200. Kein neuer App-Geraetebuild, keine native Push-/Biometrieabnahme.
Registrierung/Reset, Mehrkonten und weitere Fachadapter bleiben offen; genaue
App-Luecken in mobile/docs/SVS-ACCOUNT-SHELL-MIGRATION.md.

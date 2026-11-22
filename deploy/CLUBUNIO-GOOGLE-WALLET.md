# Google Wallet: Einrichtung

Stand22.11.2026. Apple Wallet ist separat eingerichtet. Google Wallet ist noch
nicht im Backend/Flutter implementiert und noch nicht für Mitglieder verfügbar.

## Aussteller und bestehendes Cloud-Projekt

Benutzer hat das Google-Geschäftsprofil ausgefüllt/gespeichert und die
Aussteller-ID `3388000000023233410` bereitgestellt. In Chrome wurde unter dem
aktuellen Konto genau ein ClubUnio-Projekt gefunden: `clubunio-e8d01` (ClubUnio).
Keine neuen Cloud-Projekte angelegt; drei Umgebungsidentitäten im bestehenden
Projekt verwendet. Google Wallet API und Firebase Cloud Messaging API zeigen
jeweils **API Enabled**. Keine Aktivierung anderer APIs vorgenommen.

## Dienstkonten

| Zweck | E-Mail | Stand |
|---|---|---|
| Wallet DEV | `clubunio-wallet-dev@clubunio-e8d01.iam.gserviceaccount.com` | Benutzer angelegt, Bestand geprüft |
| Wallet Stage | `clubunio-wallet-stage@clubunio-e8d01.iam.gserviceaccount.com` | In Chrome erstellt und Liste geprüft |
| Wallet PROD | `clubunio-wallet-prod@clubunio-e8d01.iam.gserviceaccount.com` | In Chrome erstellt und Liste geprüft |
| Push PROD | `clubunio-prod-push@clubunio-e8d01.iam.gserviceaccount.com` | FCM-Rolle bestätigt, Schlüssel eingerichtet, Providerprüfung200 |

Walletkonten ohne zusätzliche Cloud-Projektrollen erstellt. Die drei Konten
sind nun in der Google Pay & Wallet Console als Developer eingetragen und
einzeln als solche in der Benutzerliste bestätigt. PROD-Push ist vom Walletkonto getrennt. Erteilte Pushrolle:
`roles/firebasecloudmessaging.admin` (Firebase Cloud Messaging API admin).
Die ausdrückliche Benutzerbestätigung liegt vor; IAM meldete Policy updated und
zeigt die Rolle am PROD-Push-Konto. Für die drei Walletkonten und das separate
Pushkonto je einen JSON-Schlüssel erstellt. Keine echte Testnachricht versandt.

Ein Cloud-Projekt und ein Aussteller mit drei Dienstkonten schaffen keine harte
Aussteller-/IAM-Isolation zwischen Umgebungen. Künftige Integration muss
Umgebungs-/Vereinsgrenzen durch getrennte Konfigurationen, Class-/Object-IDs,
Mandantenautorisierung und getrennte sichere Schlüsselablage durchsetzen.
Vereinsbuilds verwenden die freigegebene Umgebung mit autoritativem Vereinslogo
und Vereinsfarben, keine eigene Codekopie oder pauschale Fremdvereinsfreigabe.

## Nächste Schritte

1. Google-Wallet-Backend, Klassen/Objekte, Status-/Widerrufupdates, mobile
   Hinzufügen-Aktion, QR-Liveprüfung und DE/EN-Texte implementieren/testen.
2. Demo-Testkonten, Google-Publishingfreigabe und echte Android-Geräteabnahme.
3. PROD-Push auf einem angemeldeten autorisierten Testgerät prüfen. Servereinrichtung
   und Provider-Dry-Run sind noch kein Nachweis einer realen Gerätezustellung.

## Schlüssel und tatsächlich geprüfte Zugänge

Auf ausdrücklichen Auftrag je einen Google-JSON-Schlüssel erzeugt. Lokale
Sicherung unter `C:/ClubUnio-Secrets/google-wallet/{dev,stage,prod,prod-push}.json`;
Ordner-ACL ohne geerbte Freigaben, nur Administrators und SYSTEM. Chromes
untergeordneter Speicherdialog war nicht zuverlässig steuerbar. Jeweils die
bereits vollständig heruntergeladenen temporären JSON-Daten anhand Typ,
Projekt, genauer Dienstkonto-E-Mail und Token-URL geprüft, exklusiv in die
geschützte Ablage kopiert und anschliessend den redundanten Speicherdialog
verlassen. Keine zusätzliche Schlüsselausstellung, keine Geheimnisse ausgegeben.

Private Serverdateien:

| Zweck | Serverpfad | Eigentümer |
|---|---|---|
| Wallet DEV | `/var/lib/clubunio-google-wallet/dev/service-account.json` | www-data |
| Wallet Stage | `/home/runcloud/google-wallet/stage/service-account.json` | runcloud |
| Wallet PROD | `/home/runcloud/google-wallet/prod/service-account.json` | runcloud |
| FCM PROD | `/home/runcloud/firebase/prod/service-account.json` | runcloud |

Ordner0700, Dateien0600, ausserhalb aller Webroots/Git/Appassets. DEV via SSH,
Stage/PROD ebenfalls verschlüsselt übertragen. Alle drei Wallet-Prüfungen als
Appbenutzer erfolgreich: OAuth und GET genericClass für den oben genannten
Aussteller200, keine Klassen-/Passerstellung oder Datensatzänderung.
Expliziter Operatorbefehl: `php deploy/check-google-wallet-credentials.php --verify dev|stage|prod`.
Er prüft Umgebung, Datei/600, Konto/Projekt/Tokenziel/RSA und gibt nur technische
Statuswerte aus. Die private Schlüsselablage macht Google Wallet noch nicht
in der App verfügbar; die fachliche Integration folgt separat.

## PROD-Push aktiviert

FCM-Prüfbefehl `php deploy/check-prod-push-credentials.php --verify` verwendet
die eigene PROD-Identität, OAuth-Scope firebase.messaging und ausschliesslich
`validate_only=true` mit synthetischem Topic. ErgebnisHTTP200, null Zustellungen.
Kein Gerätetoken, Token oder Nachrichteninhalt in der Ausgabe.

PROD hatte beide Pushflagsfalse und eine leere Registry. Nach privater Sicherung
beide Flags aktiviert. Zwei gezielte Core-Einträge:
`ios:com.my.clubunio.core` und `android:com.my.clubunio.core`, jeweils
Origin `https://app.clubunio.com`, Projekt clubunio-e8d01, kein fester Mandant,
dedizierte PROD-Credentials. Kein SV-Sissach-Testbuild auf PROD freigegeben;
künftige produktive Vereinsbuilds benötigen ihre exakte Kennung und eine frisch
verifizierte Vereinsdomain-/Mandantenbindung.

Auch der globale authentifizierte Mobile-Zugang war auf PROD ausgeschaltet,
wodurch die Geräteregistrierung503 lieferte. Als notwendige Voraussetzung der
beauftragten Push-Aktivierung `CLUBUNIO_MOBILE_ENABLED=true` gesetzt. Keine
zusätzlichen Modul-/Rollen-/Registrierungsrechte erteilt. /up200 und context401
ohne Login auf DEV/Stage/PROD geprüft; frische Core-Registryprüfung iOS/Android
und Push-Capability auf PRODtrue.

Der Live-Stand unterstützte bereits die Plattformpräfixe und zusätzliche
Stage-Pilotbedingungen. Diesen Stand nicht mit lokalen älteren Dateien
überschrieben. Gemeinsame Präfixunterstützung gezielt im eigenen Sourcebestand
nachgezogen; Legacy-Registry bleibt kompatibel. Auf PROD nur eine zusätzliche
Native-ID-Eingabeprüfung gegen gefälschte interne Präfixe eingefügt; übrige
Live-Abweichungen erhalten. Tests für gemeinsame native ID, Legacy-Fallback,
falsche Plattform/Origin, Vereinsbindung, Configvalidierung und Capability.

Hintergrunddienst [clubunio-prod-mobile-push.service](clubunio-prod-mobile-push.service)
installiert, systemd-verify bestanden, enabled/active/running als runcloud.
Ein Prozess mit flock, Polling und 300s Watch, Neustart1s, limit100,
TimeoutStop45s, private temporäre Dateien, schreibbarer Bereich nur Runtimecache/
Logs. Expliziter erster Workeraufruf verarbeitete0 Einträge; Bestand vor
Aktivierung:0 Geräte/0 Outbox. Keine künstliche Testbindung oder echte Nachricht.

Private Rückwege:

- `/root/prod-mobile-push-activation-20261122-212403`: Konfiguration vor Pushflags/Registry.
- `/root/prod-mobile-api-activation-20261122-212547`: vor Mobile-Basisfreigabe.
- `/root/prod-push-native-id-guard-20261122-212802`: vorherige Registryklasse.

Zum Abschalten zuerst Worker stoppen/deaktivieren, Push-Deliveryflagfalse und
Konfigurationscache als runcloud erneuern. Private Konfigurationssicherungen
gezielt vergleichen; keine anderen inzwischen geänderten Werte überschreiben.
Schlüssel bei Rotation erst nach erfolgreichem Austausch und Prüfung im
Google-Portal sperren; keine Geräte-/Outboxhistorie löschen.

Abnahme: drei echte Wallet-OAuth-/API-Prüfungen, echte FCM-Dry-Run-Prüfung200,
14 lokale Tests/35Assertions erfolgreich; PHP-Lint der Operatorbefehle,
laufender Dienst und HTTPS geprüft. Physische FCM-/APNs-Zustellung und native
Firebase-Konfiguration auf dem Benutzergerät bleiben zu prüfen. Kein Nachweis
einer Storeveröffentlichung oder Google-Publishingfreigabe.

Referenzen: [Google REST-Einrichtung](https://developers.google.com/wallet/generic/getting-started/auth/rest),
[Firebase-FCM-Rollen](https://firebase.google.com/docs/projects/iam/roles-predefined-product).

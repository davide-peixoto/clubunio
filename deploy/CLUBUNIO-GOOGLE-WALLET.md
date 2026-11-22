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
| Push PROD | `clubunio-prod-push@clubunio-e8d01.iam.gserviceaccount.com` | Erstellt; FCM-Rollenfreigabe noch offen |

Walletkonten ohne zusätzliche Cloud-Projektrollen und ohne Schlüssel erstellt.
Die notwendige Wallet-Entwicklerberechtigung wird separat in der Google Pay &
Wallet Console beim Aussteller erteilt; sie ist noch nicht als eingerichtet
nachgewiesen. PROD-Push ist vom Walletkonto getrennt. Vorgesehene Pushrolle:
`roles/firebasecloudmessaging.admin` (Firebase Cloud Messaging API admin).
Ausgewählt, aber vor Speichern explizite Bestätigung angefragt; noch nicht als
erteilt melden. Keine Schlüssel erzeugt, kein PROD-Push auf dem Server aktiviert
und keine Testnachricht versandt.

Ein Cloud-Projekt und ein Aussteller mit drei Dienstkonten schaffen keine harte
Aussteller-/IAM-Isolation zwischen Umgebungen. Künftige Integration muss
Umgebungs-/Vereinsgrenzen durch getrennte Konfigurationen, Class-/Object-IDs,
Mandantenautorisierung und getrennte sichere Schlüsselablage durchsetzen.
Vereinsbuilds verwenden die freigegebene Umgebung mit autoritativem Vereinslogo
und Vereinsfarben, keine eigene Codekopie oder pauschale Fremdvereinsfreigabe.

## Nächste Schritte

1. Ausstehende FCM-Rollenzuweisung bestätigen und in IAM prüfen.
2. Drei Wallet-Dienstkonten in der Wallet Console als Developer autorisieren.
3. Private Serverablage vorbereiten und erforderliche Schlüssel getrennt erzeugen/
   übertragen. Keine JSON-Schlüssel in Chat, Repository, Appassets oder Webroot.
4. Google-Wallet-Backend, Klassen/Objekte, Status-/Widerrufupdates, mobile
   Hinzufügen-Aktion, QR-Liveprüfung und DE/EN-Texte implementieren/testen.
5. Demo-Testkonten, Google-Publishingfreigabe und echte Android-Geräteabnahme.
6. PROD-Push-Schlüssel/Serverkonfiguration gesondert einrichten und auf einem
   autorisierten Testgerät prüfen. Kontenerstellung allein ist kein Pushbetrieb.

Referenzen: [Google REST-Einrichtung](https://developers.google.com/wallet/generic/getting-started/auth/rest),
[Firebase-FCM-Rollen](https://firebase.google.com/docs/projects/iam/roles-predefined-product).

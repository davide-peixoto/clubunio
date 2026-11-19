# My SV Sissach Test – Build- und Anmeldestand

Stand 19.11.2026.

## Kennungen und Umgebungen

Das gemeinsame Profil `sv_sissach_test` verwendet Version `2.0.0+1`.
Die iOS-Kennung ist `com.my.sportvereinSissach.test`, die Android-Kennung
`com.my.sportvereinsissach.test`. DEV ist an
`https://dev-clubunio.svsissach.ch` und Mandant 4 gebunden. Stage verwendet
`https://stage-clubunio.svsissach.ch` und Mandant 3.

## Firebase und Push

Beide nativen Testkennungen sind im bestehenden Firebase-Projekt
`clubunio-e8d01` als My SV Sissach Test registriert. Die SDK-Konfigurationen
liegen privat unter `C:/ClubUnio-Secrets/firebase/sv-sissach-test/`.
Der Buildparameter `--firebase-config` prüft die native Kennung und übernimmt
die Clientwerte in den ignorierten Buildordner.

In der Firebase-Konsole fehlen für diese iOS-App sowohl Development- als
auch Production-APNs-Schlüssel. Apple Developer verlangt eine Anmeldung.
Der Benutzer hat am 19.11.2026 ausdrücklich angewiesen, hier nichts weiter
zu ändern. APNs und die Ende-zu-Ende-Pushabnahme bleiben offen.

Die vorhandene DEV-Pushregistry enthält die iOS-Core-App. Stage enthält die
beiden Core-Plattformen und hat den Provider-Versand ausgeschaltet. Für die
neuen SV-Sissach-Testkennungen fehlen die scoped Servereinträge. Diese
Konfiguration wurde bei dieser Prüfung unverändert vorgefunden.

## Geräte-Login und aktueller Fehler

Die HTTPS-Bootstraps auf beiden Customdomains liefern HTTP 200, den jeweils
richtigen Mandanten sowie `loginAllowed=true` und
`keycloakLoginAvailable=true`. Der native Pairingbeginn liefert auf beiden
Origins HTTP 200. Das prüft den Servereinstieg.

Beim vom Benutzer gemeldeten Stage-Versuch am 19.11.2026 wurde die
Keycloak-Kopplung abgeschlossen und um 07:53:34 UTC eine mobile Sitzung
erstellt. Sie wurde um 07:53:35 UTC wieder widerrufen. Der gespeicherte
federierte Sicherheitsnachweis entspricht dem aktuellen Konto. Ein interner
lesender Kontextaufruf für dasselbe Konto liefert HTTP 200, Mandant 3 und
eine gültige DE/EN-Sprachfreigabe. Das beweist noch keinen erfolgreichen
HTTP-Kontextabruf aus der betroffenen App.

Der Screenshot zeigt ausschliesslich die allgemeine Anmeldefehlermeldung.
Die konkrete Ursache ist noch offen. Der neue Appstand ergänzt einen
begrenzten Diagnosecode mit dem betroffenen Schritt, etwa `context/http_503`
oder `storage/KeychainError`. Freie Fehlermeldungen, Callback-URLs, Tokens
und native Fehlerdetails werden dabei nicht angezeigt. Abbruch und
Sitzungswiderruf bleiben erhalten. Für die Ursachenbehebung ist der neue
Diagnosecode oder die Flutter-Konsolenmeldung des betroffenen Macs nötig.

## Mac-Build und Prüfung

Der Benutzer übernimmt Mac-Build und Geräteprüfung selbst. Es besteht kein
Mac-Fernzugang aus diesem Arbeitsplatz. Das Buildtool unterstützt nun einen
signierten iOS-Releasebuild über `--ios-sign`. Es prüft Version, Kennung,
Markenvertrag, Signatur und APNs-Entitlement. Anleitung und genaue Befehle
stehen in `mobile/branding/README.md` im separaten Mobile-Repository.

Lokale Prüfungen umfassen 13 Pythonfälle für Branding/Firebase/Signatur und
Simulatorstart, davon ein Bash-Launcher-Test unter Windows übersprungen,
zwölf Flutterfälle für Markenvertrag, Pairing und redigierte Fehlerdiagnose
sowie den zentralen DE/EN-Katalogcheck mit 2990 Schlüsseln. Ein Android-
Debugbuild mit der passenden Firebase-Clientdatei wurde erzeugt und seine
native Identität geprüft. Die iOS-Version 2.0.0 (1), Geräte-Logins und echte
Pushzustellung sind weiterhin auf dem Mac bzw. iPhone zu verifizieren.

Die im Mobile-Remote inzwischen ergänzten Simulatorbefehle sind übernommen.
Nach `git pull` im Mobile-Checkout startet `./test-app svs` den vorhandenen
SV-Sissach-Simulatorablauf. Der neue Diagnosecode wird bei einer erneuten
fehlgeschlagenen Anmeldung auf dem Startscreen angezeigt.

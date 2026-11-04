# Persoenliche Registrierung, Zugang und Biometrie

Stand04.11.2026: **lokaler Aufbau, noch kein fertiger Release**. Auth400 ist
reserviert und sichtbar, nicht dauerhaft angewendet. Alle neuen oeffentlichen
Funktionen, Mandanten-/Originlisten und der separate Identity-Mailversand
sind standardmaessig geschlossen. Keine echten E-Mails oder Providerabnahme.
Commit/Sync/DEV und permanente Migrationen koordiniert ausschliesslich Root.

## Vertraege

Neue PublicIdentity-Routen sind bewusst ausserhalb MobileMiddleware unter
`/api/mobile/v1/public/identity` registriert, mit eigener Ready-/Originpolicy.
Nur explizit erlaubte HTTPS-Origins und Mandantenslugs; vorhandene verifizierte
TenantDomainService-Bindung bleibt verbindlich. Custom-Origin zeigt nur ihren
eigenen Verein. Kein Bearer, Cookie, Integrationstoken oder Legacyfallback.
Bootstrap zeigt ausschliesslich konfigurierte oeffentliche Vereinsnamen.

Registrierung speichert zuerst verschluesselte Einmal-Challenges, keinen
aktiven Benutzer. Erst echter Emailproof legt ein neues reines member-Konto
an. Vorhandene/inaktive/globale Konten werden weder ueberschrieben noch
reaktiviert oder ueber Emailgleichheit einem Verein zugeordnet. Es entstehen
keine automatischen Mitglieder-, Eltern-, Trainer- oder TL-Rechte. Die echte
bestehende AccountLink-Administration prueft und erfasst Zuordnungen.
Die Aktivierungsseite zeigt deshalb access_pending, keine behauptete Sitzung.
Persoenliches Login emittiert den spezifischen403-Code access_pending nur
nach nachweislich korrektem Passwort und fehlenden zugelassenen Vereinen.
Falsche Passwoerter/unbekannte Konten bleiben generisch401. Backoffice-Profile
bleiben persoenlich geschlossen. Keine Session wird fuer pending erzeugt.

Reset ist an aktives regulaeres Konto, echte bestehende Vereinsmitgliedschaft,
eine explizit bestaetigte globale Recovery-Mailbindung, deren Revision,
Kontorevision und Passwortzustand gebunden. Ein Email-foermiger Benutzername
oder Mitglieder-/Familienkontakt allein ist niemals ein Kontonachweis.
Bestehende Kontoinhaber erfassen nach persoenlicher Anmeldung und erneuter
Passwortpruefung ihre Recovery-Adresse; sie wird erst durch eigenen
Einmal-Mailproof wirksam. Der Benutzername bleibt unveraendert. Ursprungssitzung,
Passwort- und Bindungsrevision werden bei Bestaetigung nochmals geprueft.
Wer vorher sein Passwort verloren hat, braucht weiterhin die bestehende
berechtigte Kontoverwaltung, keine unsichere Herleitung aus Kontaktfeldern.
Mehrere eigenstaendig bestaetigte Konten derselben Adresse sind moeglich;
deren Benutzernamen stehen nur im geheimen Mailtext. Reset verlangt dann
die konkrete Auswahl per Benutzername, keine oeffentliche Kontoliste.
Das neue Passwort aendert keine Rollen/Freigaben. Alle mobilen Sitzungen
dieses Kontos werden widerrufen, auch in weiteren Vereinen.

Registrierung/Reset: generische Antworten ohne Kontosuche, IP-/Email-Limits,
UUID/Fingerprint, Secret nur gehasht, Inhalte verschluesselt, Einmaligkeit,
Registrierung24h, Recovery-Enrolment/Reset30Minuten. Alle eigenen
timestamptz-Fristen werden explizit als ISO8601 mit Offset geschrieben und
verglichen, unabhaengig von PHP-/PostgreSQL-Sitzungszeitzonen.
Emailproof steht im URL-FRAGMENT, nie in ausgehenden Querystrings. Die eigene
restriktive Browserseite uebernimmt es in POST und entfernt es unmittelbar
aus der Browseradresse. Kein fremder Redirect, keine automatischen Logins.

## Enger Mailvertrag

Der bestehende TestMailGuard darf Identitysecrets niemals an den allgemeinen
Testempfaenger umleiten. Genau ein neuer Hook erkennt den finalen eigenen
MobileIdentityMail-Typ. Alles andere bleibt unveraendert. Identityversand
verlangt eine separate ausdrueckliche Freigabe, eigenen benannten SMTP-Mailer
(kein Test-/Log-/Array-/DSN-Transport), verbindliches TLS und begrenzten Timeout.
Die Guardpruefung bindet echten Mailtyp, konkrete Challenge/Outbox-ID,
signierten Payloadfingerprint, state=sending, nicht abgelaufene Challenge,
exakten Mandanten/Origin/Empfaenger/Absender und unveraenderten Nachrichteninhalt.
CC/BCC/ReplyTo/Resent-/Sender-/ReturnPath-Ergaenzungen werden abgewiesen.
Konfigurationsfehler oder gefaelschte Marker fallen nie auf Testversand zurueck.

Versand ist nach Commit einmal beansprucht. Ungewisse SMTP-Ergebnisse bleiben
attention und werden nicht automatisch erneut gesendet. Geheimnisse/Adressen
werden nicht geloggt; verschluesselte Zustellpayloads nach Versuch geloescht.
Reale externe Zustellung ist eine spaetere enge Betriebsfreigabe/Abnahme;
Tests verwenden ausschliesslich Mail::fake oder direkt konstruierte Events.
Bestehende TestMailGuard-Regressionsfaelle **2/8 bestanden**.

## Oeffentlicher Mitgliedschaftsantrag

Eigener anonymer Repositoryadapter verwendet die vorhandene
MemberRegistrationEditScreen(publicMode:true)-Maske und echte dynamische
Laender/Geschlechter/Rechnungsempfaenger. Keine private Anmeldeliste,
Mitgliedersuche, Status-/Team-/Rollenrechte oder AHV im oeffentlichen Vertrag.
Genuine RegistrationDataService.submitPublic/RegistrationDocumentService.
addPublic speichern Antrag und geschuetzte Anhaenge. Erfassung bleibt im
serverseitig konfigurierten submitted-Workflow, Annahme nur durch Fachpruefung.

Verschluesselter Formularclaim bindet Origin/Mandant, Lookupstand, UUID und
1h-Ablauf sowie dieselbe Mindestwartezeit wie die vorhandene Webmaske.
Idempotenzfingerprint schliesst gleiche UUID mit anderen Daten/Dateien.
Nur Submitted/PendingAttachments als Rueckgabe, kein privates Dossierrecht.
Max1Portrait5MiB plus3Identity-Dateien je10MiB, max4/max35MiB insgesamt;
zusaetzlich begrenzte Textfelder. MIME-/Scannerpruefung vor Commit, derselbe
SHA256 muss im dauerhaft verschluesselt abgelegten Upload stehen. Scanner-
Nachweis ebenfalls verschluesselt in400. Externer Transfer erst nach Commit,
ueber den vorhandenen Upload-/Synchronisationsdienst. Echte grosse Uploads
brauchen die separat von Root geprueften ClubUnio-Nginx-/FPM-Grenzen.

## Native Biometrie

PersonalBiometricGate nutzt LocalAuth fuer echte eingeschriebene Android-/
iOS-Biometrie, ohne PIN-Fallback oder gespeicherte Passwoerter. Erst nach
positivem Prompt liest es das verschluesselte Token und prueft frischen
detachedcontext einschliesslich Custom-Tenantgrenze. Abbruch, Lockout,
fehlende Hardware/Einschreibung oder alte Antworten entsperren nichts.
Manuelle Passwortanmeldung bleibt der bestehende Weg.

Sharedapp-Hooks: zuerst isEnabled() lesen; bei aktivierter Praeferenz keine
stille Sessionwiederherstellung. unlock() liefert Token/Kontext erst nach
Prompt/Serverpruefung; Root uebernimmt mit vorhandenem Generationguard.
Appstart startet bei aktiver Praeferenz den echten Prompt; Abbruch oder
fehlende Hardware belassen die Originalanmeldung mit manuellem Passwortweg.
Lifecyclewechsel brechen nur bereits laufende Biometrie ab; spaete Ergebnisse
werden generationgesichert verworfen. Wie im Original-SVS bleibt eine bereits
angemeldete App beim Backgroundwechsel offen, inklusive ungespeicherter
Formulare/Kamera-/Dateiauswahl. Kein zusaetzliches automatisches Logout.
Der bestehende Vault bleibt erhalten, keine Passwortkopie.
Original LoginBackend/ProfileBackend tragen optionale native Callbacks.

Native Minimalvoraussetzungen: FlutterFragmentActivity, USE_BIOMETRIC,
NSFaceIDUsageDescription und AppCompat-LaunchTheme (auch Night). Namen,
Icons, IDs und Markenfarben bleiben vom Markenbuild gesteuert.
Kein behaupteter Biometrieschutz auf Web/Windows. Die installierten Versionen
bleiben unveraendert. Primaerdoku:
[local_auth2.3.0](https://pub.dev/packages/local_auth/versions/2.3.0),
[flutter_secure_storage9.2.4](https://pub.dev/packages/flutter_secure_storage/versions/9.2.4).
Die Biometrie ist ein lokales Zugangstor; keine Behauptung, dass das bisherige
Storageformat selbst auf einen bestimmten Fingerabdruck kryptografisch bindet.

## Lokaler Nachweis und verbleibende Abnahme

7 Auth-/Intake-HTTPfaelle plus2 unveraenderte Mailguardregressionen bestanden:
9 Tests/187 Assertions. Migration400 wird ausschliesslich in TEMP-Tabellen
mit TEMP-FK-Eltern abgebildet; reale synthetische Fachfixtures werden komplett
zurueckgerollt. Kein globales Schema-DDL, keine reale Mail/Provideraktion.
Nachgewiesen: pending Login ohne Session, Original-Intake mit dynamischen
Lookups und Claim-/Payload-Replay, keine privaten Felder/Rechte, bestehendes
Konto nur nach Passwort+Emailproof, Mehrfachkontenauswahl, Sessionwiderruf,
Fristen/Host/Customgrenze/Flags, exakte Mailguardpositiv-/Manipulationsfaelle,
echtes geschuetztes Staging aller4 Originaldateien, Scanfake vor Anlage,
Hash-/Scannerbelegbindung, Replay ohne erneuten Scan und einmaliger Fake-
Maildispatch mit Secretloeschung/attention ohne automatische Wiederholung.

Flutter:7 neue Public-/Recovery-/Originalscreenfaelle und5 Biometriefaelle
bestanden. Adapter/Flow/Recovery statisch sauber. PublicIdentityFlow waehlt
den echten freigegebenen Verein und injiziert Original RegisterScreen und
MemberRegistrationEditScreen mit sichtbarem Vereinsnamen. Login/Profile
bieten optionale native Callbacks; keine persoenliche Legacy-HTTP-Ausweichroute.
Browservalidierung bleibt auf geschuetzter Seite, Passwoerter werden nicht
wieder ausgegeben. Proof bleibt im POST, Fehlerseite verraet keine Kontodaten.

`php deploy/check-mobile-identity.php --closed` prueft8oeffentliche und2
persoenliche Routen samt Middlewaregrenzen und alle standardgeschlossenen
Runtime-/Mailflags, ohne Daten-/Provideraktion; lokal bestanden.

Echte Sharedapp-Einstiege sind verdrahtet: Original-Logincallbacks, explizite
oeffentliche Vereinsauswahl, account-/tenantgebundene Recoverykarte und
Profil-Biometriepraeferenz. Productiondefault nutzt SecureStore/Gate; eigene
MemoryStore-Clients bleiben ohne Native-/SecureStorage-Pluginabhaengigkeit,
ein Testgate ist explizit injizierbar. Kontowechsel-/Notificationguards und
alle inzwischen eingebundenen Originalmodule bleiben erhalten.
Zusaetzlicher Appabschluss:4 echte Entry-/Profile-/Lifecycle-Widgetfaelle und
bestehende Konto-/Shell-/Originalmodul-/Tenant-/Notificationregressionen
zusammen38/38 bestanden. Profilpraeferenz verlangt positiven Prompt und
frischen passenden Actor; Deaktivieren braucht keinen Tokenread. Nachweis,
dass ein ungespeichertes Original-Passwortformular Background/Resume erhaelt.
Root fuehrt Releasegates aus. Reale SMTP-Zustellung, physische Android-/iOS-
Biometrie und400/DEV bleiben separat geschlossen. Rueckkehr aus dem Mailbrowser
erfolgt ueber explizites Oeffnen der App; ein Receipt authentifiziert nie.

# Explizite externe Konfiguration (standardgeschlossen)

Die Migration schaltet keine externen Konten, Provider oder Sendungen frei.
Die vormals festen geschlossenen Defaults sind jetzt bewusst konfigurierbar;
ohne gueltige Konfiguration bleiben die bestehenden Readinesspruefungen zu.
Keine neuen Env-/Credentialwerte wurden hier gesetzt und kein Provider gerufen.

## Oeffentliche persoenliche Identitaet

CLUBUNIO_MOBILE_IDENTITY_ENABLED und die getrennten *_REGISTRATION_ENABLED,
*_PASSWORD_RESET_ENABLED, *_MEMBERSHIP_ENABLED, *_EMAIL_ENROLLMENT_ENABLED sowie
*_MAIL_ENABLED werden als explizite Booleans gelesen. Gemeinsamer Praefix:
CLUBUNIO_MOBILE_IDENTITY_. Ungueltige Werte aktivieren nichts.
ORIGINS enthaelt exakte HTTPS-Origins ohne Pfad/Query, TENANT_SLUGS die bewusst
freigegebenen Clubs. Serverseitige verifizierte Domainbindung und Fachrechte
bleiben zusaetzlich Pflicht. FROM ist die gepruefte Absenderadresse.

Der eigene Mailer clubunio_identity verwendet ausschliesslich die eigenen
CLUBUNIO_IDENTITY_SMTP_HOST/PORT/SCHEME/USERNAME/PASSWORD-Werte. Kein allgemeiner
Testmailer-/MAIL_URL-/Credentialfallback. SMTPS oder SMTP mit zwingendem TLS,
15 Sekunden Timeout; keine unsichere Downgradeoption. Fehlende/ungueltige
Host-/Port-/Schemeangaben lassen die Readiness geschlossen. Der kryptografische
Mailguard, echte aktuelle Proofzeile und feste Empfaengerpruefung bleiben aktiv.

## Native Pushzustellung

CLUBUNIO_MOBILE_PUSH_ENABLED und CLUBUNIO_MOBILE_PUSH_DELIVERY_ENABLED bleiben
ohne explizites true geschlossen. CLUBUNIO_MOBILE_PUSH_APPS ist eine begrenzte
JSON-Map: native Appkennung => platform, origin, fixed_tenant_id, project_id,
credentials_file. Plattformbuild hat fixed_tenant_id=null, Custombuild eine
positive echte Mandanten-ID. Registry akzeptiert nur android/ios, HTTPS-Origins,
echte numerische IDs und einen absoluten privaten Credentialdateipfad.
Credentialmaterial selbst gehoert niemals in die Registry oder ins Repository.
Ungueltige/uebergrosse Registry wird vollstaendig verworfen. Die Konfiguration
liest keine Credentialdatei; der bestehende Readiness-/Workerpfad prueft spaeter
privat Projekt, OAuthschluessel, Domainbindung und aktuelle Fachrechte.

## Nachweise und offene Abnahme

Drei DB-freie externe Konfigurationsfaelle plus interne Schalterpruefung:
vier Tests/28 Assertions gruen. Ein TLS-Transport wird nur konstruiert, niemals
verbunden/gestartet oder zum Senden benutzt. Geschlossene Identity-/Pushgates
weiter gruen. Fehlende echte SMTP-/FCM-/APNs-Konfiguration sowie Mail-, native
Initial-/Foreground-/Tap- und Geraeteabnahme bleiben ausdruecklich offen.

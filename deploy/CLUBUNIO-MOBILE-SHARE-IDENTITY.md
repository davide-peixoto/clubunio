# Persönliche Share-/Exportidentität (04.11.2026)

Bestätigter Originalablauffehler geschlossen: SponsorShareScreen erzeugte trotz
persönlichem/custom Mandanten den festen Text „Sponsorenlauf des SV Sissach“.
Der bestehende Consent-/Link-/QR-/Kopieren-/Widerrufen-/Teilen-Ablauf bleibt.
Persönlicher share-link DTO enthält jetzt echte TenantID/TenantName. Adapter
prüft Vereins-ID, nichtleeren Namen und exakte aktuelle API-Origin. Sharetext
und sichtbare Vorschau verwenden den tatsächlichen Verein; eine abweichende
App-Buildmarke wird nicht als fremder Vereinsname ausgegeben. Ältere originale
Repositoryantwort ohne Vereinsidentität bekommt neutralen Text, keinen
erfundenen SVS-/ClubUnio-Vereinsnamen.

## Öffentliche URL im Custom-Build

Der vorhandene öffentliche SponsorRunPublicService bleibt unverändert für
Web-/Serviceclients. Ausschließlich die native Controllerantwort erzeugt die
feste Route `/sponsor-run#<echter Token>` über MobileSponsorShareIdentity.
Diese löst die Requestdomain erneut über TenantDomainService auf: konfigurierte
zentrale Hosts oder tatsächlich verifizierte/konfigurierte Vereinsdomains,
passender gebundener Verein und HTTPS. Bereits erlaubte Originports bleiben
erhalten (auch8443); keine neue Einschränkung gegenüber MobileApi/BuildUriPolicy.
Kein Body-/Query-URL-/Forwarded-Host-
Freitext wird übernommen. Quelle muss echter aktueller Vereinslink sein,
nicht widerrufen/abgelaufen, Token64lowerhex und Hash passend zum gespeicherten
Tokenhash. Für widerrufene/nichtaktive Links wird kein URL erzeugt.

Client verlangt dieselbe Origin wie MobileApi, HTTPS ohne Userinfo/Query,
genau `/sponsor-run` und64lowerhex Fragment. Plattform- und fest gebundene
Custom-App akzeptieren damit ausschließlich ihre eigene verifizierte Origin.
Keine erfundenen Logos/öffentlichen Domains, keine Veränderung von Tokenrechten.

## Weitere erreichbare Wege geprüft

- Persönlicher Mitgliederausweis benutzt bereits echten TenantName, echte
  konfigurierte Vereinsmarke oder neutrales Badge; Exportbetreff entsprechend.
  ClubUnio-Buildlogo wird nicht als fremdes Vereinslogo ausgegeben.
- Persönliches Originalspiel bekommt echten clubName und überspringt dadurch
  den ausschließlich alten SVS-Wappenladepfad.
- Dokumente liefern berechtigte echte private Dateien; keine fest erfundenen
  Vereinsnamen/Share-URLs im persönlichen Dokumentenweg gefunden.
- Aufgebot-Sharetext verwendet tatsächlichen Termin, Mannschaft und Inhalt,
  keinen festen SVS-Namen. Spesenviewer nutzt persönliche indexgebundene
  Dateizugriffe; alte SVS-Hostlisten liegen im gesperrten Legacyadapter.
- Profil-Diagnosepakettitel und alter Biometriesatz sind durch den persönlichen
  ProfileBackend-Zweig nicht erreichbar (HiddenTap nur ohne Backend, persönliche
  Biometrie eigener Callback). Gleiches gilt für Keybox-SVS-Fallback (persönliche
  AccessName-Ableitung separat), ursprünglichen Registrierungstext/Logo und
  alte WhatsApp-Supportadresse (persönliche Shell eigener Supportcallback).
  Nicht pauschal Legacystrings gelöscht oder unbekannte Kontakte erfunden.

## Prüfung und Auslieferung

Elf Flutterfälle grün: fünf neue persönliche Marken-/Originsfälle plus sechs
Original-Sponsoren-/Share-Regressionen. Enthalten sind tatsächlicher SDK,
Plattform/customOrigin positiv, fremde Origin/Pfad/Query/Token negativ,
fehlende/fremde Tenantidentität und originaler QR-/Shareablauf unter anderer
Custom-Buildmarke. Share-Callback zeichnet nur Testparameter auf; kein OS-
Share-/Provideraufruf. Analyse der drei Produkt-/Testdateien befundfrei.

Drei PHP-Unitfälle/12 Assertions ohne PG: Plattform/custom und echte feste
Tokenroute inklusiveCustom8443, veraltete/manipulierte Quellfelder, fremde Domain/Verein und HTTP.
Domainservice ist dabei ein expliziter Prüfspy; dies behauptet keine neue
DNS-/TLS-/HTTP-Kernel-Abnahme. Bestehender realer Domainservice wird produktiv
frisch aufgerufen. Der vorhandene synthetische PG-Linkfall ist zusätzlich um
echte zentrale/configurierte Customdomain und unbekannte Domain sowie TenantDTO
erweitert; dessen freigegebener serieller Nachlauf ist mit1 Test/30 Assertions
grün (tatsächliche HTTP-/Domain-/Token-/Consent-/Replay-/Widerrufswege).
Keine permanente DDL durch die genannten String-/Widget-/Unit-/Rollbackprüfungen,
kein Shareversand.
Gezieltes Backend/App-Delta muss zusammen durch Root ausgeliefert werden;
alter Backend-DTO ohne Tenantidentität schließt die neue persönliche Sharemaske.

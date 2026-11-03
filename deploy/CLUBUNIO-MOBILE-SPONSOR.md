# Persoenlicher Sponsorenlauf

Stand03.11.2026. Die vorhandenen SVS-Screens fuer Anlass-/Teilnehmerlisten,
Zusagenvorschau, Helfer und QR-/Sponsorenlinks bleiben die Basis. Persoenliche
Mobile-Sessions ersetzen die technische Altanbindung. Featureflag
`clubunio-mobile.native_sponsor_enabled` bleibt standardmaessig false.

## Vertrag

Neue Quellen: `MobileSponsorRoutes`, `MobileSponsorController`, `MobileSponsorData`,
`MobileSponsorReady`; persoenlicher Flutteradapter `PersonalSponsorRepository`.
`MobileSponsorRoutes::register()` unter der bestehenden persoenlichen Clubgruppe
registrieren. Die Shell injiziert den Adapter in `SponsorRunScreen`.

Unter `/api/mobile/v1/clubs/{tenant}/sponsor-run`:

- GET/POST `events`; PATCH `events/{event}`; GET `teams`
- GET `events/{event}/participants`, `helpers`, `helper-candidates`, `billing`
- POST `events/{event}/helpers`
- GET/POST `participants/{participant}/pledges`, `share-link`
- PATCH `participants/{participant}/operations`, `result`

Die benannten SVS-Felder bleiben am persoenlichen Featurevertrag erhalten. Es ist
kein frei verwendbarer Tabellenproxy. Native ClubUnio-IDs und Revisionen werden
benutzt. Erfolgreiche JSON-Antworten sind in `data` gekapselt und enthalten
`success:true`; der Client bestaetigt Mutationen erst bei passenden echten IDs.
Listen sind begrenzt/paginiert, Zusagenvorschau kann weitere Seiten laden.

## Rechte und Fachablauf

`SponsorRunService`, `SponsorRunPublicService`, `MemberAccessService` und die
bestehenden Modulrechte entscheiden serverseitig. Eltern sehen nur genehmigt
zugeordnete minderjaehrige Kinder; mit18Jahren entfaellt dieser Zugang. Trainer
sehen verantwortete Mannschaften. Helfer erhalten ausschliesslich zeitlich und
fachlich zugewiesene Check-in-/Rundenrechte. Helfer allein sehen keine privaten
Sponsorenzusagen oder Summen. Verdeckte Teilnehmer/Anlaesse liefern404.

Writes authentisieren die persoenliche Session nochmals unter Mandanten- und
Sessionsperre. Check-in, Rundenentwurf und verbindliche Freigabe pruefen die
angezeigte Revision. Freigabe verlangt eine ausdrueckliche Bestaetigung des
aktuellen Rundenentwurfs; danach sind Runden unveraenderlich. Geschlossene
Zusagefrist, abgesagter Anlass, pensionierte Teilnahme und fremde IDs bleiben
gesperrt. Anlaesse und Helfer verwenden vorhandene Revisions-/Auditdienste.
Die Helfersuche zeigt nur aktive Mandantenkonten mit eigener aktiver Zuordnung;
Konten werden durch native AccountID statt einen Nutzernamen zugewiesen.

CHF wird als ganze Rappen verarbeitet. Betrag/Deckel werden vom bestehenden
Service geprueft. Rechnungs-/Sammelmodus und ausdrueckliche Sammelbestaetigung
bleiben erhalten. Person/Firma und Rechnungsadresse bleiben verschluesselt im
Backend; die Vorschau gibt weder Adresse noch E-Mail zurueck. Endbetrag folgt
erst nach Rundenfreigabe. Die bestehende Zusage-Idempotenz bleibt unveraendert.

Sponsorenlinks bleiben vom vorhandenen ausdruecklichen Mandanten-Public-Schalter,
persoenlicher Berechtigung, Zusagefrist und Namens-Einwilligung abhaengig.
Anzeigen laeuft lesend; Erstellen/Rotieren/Widerrufen sind ausdrueckliche Aktionen.
Die App prueft HTTPS, festen Public-Pfad und begrenzten Fragmenttoken, bevor ein
Link fuer QR/Kopieren/Teilen angezeigt wird. Es werden keine Mails ausgeloest.

## Migration200 und Restore

`2026_11_03_000200_mobile_sponsor_commands.php` ist ausdruecklich reserviert;
Anwendung ausschliesslich durch Root in einem geprueften additiven DEV-Fenster.
Nicht das komplette Migrationsverzeichnis fortlaufend ausfuehren.

Eine Tabelle bindet tenant/account/UUID/Operation und normalisierten SHA256 an
echte Event-/Teilnehmer-IDs und Ergebnisrevision. FKs pruefen Mandantenzuordnung
und Event-/Teilnehmerbindung. Keine Privattexte oder Linktokens im Ledger.
Diese neue Idempotenz schliesst ausschliesslich die Luecken bei Anlassanlage und
Linkaktionen; Zusagen nutzen weiter den vorhandenen Fachledger. Abweichender
Replay oder inzwischen geaenderter Link liefert409. Keine automatischen Retries;
Formularvorgang behaelt die UUID bis zur Bestaetigung.

Vor Anwendung Schema-/Datenbackup und Restorepunkt; danach Tabellenrechte,
Fremdschluessel und Migrationseintrag gezielt pruefen. `PUBLIC` erhaelt keine
Rechte. Down verweigert destruktives Loeschen des Nachweisledgers; Ruecknahme
erfolgt durch Featureflag oder ausdruecklich geprueften Restore. Keine neuen
Pflichtspalten an vorhandenen Fachobjekten, keine Datenimporte/Refreshes.

## Abrechnung und verbleibende Abnahme

Die bestehende Abrechnungsansicht zeigt ausschliesslich autorisierte lokale
Rechnungssnapshots mit Status und CHF. Keine Bexio-Kataloge, externen PDFs,
Provider-Synchronisierung, Kontakt-/Rechnungsanlage, Versand oder Zahlungen aus
diesem Paket. Diese Aktionen bleiben im Vereinsbackend. Der persoenliche Adapter
und die Serverrouten bieten solche Mutationen nicht an; die UI blendet sie aus.

Nachweis:5Fluttertests pruefen echten MobileApi-Vertrag und Originalscreens,
Kinder-/Zusagenvorschau inklusive weiterer Seiten, Linkansicht ohne automatischen
POST, unsichere Eventanlage mit unveraenderter CallerUUID und Abrechnung ohne
externe Aktionen. Zusaetzlich werden Helfer mit gleichem Anzeigenamen durch
AccountID unterschieden und gespeicherte Datumswerte korrekt weitergereicht.
Gezielte Dart-Analyse/PHP-Lint sauber. Sieben echte PostgreSQL-Rollbacktests mit
113 Assertions bestanden, einschliesslich privater TEMP-Abbildung der neuen
Migration200-Fremdschluessel. Ablauf-Fixtures verwenden explizite ISO-Zeitzonen
und beweisen den abgelaufenen Zustand vor der erwarteten Zugriffssperre.
Migration200 nicht dauerhaft angewendet; keine externen Aufrufe.

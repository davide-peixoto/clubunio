# Elternzugriff und Elternlogins

Stand: 23.11.2026.

## Fachregel

Minderjaehrige erhalten automatisch eine Elternverknuepfung, wenn die beim
Mitglied hinterlegte Mutter- oder Vateradresse mit einer unabhaengig bestaetigten
Loginadresse uebereinstimmt. Ein Benutzername allein reicht nicht. OIDC-Nachweise
werden erst nach Signatur-, Issuer- und Subject-Zuordnungspruefung angenommen;
alternativ gilt eine verbrauchte, kontogebundene E-Mail-Verifikation. Aktives
Konto und aktive Mitgliedschaft bleiben Voraussetzung. Vorhandene manuelle
Widerrufe oder zurueckgehaltene Pruefentscheidungen werden nicht ueberschrieben.
Die Automatik aendert keine vorhandenen Rollen oder gesperrten Portalzugriffe.

Am 18. Geburtstag (Kalendertag Europe/Zurich) endet diese automatische
Vertretung ohne ausdrueckliche Volljaehrigenfreigabe. Der Spieler kann fuer seine
eigene bestaetigte Mitgliedschaft im Profil zustimmen, einen bereits
verifizierten Elternlogin per E-Mail hinzufuegen oder widerrufen. Eltern duerfen
sich nicht selbst freigeben. Alternativ genehmigt die Geschaeftsstelle ueber die
bestehende Kontozuordnungsverwaltung; diese Entscheidung wird als Office-Consent
pro konkreter Verknuepfung protokolliert. Die Zustimmung eines Elternteils gilt
nicht fuer weitere Elternkonten. Ein Widerruf wirkt bei der naechsten
Berechtigungspruefung; vorhandene Dateien auf fremden Endgeraeten sind nicht
rueckrufbar.

Automatische Verknuepfungen werden beim verifizierten Login, nach erfolgreicher
E-Mail-Verifikation und beim Profilaufruf synchronisiert. Bei Annahme einer
neuen minderjaehrigen Mitgliedschaft wird ein vorhandener verifizierter
Elternlogin ebenfalls synchronisiert. Bestehende Sitzungen ohne gespeicherten
Nachweis brauchen einen erneuten verifizierten Login. Die angezeigte Adresse
wird nicht aus einem unbestaetigten Benutzernamen als Beweis uebernommen.

## API und App

GET `clubs/{tenant}/profile/parent-access`: eigene Mitgliedschaften und deren
Elternlogins getrennt von vertretenen Kindern. Kinder geben keine Schreibrechte
oder fremden Kontodetails preis. POST derselben Route nimmt MemberID und Email;
POST `.../{link}/approve` bzw. `.../{link}/revoke` nimmt Revision. Schreiben
prueft Session und Mandant erneut innerhalb der Transaktion, sperrt den Mandanten
und lehnt veraltete Revisionen ab. Alle Antworten bleiben privat/no-store.

Die App verwendet ausschliesslich frische CanApprove/CanRevoke-Rechte. Unter
Profil > Elternzugriff verwalten Erwachsene ihre Elternlogins; Eltern sehen
separat den Status ihrer Kinder. Es gibt keine oeffentliche Kontosuche und
keinen automatischen Einladungsversand. Texte sind lokal uebersetzt, ohne
Uebersetzungs-API. Siehe mobile/docs/PARENT-ACCESS.md.

## Rollout und Sicherheit

Migration `2026_11_23_000010_parent_access`: Consent-Felder an account_member_links,
private account_parent_email_proofs und Indizes fuer Elternadressen. Featureflag
CLUBUNIO_PARENT_ACCESS_ENABLED erst nach Migration aktivieren; Default false
bleibt mit alten Schemas kompatibel. Quelle clubora, Live-Schema clubunio.
Kein globaler Import, keine Rechteableitung aus alten Snapshot-Konten.

Die beiden ausdruecklich bestaetigten Kinderverknuepfungen des gemeldeten
Stage-Kontos wurden ueber AccountLinkService freigegeben und auditiert. Inaktive
historische Konten anderer Stage-Mandanten bleiben unveraendert.

Gezielte Dateisicherungen und PG-Dumps der Link-/Migrationstabellen liegen vor
Migration privat auf den jeweiligen Servern. Bestehende abweichende OIDC- und
Registrierungsimplementierungen wurden mit engen, vorab geprueften Aenderungen
erhalten. Keine Zugangsdaten oder Nachweisdatensaetze ins Repository aufnehmen.

## Verifikation

Lokal: 13 Tests / 108 Assertions bestanden; 20 bestehende Opt-in-PG-Tests sind
lokal uebersprungen. Uebersetzungskataloge: 3046 Schluessel DE/EN konsistent.
App: 25 fokussierte Tests, gezielte Analyse und 2172 Texte in fuenf Katalogen.

Live-Pruefung: `php deploy/check-parent-access.php --synthetic-rollback
clubunio_dev|clubunio_stage|clubunio_prod`. Verwendet echte PostgreSQL-Abfragen
fuer Altersgrenze, konkrete Consent-Verknuepfung und Benachrichtigungsempfaenger;
saemtliche synthetischen Daten werden zurueckgerollt, externe Requests gesperrt.
Ein echter iPhone-Test der neuen Profilseite bleibt dem Neubau am Mac vorbehalten.

Rollout abgeschlossen: DEV, Stage und PROD migriert und aktiviert; jeweils 39
synthetische PG-Pruefungen erfolgreich und zurueckgerollt. HTTP /up jeweils 200.
Stage-Zielkonto liefert weiterhin drei vertretene Mitglieder (eigener Eintrag
und beide bestaetigten Kinder). App-Profilseite benoetigt den aktuellen Neubau.

## Korrektur des Vereinsdomain-Logins (23.11.2026)

Der OIDC-Callback pruefte fuer fest gebundene Vereinsdomains bislang
Account::canAccessTenant (Backoffice-/Modulzugang). Berechtigte Eltern und
Trainer wurden dadurch vor dem Mobile-Return mit HTTP403 abgewiesen. Der
Callback verwendet jetzt MemberAccessService::canEnter fuer genau den
Domain-Mandanten. Aktives Konto, api_only-Sperre, Subjectbindung, State/PKCE,
MFA und die anschliessenden Mobile-Pruefungen bleiben erhalten. Keine
Rollenaufwertung und keine Erweiterung auf fremde Vereine.

Regression: kompletter nativer Callback mit berechtigtem Member ohne
Backofficezugang erreicht den App-Return; verweigerter Zielmandant bleibt403.
Zusammen mit PlatformOidcTest:7 Tests/36 Assertions bestanden. Enge
Serverkorrektur auf Stage, DEV und PROD mit privatem Dateibackup; bestehende
abweichende Web-MFA-Implementierungen erhalten. Stage-Zielkonto besteht die
Loginpruefung fuer beide zugeordneten Mandanten. Echter erneuter Login durch
den Benutzer bleibt abschliessende Geraeteabnahme. Kein App-Neubau erforderlich.

## Gezielte PROD-Kontoaktivierung (23.11.2026)

Nach ausdruecklicher Benutzerfreigabe wurde das bisher deaktivierte PROD-Konto105
im bestehenden Mandanten12 aktiviert und portal_enabled eingeschaltet. Rolle
trainer und bestehende Mitgliedszuordnungen bleiben erhalten. Die Identitaet
wurde anhand der bereits explizit auf Stage gebundenen Subject-ID und durch
eine unabhaengige Nur-Lesen-Pruefung im Keycloak-Realm bestaetigt: Subject,
E-Mail, enabled und email_verified muessen exakt passen. Kein neues Keycloak-
Konto, kein Passwortreset und keine E-Mail versendet.

Transaktional wurde genau ein keycloak_account_links-Datensatz angelegt und
die Aktivierung mit berechtigtem PROD-Auditakteur protokolliert. Vorher wurde
derselbe Ablauf mit Rollback geprueft. Die bestehende PROD-Subject-Allowlist
wurde nur um dieses Konto ergaenzt, sodass der bisher begrenzte Loginbetrieb
erhalten bleibt; globale Einladungen weiterhin deaktiviert. Nach config:cache
frisch geprueft: Konto aktiv, explizites Mapping und DB-Verknuepfung korrekt,
MemberAccess fuer den Zielmandanten true, bestehende Trainerrolle und eigener
Mitgliedseintrag vorhanden. Minderjaehrige Kinder werden beim naechsten echten
verifizierten Login nach der zentralen Elternregel synchronisiert.

Private Sicherung: /var/backups/clubunio-prod-gmail-20261123 (urspruengliche
Umgebungskonfiguration und gezielter PG-Dump der betroffenen Kontotabellen).
Rueckweg ist gezielt: neu hinzugefuegtes Subject-Mapping und exakt diese
DB-Identitaetsverknuepfung entfernen, Konto105 und dessen Vereinszugang wieder
sperren und Konfigurationscache neu erzeugen. Kein pauschaler DB-Restore.
Keine Secrets, Subjects oder Umgebungskonfiguration im Repository.

Loginregressionen:10Tests/54Assertions bestanden. Der echte iPhone-Login
bleibt abschliessende Benutzerabnahme. Fuer die Fehlerdiagnose beachten:
RunCloud-Zugriffslogs sind mit16k gepuffert und ohne flush-Intervall. Fehlende
neue Logzeilen beweisen keinen fehlenden Request; USR1 an den NGINX-Master
oeffnet die Logs neu und leert den Puffer ohne Dienstunterbrechung. Damit war
der PROD-Callback403 zeitlich eindeutig nachgewiesen; Stage hatte zuvor einen
erfolgreichen Callback und Tokenaustausch.

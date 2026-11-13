# Sichere Anmeldung (ClubUnio)

## Pilot

- Passkey mit WebAuthn und verpflichtender Benutzerverifikation (PIN oder
  Biometrie). Beim Login mit Passkey ist kein zusaetzlicher Code erforderlich.
- Alternativ Passwort plus sechsstelliger TOTP-Code einer Authenticator-App.
  Der Wizard zeigt den Einrichtungsschluessel fuer die manuelle Eingabe.
- Der Wizard ist nach Passwort-Login ueber das Benutzermenu in der Verwaltung
  bzw. das Schild im Portal erreichbar. Bei Konten ab dem in UTC
  konfigurierten Aktivierungsstichtag ist der Wizard vor der ersten
  fachlichen Sitzung Pflicht. Bestehende Konten werden nicht pauschal
  ausgesperrt. Die App oeffnet den Einstieg nach HTTP 428 im Systembrowser.
  CLI-Ausstellung nach separat gepruefter Identitaet:
  `php artisan clubunio:security-invite <account-id>`.
- Eine WebAuthn-Challenge ist kurzlebig und an Sitzung, Vorgang und App-Kopplung
  gebunden. Der RP ist der exakte konfigurierte HTTPS-Host. Die App zeigt einen
  achtstelligen Code an, der im Systembrowser eingegeben wird. Das Sitzungstoken
  wird danach separat und einmalig von der App abgerufen, nie per URL.
- Bestehende lokale Geraetebiometrie entsperrt nur den App-Zugang auf demselben
  Geraet. Sie ersetzt weder einen Passkey noch einen serverseitigen Faktor.

## Wiederherstellung

- Passwort plus einmaliger Wiederherstellungscode autorisiert nur die
  Neueinrichtung. Die bisherige Sitzungsgeneration wird sofort ungueltig.
  Nach abgeschlossener Neueinrichtung werden alte Faktoren ersetzt und
  acht neue Einmalcodes angezeigt. Codes und Einladungen liegen nur gehasht,
  der TOTP-Schluessel verschluesselt vor.
- Eine bereits bestaetigte Recovery-Mail kann den bestehenden Passwort-Reset
  und einen Setup-Code ausloesen, nur fuer reine Mitgliedskonten und auf dem
  kanonischen Ursprung. Alle Clubrollen und die Plattformrolle werden auch
  beim Einloesen geprueft. Kein E-Mail-Ersatz fuer Staff oder Backoffice.
- Support-Wiederherstellung: `clubunio:security-invite <account-id> --recovery`
  nur nach unabhaengiger Identitaetspruefung, ohne E-Mail-Alleinfreigabe.
  Keine Schluessel, Einmalcodes oder Sitzungsnachweise in Tickets oder Logs.
- Unter Benutzer und Rollen koennen Vereinsadmin und Geschaeftsstelle
  die sichere Anmeldung eines eigenen, vereinsgebundenen Kontos
  zuruecksetzen. Eine hoechstens zehn Minuten alte starke Anmeldung,
  Passwortbestaetigung, Identitaetspruefung und Begruendung sind Pflicht.
  Alte Faktoren und Sitzungen sind sofort gesperrt; nur der einmal
  angezeigte Setup-Code und das Passwort erlauben die Neueinrichtung.
  Fremd-, Mehrvereins-, Plattform- und privilegierte Verwaltungskonten
  sind nicht delegiert. Das Audit enthaelt keinen Setup-Code.

## Rollout

1. Getrennte Zielumgebung und verschluesselte Sicherung pruefen. Additive
   Migration im freigegebenen ClubUnio-Runner zuerst ausfuehren; dabei keinen
   PHPMaker-Import, keine Kopie von Faktoren und keinen Bestands-Reset starten.
2. Fuer die Abnahme `CLUBUNIO_SECURITY_ORIGIN` auf den exakten kanonischen
   HTTPS-Ursprung der Instanz ohne Pfad oder Schluss-Slash setzen. Proxy,
   Cookie, CSRF und Cache-Store mit atomaren Sperren pruefen.
3. Erst danach `CLUBUNIO_STRONG_LOGIN=true` und
   `CLUBUNIO_SECURITY_ENROLL_SINCE` (ISO-8601 UTC) in einer freigegebenen
   Testumgebung. Mit separaten Konten Passkey (Web sowie iOS und Android),
   Authenticator, Wiederherstellung, Mailzustellung, Logout und verweigerte
   Alt-Sitzungen pruefen. App-Altbuilds kennen den Einstieg nach HTTP 428
   nicht. Daher nicht mit produktiven App-Konten aktivieren.
4. Rueckweg: Konfiguration und Code zuruecknehmen; Tabellen nicht loeschen.
   Das Deaktivieren der einzigen Pilot-Flag setzt den Schutz aus und ist nach
   echten Enrollments keine regulaere Betriebsoption.

Weitere Passkeys und ein Authenticator koennen nach starker Web-Anmeldung und
erneuter Passwortpruefung hinzugefuegt werden. Die Einrichtung erneuert die
Recovery-Codes und beendet alte Sitzungen. Einzelnes Anzeigen oder Entfernen
registrierter Passkeys ist noch nicht vorhanden. Vor allgemeiner Freigabe sind
echte Geraete-/Mailabnahme, Pflicht nach Rolle und SSO ueber Kunden-Domains
offen. Kein Store-Release.

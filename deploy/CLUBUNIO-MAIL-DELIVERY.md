# Vereinsmail: Antwortadresse

Vereinsadmins pflegen unter **Einstellungen > Mailversand** die Antwortadresse.
Eine leere Einstellung verwendet den bisherigen Standard. Die Einstellung
betrifft Vereinsmails aus dem Anmeldungs-, Spesen- und Platzreservierungs-Outbox
sowie Vorlagen-Testmails. Der Mandant wird aus dem gespeicherten Versandauftrag
bzw. der geprueften Aktion ermittelt, nicht aus HTTP-Mailheadern.

Die Absenderadresse und SMTP-Zugangsdaten bleiben getrennt. Sicherheitsmails
und Bestaetigungstokens fuer den Sponsorenlauf verwenden keine Vereinsadresse.
Auf Stage bleibt die Test-Empfaengerumleitung unveraendert; die Antwortadresse
aendert weder To/CC/BCC noch den SMTP-Empfaenger. Diese Funktion aktiviert keinen
echten Vereinsmailversand und konfiguriert noch keine individuellen SMTP-Server.

## Migration und Rueckweg

Keine Schema-Migration: bestehende `tenant_settings` und `setting_changes`
werden genutzt. Beim Import keine Antwortadresse voraussetzen oder automatisch
aus Altdaten uebernehmen. Die Vereinsadmins pflegen die Adresse bei Bedarf.
Zum Ruecksetzen das Feld leeren; ein Code-Rollback ignoriert den Eintrag.

# Nachrichtenvorlagen DE/EN

## Auswahl und Bestandsschutz

- Neue Sprachvorlagen nur fuer aktivierte Sprachen, aktuell de-CH und en-GB.
- Historische FR-/IT-Varianten bleiben erhalten; keine neue Anlage oder
  Reaktivierung bei deaktivierter Sprache. Vorlagenidentitaet bleibt unveraendert.
- Empfaengersprache: aktive Kontopraeferenz im gleichen Verein, danach
  Vereinssprache, danach konfigurierter Standard (Deutsch). Niemals Adminsession.
- E-Mail-Adressen verwenden nur eindeutige Praeferenzen passender aktiver
  Vereinskonten; sonst Vereinssprache. Keine Ableitung aus Namen/Freitext.
- Fehlende Variante faellt auf de-CH zurueck. Explizit deaktivierte Variante
  faellt nicht zurueck. Lookupwerte folgen der tatsaechlich verwendeten
  Vorlagensprache; Benutzereingaben werden nicht uebersetzt.
- Migration 2026_11_04_001400_message_template_languages ergaenzt fehlende
  Varianten und deren Erstversion, ohne Schemaaenderung oder Ueberschreiben
  bestehender Texte. Neue EN-Varianten uebernehmen die DE-Aktivsperre.
  Nur nach Review und Backup gezielt ausfuehren, nicht Bestandteil der Tests.

## Ankuendigungen und Testversand

Ankuendigungen bleiben atomar: Fehlt eine benoetigte Sprachvariante einschliesslich
Fallback oder ist sie deaktiviert, wird die gesamte Veroeffentlichung blockiert.
Es gibt keine stille Teilzustellung oder Entfernung von Empfaengern. Dies entspricht
der bisherigen Gesamtsperre bei deaktivierter Vereinsvorlage. Der Vorschauvergleich
enthaelt alle verwendeten Varianten; geaenderte Empfaengersprache oder
Vorlagenrevision verlangt eine neue Vorschau. Empfaengerregeln bleiben unveraendert.

TestMailService verwendet Beispieldaten der ausgewaehlten Vorlage, unabhaengig
von der UI-Sprache. Auch der Testhinweis verwendet diese Sprache.
Sponsorenlauf-Bestaetigung und Standardtext des Rechnungsversands verwenden
Empfaenger-/Vereinssprache, niemals die Operatorsprache. Der Providerplatzhalter
`[Network Link]`, Provider-Sprach-IDs und Testempfaenger bleiben unveraendert.
Empfaenger bleibt ausschliesslich TestMailGuard::RECIPIENT.
SMTP wird in Tests vollstaendig gemockt. Keine echten Nachrichten oder Migrationen.
Mehrsprachige Outbox-Snapshots behalten die bisherigen To-/CC-Rollen; unklare
oder teilweise fehlgeschlagene Testzustellung wird nicht automatisch wiederholt.

## Pruefung

In-memory: tests/Unit/MessageTemplateLanguagesTest.php.
PostgreSQL: tests/Feature/MessageTemplateLanguagesPostgresTest.php, ausschliesslich
synthetische Daten in aeusseren Transaktionen mit Rollback und Nachkontrolle.
Opt-in CLUBUNIO_TEST_DB=1; Aufruf direkt mit
`php -d extension=pdo_pgsql vendor/phpunit/phpunit/phpunit` und dem Testpfad.
Nicht ueber artisan test starten, damit die temporaere PHP-Erweiterung erhalten bleibt.

Keine PostgreSQL-Schemamigration im Test. Bestehende Featuretests ergaenzen die
isolierte Sprachpruefung; Browser-/PDF-/Mobile-Gesamtabnahme ist davon getrennt.

Gezielte PostgreSQL-Abnahme am 04.11.2026: vier neue Vorlagen-/Ankuendigungs-/
Testmailtests mit236 Assertions erfolgreich, einschliesslich Rollback-Nachkontrolle.
Zwei weitere Sponsorenlauf-Sprachtests mit20 Assertions erfolgreich;
Providerclient ausschliesslich Fake, SMTP ausschliesslich Mock/Fake.

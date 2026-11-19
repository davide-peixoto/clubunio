# Oeffentlicher Mitgliedsantrag: Textupdate

13.11.2026: Einwilligung, Absende-Button und Eingangsbestätigung sprechen
auf Deutsch und Englisch vom Mitgliedsantrag. Pflichtfeld und
Speicherlogik bleiben unverändert. Keine Datenbankmigration.

- DEV: Textcommit `3e000f2`, Pi-Release `20261113-clubunio-join-copy`.
  Rückweg: `20261113-clubunio-join-rules`.
- Stage: Textcommit `72f894f`, im ausgelieferten Checkout `8bf6e73`.
  Rückweg: nur den Textcommit rückgängig machen, danach den View-Cache leeren.
- Beide SV-Sissach-Formulare per HTTPS geprüft: neuer Einwilligungstext,
  Pflicht-Checkbox, neuer Absende-Button. Kein Antrag abgesendet.
  Lokale Rendering-Tests prüfen DE/EN und die Bestätigung. PROD unverändert.

## Hinweis zur Testnutzung

13.11.2026: Der Hinweis im Formular bittet in DE/EN ausdrücklich um
erfundene Angaben und Testdokumente und erklärt, dass das Formular die
offizielle Vereinsanmeldung nicht ersetzt. Keine Änderung am Footer,
an der Antragslogik oder an der Datenbank.

- Stage: Textcommit `3d500e8`; Formular, neuer Hinweis und Pflicht-Checkbox
  per HTTPS geprüft (200).
- DEV: Textcommit `672526e`, Pi-Release `20261113-clubunio-join-notice`;
  Rückweg `20261113-clubunio-join-copy`. Dateien und View-Cache geprüft.
  Öffentlicher Live-Check während einer Störung des bestehenden
  PostgreSQL-Tunnels mit HTTP 500 blockiert.
- Rendering-Tests für beide Sprachen auf beiden Branches bestanden.
  Kein Antrag abgesendet. PROD unverändert.

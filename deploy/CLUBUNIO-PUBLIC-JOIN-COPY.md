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

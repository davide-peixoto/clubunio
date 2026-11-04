# Explizite interne Modulfreigaben

Nach installierter/gepruefter900 aktiviert
CLUBUNIO_MOBILE_EXPENSE_PROVENANCE_ENABLED=true die ehrliche OCR-Herkunft.
Nach installierter/gepruefter1000 aktiviert
CLUBUNIO_MOBILE_NATIVE_PUSH_ADMIN_ENABLED=true den persoenlichen Originalablauf
fuer freigegebene Rundnachrichten. Keine dieser Einstellungen aktiviert externe
FCM-/Mail-/Zahlungsprovider oder ersetzt Fachrechte/Empfaengerpruefung.

Beide Schalter sind standardgeschlossen. true/false werden explizit gelesen;
ungueltige oder leere Werte aktivieren nichts. Ein DB-freier Konfigurationsfall
mit acht Assertions prueft echte Env-Werte und deren Wiederherstellung.
Runtime-/Schemaanwendung erfolgt separat mit dokumentiertem DEV-Nachweis.

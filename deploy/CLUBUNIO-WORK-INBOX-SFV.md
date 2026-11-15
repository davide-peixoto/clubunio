# Arbeitskorb und SFV-Spielverschiebung

Am 15.11.2026 wurden im aktiven DEV-Release `20261115-clubunio-support-zammad` die zuvor geprüften Einstellungen für starke Anmeldung wiederhergestellt. Beide isolierten Testkonten bestanden den HTTPS-Login mit Passwort und TOTP sowie den Dashboard-Aufruf. Anschliessend konnte der DEV-Datenbanktunnel den VPS auf SSH-Port 22 nicht erreichen. Der isolierte Spieltest brach vor der Anlage der Testdaten ab. Bis zur Wiederherstellung des Tunnels ist die vollständige angemeldete Abnahme offen.

Die 60-Minuten-Regel ist auf DEV im Release `20261115-clubunio-sfv-hour` aktiv. Rückweg ist `20261115-clubunio-platform-monitoring`. Der Release benötigt keine Datenbankmigration. Der DEV-Datenbankcheck und der Zeitgrenzentest im aktiven Release bestanden. Der bisherige TOTP-Testclient fand das erwartete Formular nicht. Eine neue angemeldete Abnahme steht aus.

Für SFV-Spiele kann die Spielleitung am Spieltag in der Platzplanung eine Änderung zwischen 60 Minuten vor Anpfiff und dem SFV-Anpfiff bestätigen. Die Bestätigung schreibt die lokale Platzbelegung und berechtigte verknüpfte Teamtermine zusammen fort. Veo übernimmt die lokale Uhrzeit und Kamera. Der SFV-Import schützt die Ausnahme bis zum Ende des längeren Belegungsfensters aus SFV-Termin und bestätigter lokaler Zuteilung. Die Bestätigung wird im vorhandenen Änderungsjournal gespeichert.

Unter **Verwaltung → Arbeitskorb** sehen Vereinsadmins und Geschäftsstelle die nach Modulrechten verfügbaren Platz- und Busanfragen sowie Spesen zur Freigabe. Vereinsadmins können über den Arbeitskorb Bexio-Prüffälle in der Synchronisation öffnen. Die Bearbeitung erfolgt im jeweiligen Fachbereich.

In **SFV-Wettkämpfe** zeigt die Einzelvorschau bei einem Platz- oder Terminwechsel betroffene Platzbelegungen und über die SFV-Spiel-ID verknüpfte aktive Teamtermine. Die Bestätigung prüft die Vorschau erneut. Blockierende Konflikte oder fehlende Rechte für betroffene Teamtermine verhindern die Übernahme. Bei erfolgreicher Übernahme werden Wettkampf und verknüpfte Teamtermine gemeinsam aktualisiert. Die bestehenden Dienste erfassen Änderungen und verwalten Benachrichtigungen. Eine Termin- oder Platzänderung an einem bestehenden Spiel erfordert im Web eine Einzelprüfung. Die externe SFV-API behält ihren Importvertrag.

Für die Änderung ist keine Migration erforderlich. Rückweg ist der vorherige Web-Release. Bereits bestätigte fachliche Änderungen bleiben im Änderungsverlauf erhalten.

Auf DEV aktiv als `20261114-clubunio-inbox-sfv-v2`. Rückweg ist `20261113-clubunio-veo-planning`. Die angemeldete HTTPS-Abnahme mit zwei getrennten TOTP-Testkonten hat alle Arbeitskorbbereiche geprüft. Ein synthetisches Spiel wurde in der Sammelvorschau zur Einzelprüfung verwiesen und erst nach bestätigter Einzelvorschau verschoben. Der separate PostgreSQL-Test für die gemeinsame Aktualisierung von Spiel und Teamtermin bestand.

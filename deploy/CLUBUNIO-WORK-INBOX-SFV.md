# Arbeitskorb und SFV-Spielverschiebung

Unter **Verwaltung → Arbeitskorb** sehen Vereinsadmins und Geschäftsstelle die nach Modulrechten verfügbaren Platz- und Busanfragen sowie Spesen zur Freigabe. Vereinsadmins können über den Arbeitskorb Bexio-Prüffälle in der Synchronisation öffnen. Die Bearbeitung erfolgt im jeweiligen Fachbereich.

In **SFV-Wettkämpfe** zeigt die Einzelvorschau bei einem Platz- oder Terminwechsel betroffene Platzbelegungen und über die SFV-Spiel-ID verknüpfte aktive Teamtermine. Die Bestätigung prüft die Vorschau erneut. Blockierende Konflikte oder fehlende Rechte für betroffene Teamtermine verhindern die Übernahme. Bei erfolgreicher Übernahme werden Wettkampf und verknüpfte Teamtermine gemeinsam aktualisiert. Die bestehenden Dienste erfassen Änderungen und verwalten Benachrichtigungen. Eine Termin- oder Platzänderung an einem bestehenden Spiel erfordert im Web eine Einzelprüfung. Die externe SFV-API behält ihren Importvertrag.

Für die Änderung ist keine Migration erforderlich. Rückweg ist der vorherige Web-Release. Bereits bestätigte fachliche Änderungen bleiben im Änderungsverlauf erhalten.

Auf DEV aktiv als `20261114-clubunio-inbox-sfv-v2`. Rückweg ist `20261113-clubunio-veo-planning`. Die angemeldete HTTPS-Abnahme mit zwei getrennten TOTP-Testkonten hat alle Arbeitskorbbereiche geprüft. Ein synthetisches Spiel wurde in der Sammelvorschau zur Einzelprüfung verwiesen und erst nach bestätigter Einzelvorschau verschoben. Der separate PostgreSQL-Test für die gemeinsame Aktualisierung von Spiel und Teamtermin bestand.

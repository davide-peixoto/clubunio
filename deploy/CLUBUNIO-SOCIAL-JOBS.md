# Geplante Social-Media-Beiträge

**Anlagen & Kommunikation → Social Media → Geplante Jobs**. Pro Regel Name,
Mannschaften, Uhrzeit (Europe/Zurich), Vorlagenversion, Textsprache und Kanäle
wählen. Ein leerer Begleittext wird aus den öffentlichen Spieldaten erzeugt.
Die Vorschau im Jobdialog benutzt denselben serverseitigen JPEG-Renderer wie
der Scheduler und synthetische Beispieldaten. Der Renderer verwendet GD und
Liberation Sans; Umbrüche können vom Browsereditor abweichen.

- **Spieltag**: Täglich ab der gewählten Uhrzeit. Ein Beitrag mit den heutigen
  Wettkämpfen der ausgewählten Mannschaften. Explizite Platzplan-Zuordnungen
  haben Vorrang; ansonsten gilt die SFV-Team-ID am verknüpften Spiel.
  Keine Namenszuordnung. Ohne passende Spiele kein Beitrag. 20 Minuten
  Nachholfenster, danach kein verspäteter Spieltagspost.
- **Schlussresultat**: Start zwischen 00:00 und 05:59. Der Job prüft Spiele der
  letzten drei abgeschlossenen Kalendertage, damit verspätete Resultate
  nachkommen. Nur ausgewählte Mannschaften mit SFV-Team-ID. Der Schedule dient
  zur Auswahl; erst die SFV-Matchdetails mit identischer Match-ID/Anspielzeit,
  `hasMatchEnded=true` und beiden Torzahlen lösen einen Resultatpost aus.
  Keine Spieler- oder Ereignis-Endpunkte. Pro Regel und SFV-Match-ID maximal
  ein Post. Korrekturen bereits publizierter Resultate bleiben redaktionell.
- **Freigabe**: Ein Job ohne Versandfreigabe erzeugt Entwürfe. Für automatische
  Testpublikation braucht das einrichtende Konto zusätzlich Freigabe- und
  Versandrecht; die Rechte werden erneut geprüft. Resultatjobs benötigen
  zusätzlich SFV-Abgleichrecht. Fehlt eines der gewählten Testziele, bleibt
  der gesamte Beitrag ein Entwurf. Es gibt keinen produktiven Fallback.
- **Protokoll**: Letzte Ausführungen und Kanalstatus. Unklare Meta-Zustellungen
  werden nicht automatisch wiederholt. Eine Änderung der Vorlage wird erst
  wirksam, wenn die neue Version im Job ausgewählt und gespeichert wird.

## Migration und Betrieb

Zwei additive Tabellen: `social_jobs` und `social_job_runs`. DEV nach
verschlüsseltem, lesbar geprüftem PostgreSQL-Backup migriert:
`php deploy/emit-vps-dev-social-studio-migration.php clubunio_dev jobs`.
Kein Import, keine Änderung an alten Wettkämpfen und keine Jobvorgaben.
Bestehende Profile werden nicht erweitert. Die Jobausführung verwendet den
bestehenden minutengenauen Pi-Scheduler und bleibt ausserhalb DEV aus.
DEV-Release: `20261113-clubunio-social-jobs`; Rückweg:
`20261113-clubunio-social-studio-delta`. Beim Rücksprung neue Tabellen erhalten.

DEV-Abnahme mit synthetischem SFV-Adapter und echten PostgreSQL-Tabellen:
Teamauswahl, Spieltags- und Resultatgrafik, kein Doppelpost, fehlende
Meta-Testziele, Rückrollen der Daten und Entfernen der Testbilder geprüft.
Keine Live-SFV- oder Meta-Anfrage in dieser Abnahme. Für einen echten
automatischen Post sind weiterhin verifizierte separate Meta-Testziele nötig.

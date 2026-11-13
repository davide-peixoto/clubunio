# Kampagnen: Stammdaten-Pilot

Bei neuen Entwuerfen mit Beides wird pro Mitglied E-Mail vorgeschlagen,
wenn die Rechnungsadresse eine gueltige E-Mail liefert, sonst Post.
Explizit gewaehlte Kanaele und bestehende Entwuerfe bleiben unveraendert.
E-Mail-Versand prueft dieselbe Rechnungsadressquelle erneut. Bei fehlender
Konfiguration gilt die eigene Adresse; eine ungueltige konfigurierte
Elternadresse wird nicht durch eine andere Adresse ersetzt.
Status und Mannschaft der konfigurierten Saison sind filter- und
sortierbar. Mitgliederbearbeitung nutzt bestehende Rechte und Formulare.
Nur nicht freigegebene Entwuerfe koennen geloescht werden. Keine Migration.

Stage: Kampagnen sind unter Anlagen & Kommunikation eingeordnet. Die
Uebersicht verwendet eine Verwaltungstabelle und ein getrenntes Formular.
Empfaengerabfragen qualifizieren die Mandantenspalte und verknuepfen
Mitglieder und Antraege jeweils auch ueber die Mandanten-ID. Keine Migration.

Die Empfaengerliste verwendet die gemeinsame Verwaltungs-Tabelle mit
getrennten Spalten fuer Mitglied, Versandkanal und Status. Keine Migration.

## SMTP-Warteschlange

Nur Stage, nur `stage-mobile-pilot` und eindeutige synthetische Adressen
unter `example.invalid`. Der bestehende SMTP-Testkanal leitet an das feste
Testpostfach um. Flag `CLUBUNIO_CAMPAIGN_TEST_QUEUE` ist standardmaessig aus.
Die additive Migration `2026_11_13_000620_campaign_mail_queue.php` muss
gezielt auf Stage angewendet werden. Der Scheduler ruft bei aktivem Flag
`clubunio:campaign-test-mail` alle zehn Sekunden auf. Konfigurierbar:
`CLUBUNIO_CAMPAIGN_MAIL_PER_MINUTE` (1–30, Standard 5; tatsächlicher Durchsatz
durch Scheduler-Takt begrenzt) und `CLUBUNIO_CAMPAIGN_MAIL_ATTEMPTS` (1–5,
Standard 3). 421 pausiert die Queue zehn Minuten. Explizite 4xx-Antworten
werden begrenzt wiederholt, 5xx nicht. Unklare Ausgaenge erfordern Pruefung.
Nach SMTP-Annahme kann die Geschaeftsstelle einen spaeteren permanenten
Ruecklaeufer manuell erfassen; die Adresse wird mandantenbezogen gesperrt.
Keine automatische Ruecklaeuferabholung. Keine Produktionsfreigabe.

Vereinsadmins sehen Kampagnen des eigenen Vereins lesend. Bearbeitung,
Einladungen und Entscheide bleiben der Geschaeftsstelle vorbehalten.

Die Geschaeftsstelle erstellt Entwuerfe mit E-Mail, Post oder beidem und
bearbeitet die Empfaenger vor der Freigabe. Aktive Erwachsene sind
vorauswaehlbar. Minderjaehrige und unbekanntes Alter bleiben bis zur
separaten Umsetzung des verifizierten Elternversands ausgeschlossen.

Freigabe erzeugt einen 30 Tage gueltigen Token pro Empfaenger fuer beide
Kanaele. Der Browser zeigt keine bestehenden Stammdaten an. Leere Felder
bleiben unveraendert. Erst eine erfolgreiche Antragserstellung verbraucht
den Link. Die Geschaeftsstelle muss den Antrag im bestehenden Workflow
pruefen; ein Kampagnenlink erstellt kein Konto und ersetzt keine
Postfachbestaetigung. Der QR-Brief ist als einzelne Druckansicht verfuegbar.

Freigabe und Antworten sind auf `stage-mobile-pilot` begrenzt. SMTP ist
auf die vorhandene Testumleitung und synthetische Adressen beschraenkt,
kein echter Massenversand. Entwuerfe duerfen auch in anderen Stage-Vereinen
vorbereitet werden. Events, Sponsorenzusagen, Sammeldruck und verifizierte
Familienempfaenger sind Folgepakete.

## Migration und Rueckweg

Nur Stage: `2026_11_13_000500_member_campaigns.php` nach
`StageMigrationTarget::authorize` anwenden. Zwei neue Tabellen und ein
nullable Antragsteller fuer Kampagnenantraege, keine Bestandsmigration.
Mit `deploy/apply-stage-member-campaigns.php` migrieren, danach
`artisan route:cache` und `artisan view:clear` im Stage-Release ausfuehren.
`deploy/check-stage-member-campaigns.php` benutzt synthetische Fixtures
in einer Rueckrolltransaktion und blockiert echten Mailversand.
Vor Code-Rollback offene Kampagnenantraege entscheiden und Links sperren;
Tabellen und Auditverknuepfungen erhalten. DEV und PROD unveraendert.

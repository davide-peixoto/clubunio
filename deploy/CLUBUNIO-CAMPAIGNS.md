# Kampagnen: Stammdaten-Pilot

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

# Persoenliches Menue: vier Originalmodule (04.11.2026)

MobileAccountController.modules verbindet das bestehende Mitgliederverzeichnis,
Mitgliederanmeldungen, Trainingsplaner und Sponsorenlauf mit den bereits
vorbereiteten Originalmasken. Die vorherigen Planungs-/My-Teams-/Spesen-/Anlagen-/
Bus-/Dokumentenpfade bleiben erhalten. Sichtbare mobile Moduloptionen und ein
zugewiesenes mobiles Menueprofil werden weiterhin vor der Freigabe angewendet.

## Vertraege und echte Rechte

| Menue | Standardmaessig geschlossener Serverflag | Clientkompatibilitaet |
| --- | --- | --- |
| Mitglieder | native_members_enabled | members-v1 |
| MitgliederAnmeldungen | native_registrations_enabled | registrations-v1 |
| Trainingsplaner | native_training_enabled | training-v1 |
| Sponsorenlauf | native_sponsor_enabled | sponsor-v1 |

Die Claims sind ausschliesslich Darstellungsvertraeglichkeit. Persoenlicher
Mandantenzutritt und jede einzelne Fachoperation werden weiter serverseitig
geprueft. Das Profilrollenverbot der persoenlichen App bleibt bestehen.
MobileFollowupModules verwendet dieselben echten Fachdienste:

- Verzeichnis: members.view oder ein tatsaechlich sichtbares Mitglied im
  eigenen/vertretenen/berechtigten Dossierscope; keine Erweiterung privater Rechte.
- Anmeldungen: authorizeReader plus registrations.view oder echte zugeordnete
  Anmeldungen aus RegistrationReviewService.visible, einschliesslich TL-Sicht.
- Training: MobileTrainingPlanService.authorizeOperation(view) und bei
  Teamrechten tatsaechliche aktuelle Teams; keine KI-Providerfreigabe daraus.
- Sponsorenlauf: SponsorRunService-Verwaltung oder tatsaechlich sichtbares
  Ereignis via eigener/vertretene Kinder-/Team-/zeitlich gueltiger Helfersicht.

Neue Directory-Readiness sichert auch die beiden direkten Verzeichnisendpoints
(members und globale members/change-requests) ab. Einzeldossiers, Rechnungen,
Dokumente und Ausweise behalten ihre jeweiligen vorhandenen Rechte/Gates.
Environment fuer Directory: CLUBUNIO_MOBILE_NATIVE_MEMBERS_ENABLED=false.
Kein neuer Schemaauftrag, keine neuen Abhaengigkeiten.

## Gestufte Freigabe ohne PHPUnit auf DEV

`deploy/check-mobile-followup-modules.php --foundation --synthetic-rollback`
prueft echte deaktivierte Konfiguration, vier geschuetzte Originalrouten, das
Menue mit alten/neuen Clients und HTTP503 der direkten Modulendpoints.
Foundation verlangt keine noch fehlenden Migrationen100/200/300.

`deploy/check-mobile-followup-modules.php --enabled --synthetic-rollback`
verlangt zuerst alle vier echten Serverflags und die bereits vorhandenen,
geprueft angewendeten Schemata samt Migrationsledger100/200/300. Das Gate legt
keine Tabellen an und wendet keine Migration an. Erst dann laufen die positiven
und negativen Kernelpruefungen mit echten persoenlichen Fachdiensten.

Die gemeinsame Probe in deploy/mobile-followup-menu-probe.php verwendet keine
PHPUnit-Klassen oder Assertions. Sie erzeugt rein synthetische Konten,
Mitglieder, Teams, eine Anmeldung, ein Sponsorereignis und Helferzuordnung in
einer expliziten Transaktion und rollt sie bei Erfolg/Fehler zurueck. HTTP, Mail,
Queue und Providerzugriffe sind geschlossen; weder Mails noch Rechnungen,
Zahlungen oder KI-Auftraege werden ausgefuehrt. Fehlerausgaben nennen nur den
Pruefschritt, Status oder strukturelle SQL-Informationen, keine privaten Werte.

Nachweise pruefen ausser Readiness/alten Clients auch echte Trainer- und
TL-Sicht, eigene Kinder, unbeteiligte Benutzer, Helferablauf, Elternwiderruf,
entzogene Teamzustaendigkeit, mobile Profilfilter, einzelne unabhaengige
Serverflags, Mandantentrennung und die unveraenderte Profil-App-Sperre.
Die lokalen PostgreSQL-Tests nutzen dieselbe echte Kernelprobe unter PHPUnit;
DEV benoetigt dieses Paket nicht. Permanente Freigabe/Deployment und der
native Gesamtnachweis erfolgen koordiniert durch Root.

Gezielter Abschluss: zwei neue Kernel-/PG-Faelle mit110 Pruefungen, gemeinsam
mit bestehendem Verzeichnisfall29 und FacBus-Menuefall19 insgesamt vier Faelle
mit158 Assertions gruen. Alle Daten wurden zurueckgerollt; keine permanente
Anwendung100/200/300 durch dieses Paket. PHP-Lints und gezielter Diffcheck
befundfrei. Das standalone DEV-Gate schliesst lokal am strikten Origin/API-Guard;
die echte DEV-Foundation-/Enabled-Ausfuehrung bleibt Teil des Root-Releases.

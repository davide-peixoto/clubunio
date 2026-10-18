# Abnahme der Beispielprofile

Datum: 17.10.2026. Rechteabnahme: bestanden; Browser-Nebenbefunde offen.

## Umfang und Sicherheit

Automatisierte Abnahme der lokalen ClubUnio-Webanwendung (Basis 455d15e),
mit echten Fachservices, HTTP-Requests, Filament-/Livewire-Pruefungen und
PostgreSQL-Transaktionen auf der separaten ClubUnio-Datenbank.
Keine PHPMaker-DEV-/PROD-Zugriffe, kein Serverdeployment, keine Migration.
Parallel bearbeitete Saison-/Performance-Aenderungen sind nicht Gegenstand
dieser Abnahme und nicht Teil dieses Testpakets.

Jeder Test erzeugt einen eigenen synthetischen Verein, ein neues Testkonto,
ein Profil und eigene Fachdaten. Bestehende Konten werden nicht verwendet oder
umgestellt. Alle Testdaten und Auditeintraege werden zurueckgerollt. Nach jedem
Test wird ein Fingerprint der bestehenden Konten-Aktivierung/Revision,
Benutzerzuordnungen, Profile und Systemrollen mit dem Ausgangswert verglichen.
Der synthetische Verein darf danach nicht mehr existieren. Alle Vergleiche
bestanden. PostgreSQL-Sequenzzaehler koennen trotz Rollback weiterlaufen.

Externe HTTP-Aufrufe und E-Mails sind durch Test-Doubles abgefangen; es wurde
explizit geprueft, dass nichts gesendet oder eingereiht wurde. Keine echten
Bankzahlungen, Bexio-Buchungen oder Cloud-Uploads. Das PDF entsteht lokal aus
synthetischen Rechnungsdaten, nicht durch einen Bexio-Abruf.

## Profildefinitionen

### Geschaeftsstelle nur lesen

Profilname im Test: `Geschäftsstelle nur lesen`.
Alle 24 aktuellen Module erhalten ausschliesslich `view`, entsprechend der
Nur-Lesen-Vorlage. Keine automatische Office-Rolle, keine Benutzerverwaltung.
Downloads/PDFs, Exporte, Uploads und alle Fachmutationen sind nicht enthalten.
Persoenliche Ansichtsfilter bleiben gemaess bestehendem Konzept erlaubt.

### Finanzen bearbeiten

Rechnungen, Spesen, Beitraege und Gutschriften erhalten die jeweils im Katalog
vorhandenen Funktionen `view`, `create`, `update`, `copy`, `deactivate`,
`restore`, `download`, `upload`, `export`. Hinzu kommen `members.view`,
`teams.view`, `seasons.view` fuer Dossier-/Zuordnungskontexte.

Nicht enthalten: `approve`, `send`, `cancel`, `sync`, Bexio-Modulrechte,
Mitglieder-/Sportbearbeitung oder administrative Rechte. "Bearbeiten" wird
hier bewusst nicht mit Freigabe, Stornierung oder Auszahlung gleichgesetzt.
Diese Definition ist eine Testvorlage, keine neue dauerhafte Rollenzuweisung.

## Ergebnisse

| Pruefung | Ergebnis |
| --- | --- |
| Nur Lesen: vollstaendige Modul-/Funktionsmatrix | Nur `view` erlaubt |
| Nur Lesen: Mitglieder-/Rechnungsliste per HTTP | 200, synthetisches Mitglied sichtbar |
| Nur Lesen: Benutzer- und Profilverwaltung | 403 |
| Nur Lesen: direkte Rechnungs-/Spesenanlage | 403, keine Anlage |
| Nur Lesen: Dossier-Untertabellenaktionen | Anzeigen erlaubt, Anlegen/Bearbeiten/Freigabe/PDF/Export gesperrt |
| Finanzen: Rechnung erstellen und Kopf bearbeiten | Gespeichert und per HTTP sichtbar |
| Finanzen: Rechnungsposition anlegen und bearbeiten | Nettobetrag 20 auf 25 CHF korrekt nachgefuehrt |
| Finanzen: Spesenbeleg anlegen und bearbeiten | Gespeichert, Detailansicht200 |
| Finanzen: lokale Rechnungs-PDF | PDF-Inhalt erfolgreich erzeugt |
| Finanzen: Rechnungsexport | Erfolgreiche Exportantwort; keine Vollpruefung aller CSV-Formate |
| Finanzen: Freigabe/Stornierung, Versand/Sync | Direkte Freigabe/Stornierung403, Rechte fuer Versand/Sync gesperrt |
| Finanzen: Spesenstatuswechsel | Keine erlaubten Zielstatus |
| Finanzen: Rechnung im Mitgliederdossier | Bearbeiten erlaubt, Freigabe gesperrt |
| Finanzen: fremder Verein | Eintritt und Anlage verweigert |
| Finanzen: Rechteentzug bei geoeffnetem Anlagedialog | Livewire403, keine weitere Rechnung angelegt |
| Finanzen: nachtraeglicher Schreibversuch / inaktives Profil | 403 bzw. kein Arbeitsbereichszugang |
| Vorhandene Benutzerrechte / Testdatenbereinigung | Fingerprints identisch, Testvereine entfernt |
| Ressourcen-Zuordnung / Eltern- und Untermodulrechte | Bekannte Ressourcen explizit zugeordnet, administrative Ausnahmen gesperrt |

## Nachweis und Wiederholung

`tests/Feature/ProfileAcceptanceTest.php`: 3 Tests, 280 Assertions bestanden.
Gezielte Auswahl aus `tests/Feature/ProfileUiTest.php`: 3 Tests, 284 Assertions
bestanden (Ressourcenabdeckung, Eltern-/Kindrechte, Nur-Lesen-/unbekannte Aktionen).
Gesamt: **6 Tests, 564 Assertions**, keine Fehler. PHP-Syntaxpruefung bestanden.

```powershell
$env:CLUBUNIO_TEST_DB='1'
& C:\xampp\php\php.exe -d extension=pdo_pgsql vendor/phpunit/phpunit/phpunit tests/Feature/ProfileAcceptanceTest.php
& C:\xampp\php\php.exe -d extension=pdo_pgsql vendor/phpunit/phpunit/phpunit tests/Feature/ProfileUiTest.php --filter 'test_all_view_profile_can_read_mapped_resources_but_cannot_mutate|test_relation_requires_child_and_parent_permission|test_read_only_actions_and_unknown_actions_are_denied'
```

Erster Testlauf: ausschliesslich eine falsche Testerwartung zur SQL-Dezimalskala
(`20.00` statt `20.0000`); korrigiert, kompletter Abnahmelauf erneut gruen.
Keine Anwendungskorrektur erforderlich.

## Manueller Browserdurchlauf auf ClubUnio

Am 17.10.2026 auf `https://dev.clubunio.ch`, bereitgestelltes Release
`20261017-clubunio-season-bulk-performance`. UI-gesteuerter Durchlauf im
integrierten Browser, Desktop 1280 x 720; keine HTTP-Skripte als Ersatz fuer
Formularaktionen. Die oben beschriebene automatisierte Abnahme bleibt separat.

Zwei neue, getrennte Testkonten und Profile im synthetischen Verein
`ClubUnio Browser-Abnahme` (12972); Mitglied 77080, Rechnung 10346 und Spesenbeleg
2202. Keine vorhandenen Benutzer umgestellt. Testkonten hatten keinen Zugang
zum SV-Sissach-Mandanten. Die Einrichtung erfolgte mit dem CLI-Helfer
`tools/clubunio-profile-browser-fixture.php`, nicht durch Import bestehender Daten.

| Browserpruefung | Ergebnis |
| --- | --- |
| Beide Konten einzeln anmelden / abmelden | Erfolgreich, korrektes Profil und Testmandant sichtbar |
| Nur Lesen: Mitgliederliste und Mitgliederdossier | Ein synthetisches Mitglied, keine Anlage-/Bearbeitungsaktionen |
| Nur Lesen: Rechnungs-/Spesenuntertabelle | Nur Positionen und Verlauf, kein Bearbeiten/Anlegen/Freigeben |
| Nur Lesen: verschachtelte Rechnungspositionen | Testposition sichtbar, keine Mutationen |
| Nur Lesen: direkte Profil-/Benutzerverwaltung | Beide Seiten zeigen 403 |
| Nur Lesen: direkte lokale Rechnungs-PDF | 403, da download nicht freigegeben |
| Finanzen: Navigation | Finanzmodule und lesende Mitglieder-/Team-/Saisonkontexte; keine Benutzerverwaltung, kein Bexio-Abgleich |
| Finanzen: Rechnung im Mitgliederdossier bearbeiten | Bemerkung gespeichert, beim erneuten Oeffnen korrekt angezeigt |
| Finanzen: Rechnungsposition im verschachtelten Dialog | Brutto und Netto von CHF25 auf CHF30 gespeichert und im Positionsdialog sichtbar |
| Finanzen: lokale PDF-Vorschau | Eine Seite sichtbar, Testposition und gespeicherte Bemerkung korrekt, kein Bexio-Aufruf |
| Finanzen: Spesen im Mitgliederdossier bearbeiten | Bemerkung gespeichert, Mannschaft/Runde/Bankkonto korrekt vorbelegt |
| Finanzen: Freigabe-/Versandaktionen in geprueften Untertabellen | Nicht angeboten |
| Finanzen: direkte Profil-/Benutzerverwaltung und Teamplanung | Alle drei Aufrufe zeigen 403 |
| Speicherung gegenpruefen | Beide Bemerkungen in der DB korrekt, sechs synthetische Auditereignisse vorhanden |
| Bereinigung | Testverein, beide Konten/Profile, Fachdaten und Auditdaten entfernt; bestehende Rechte-Fingerprints identisch |
| Anmeldung nach Bereinigung | Entferntes Finanzkonto wird mit ungueltigen Zugangsdaten abgewiesen |

Bildbelege (ausschliesslich synthetische Daten):
- [Gespeicherte Rechnungsbemerkung](evidence/profile-acceptance-20261017/finance-invoice-saved.jpg)
- [PDF-Vorschau](evidence/profile-acceptance-20261017/finance-pdf.jpg)
- [Gesperrte Teamplanung](evidence/profile-acceptance-20261017/finance-planning-denied.jpg)

### Offene Browser-Nebenbefunde

1. **Aenderungsverlauf zeigt neue Rechnungswerte als leer.** Die gespeicherte
   Bemerkung und PDF sind korrekt, aber der Verlauf zeigt etwa
   `Browser-Abnahme Ausgangsstand -> -`. Ursache im Quellcode eingegrenzt:
   InvoiceWorkflowService schreibt den neuen Zustand verschachtelt unter
   `after_value.invoice`, DossierHistoryService liest Felder direkt an der
   Wurzel. Kein Berechtigungsfehler, aber irrefuehrender Verlaufsdialog.
2. **Elterntabelle/Tab nach verschachtelten Dialogen nicht zuverlaessig frisch.**
   Nach der Positionsaenderung zeigte die Elternzeile noch CHF25, obwohl die
   Position CHF30 anzeigte. Beim anschliessenden Klick auf Spesen wechselte
   die URL zu `relation=2`, der bisherige Inhalt blieb stehen; erst Neuladen
   zeigte Spesen. Auch im Nur-Lesen-Durchlauf nach dem Positionsdialog beobachtet.
   Als UI-Aktualisierungsbefund erfasst, nicht als verlorene Speicherung.
3. **Unvollstaendige deutsche Beschriftung:** Positionsaktion/Dialog heisst
   `Edit line`; im Verlauf erscheinen technische Feldnamen wie `Workflow status id`.

Diese Befunde wurden in diesem reinen Abnahmepaket nicht durch Aenderungen an
der Anwendung behoben. Keine neue Anwendungsversion deployed, keine Migration,
keine echten Nachrichten, Zahlungen oder Bexio-Buchungen ausgelöst.
PHPMaker DEV/PROD unveraendert. CLI-Helfer und Zustandsdatei nach Bereinigung
auch vom Server entfernt; kein oeffentlicher Testendpunkt.

## Nachtrag 18.10.2026: UI-Befunde

Die drei oben dokumentierten Nebenbefunde werden im separaten Korrekturpaket
CLUBUNIO-DOSSIER-UI-FIXES.md behandelt. Historische Ergebnisse bleiben oben
unveraendert nachvollziehbar. Keine Erweiterung der getesteten Profilrechte.

## Grenzen

Automatisierte Abnahme und manueller Browserdurchlauf mit synthetischen
Testkonten ergaenzen sich; keine Abnahme mit echten Benutzerzuordnungen.
Nicht jede moegliche Fachkombination wird durch diese sechs Tests ausgefuehrt;
sie ergaenzen die bereits dokumentierten Profil-/Bestandstests. Beispielsweise
werden nicht alle Uploadformate und Kopier-/Restore-Workflows nochmals ausgefuehrt.
API, SaaS und Betrieb bleiben ausgeschlossen. Die beiden benannten Testprofile
sind nach Abschluss nicht dauerhaft vorhanden; eine spaetere echte Zuordnung
erfordert eine bewusste administrative Entscheidung.
Manuell wurden nicht alle 24 Module, Neuanlage, Exporte, Uploads oder saemtliche
Kopier-/Wiederherstellungsaktionen durchgeklickt; Neuanlage und direkter
Schreibschutz sind durch die obigen automatisierten Tests abgedeckt.

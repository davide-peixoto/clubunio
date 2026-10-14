# ClubUnio: persoenliche Listenansichten

Stand 11.10.2026. Admin/Geschaeftsstelle koennen ueber "Meine Ansichten" die
aktuelle Auswahl speichern, laden, umbenennen, aktualisieren und loeschen.
Verfuegbar bei Mitgliedern, Mitgliederlisten, Anmeldungen, Spesen, Rechnungen
und Trainingszeiten. Maximal 20 Ansichten je Benutzer, Mandant und Liste.

Gespeichert werden Suche, Filter, Sortierung, Status-/Berichtstab und die
Sichtbarkeit umschaltbarer Spalten. Genau eine Ansicht kann Standard sein.
Ein neuer direkter Aufruf ohne explizite URL-Auswahl laedt diesen Standard.
URL-Filter/Suche/Sortierung/Tab haben Vorrang; anschliessend kann eine Ansicht
explizit geladen werden. Ohne Standard bleibt das bisherige Session-Verhalten.
Session-Schluessel sind ebenfalls benutzer- und mandantenbezogen.

## Sicherheit und Speicherung

Migration 000016 erstellt clubora.saved_views nur in der neuen ClubUnio-DB.
Die Auswahl wird mit dem bestehenden Laravel-Schluessel verschluesselt.
Ansichten sind privat, kein Teilen und keine Erweiterung der Datenberechtigung.
Jede Aktion prueft Besitzer, Mandant und freigegebene Liste erneut.
Entfernte Lookupwerte/Spalten oder unbekannte Filter werden vor dem Anwenden
abgewiesen; die bisherige Auswahl bleibt erhalten. Das Formular meldet den
Fehler, eine ungueltige Standardansicht erzeugt einen Hinweis.
Revisionen erkennen veraltete Aenderungen. Account-Sperre und eindeutiger
DB-Index sichern den einzelnen Standard auch bei konkurrierenden Aenderungen.

Keine Migration bestehender PHPMaker-Filter und keine Aenderung an DEV/PROD.
Keine fachlichen Daten, Nachrichten oder externen Dienste werden veraendert.
Rueckkehr auf Release13 ist mit beibehaltener additiver Tabelle moeglich.
APP_KEY muss bei Deployment/Backup erhalten bleiben.

## Pruefung

Vier automatisierte Tests mit synthetischen Mandanten und Rollback pruefen
Speichern/Laden, Verschluesselung, URL-Vorrang, Standards, Umbenennen/Loeschen,
Benutzer-/Listen-Trennung, Revisionen, ungueltige Lookups und Berichtstabs.
Lokaler Browser: Menue und Speicherdialog geprueft, keine echte Ansicht angelegt.
Fachliche Bedienungsabnahme und die restlichen Altlisten bleiben offen.

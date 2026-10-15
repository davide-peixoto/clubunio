# ClubUnio: geschuetzte Dokumentuebertragung (Release72)

## Umfang

Mitgliederanhaenge, Anmeldefotos/-ausweise und neue Spesenanhaenge verwenden
dieselbe bestaetigte Azure-Uebertragung. Die bisherigen privaten Leserouten
und ihre Rollen-/Eltern-/Eigentuemerpruefungen bleiben bestehen. Altdateien und
ihre Pfade werden nicht migriert oder geloescht. Kein oeffentlicher Blobzugriff,
keine Aenderung der PHPMaker-Instanzen, API, SaaS oder Betriebsautomation.

## Ablauf und Fehlerbehandlung

1. Datei, Quelle, aktueller Benutzer und Dateityp/Groesse pruefen.
2. In einer DB-Transaktion verschluesselte Binaerdaten/Metadaten, SHA256,
   UUID-Zielpfad und einen zentralen Synchronisationsauftrag speichern.
3. Azure einmal mit create-only-Berechtigung und Content-MD5 aufrufen.
4. Erst nach bestaetigtem PUT oder identischem SHA256/Groesse beim Wiederlesen
   den Dokumentdatensatz bzw. Spesenverweis veroeffentlichen. Dieselbe lokale
   Transaktion entfernt die verschluesselten Binaerdaten aus dem Staging.

Ein Timeout behaelt die Datei und den Auftrag. Unter Synchronisation kann ein
Admin Azure-Dokumente filtern und erneut pruefen. Nach einem begonnenen PUT
wird zuerst genau derselbe UUID-Pfad gelesen: identische Datei bestaetigen,
nur bei eindeutigem404 denselben Pfad erneut anlegen.503, unklare Antworten
oder andere Dateiinhalte bleiben offen. Keine automatische Wiederholung.
Der Provideraufruf liegt ausserhalb der Publikationstransaktion; bei DB-Fehler
nach erfolgreichem PUT bleibt die Datei beim naechsten Versuch nachweisbar.

Urspruengliche Benutzerrechte werden vor Wiederholung und vor Publikation neu
geprueft. Ein Admin-Replay ersetzt diese Rechte nicht. Oeffentliche Uploads
besitzen stattdessen eine gespeicherte Anmeldungs-/Request-Zuordnung und keinen
fiktiven Benutzer. Ein Wechsel des Azure-Kontos/Containers sperrt Wiederholung;
Schluesselrotation allein nicht. Neue Anmeldungen werden vor dem Azure-Aufruf
mitsamt allen verschluesselten Anhaengen committed. Bei Azure-Fehler wird die
erfolgreiche Anmeldung mit Hinweis bestaetigt, statt eine Neuanmeldung anzuregen.
Annahme einer Anmeldung und Spesenfreigabe werden bei offenen Uploads gesperrt.

Spesen bieten an der Position Beleg hinzufuegen. Neue Verweise verwenden
clubunio/expenses/{tenant}/{line}/{uuid}; vorhandene Altverweise bleiben erhalten.
Maximal zehn Anhaenge inkl. offener Uebertragungen,10MiB je Spesenanhaenge;
Mitglieder/Anmeldung maximal20MiB. Erlaubt PDF/JPEG/PNG, dort zusaetzlich WebP;
Passfotos keine PDFs. Pro Verein100 gehaltene Dateien oder100MiB Nutzdaten.
Verschluesselungs-/DB-Overhead kommt hinzu. Voller Speicher lehnt weitere
Uploads sichtbar ab; nichts wird stillschweigend verworfen.

Manueller Abschluss verlangt eine Begruendung und markiert einen offenen Upload
als cancelled. Dies erzeugt kein Dokument. Verschluesselte Bytes bleiben fuer
bewusste Klaerung erhalten und zaehlen weiterhin zum Limit. Keine automatische
Loeschfrist/Entsorgung implementiert. APP_KEY und DB-Backups bleiben fuer die
Wiederherstellung zwingend; dies ersetzt kein Backup.

## Migration und Ruecknahme

Eigene additive Migration2026_10_16_000068_document_uploads.php zuerst ausfuehren.
Sie erstellt document_uploads und erlaubt NULL-Akteure im Syncjournal fuer
oeffentliche Anmeldungen. Bestehende Daten bleiben unveraendert. Niemals
alte Pflichtakteure fingieren. Lokal und Pi verwenden dieselbe ClubUnio-DB;
die Migration ist einmal angewandt. Bei Ruecknahme Code zurueckschalten,
Tabelle/Journale behalten und neue Uploads pausieren. Kein automatisches down,
keine Anhaenge loeschen. Bei spaeterer Produktivmigration: frische Quelldaten
importieren, additive Migration ausfuehren, Azure-Rechte/privaten Container und
APP_KEY pruefen, dann Upload-/Lesen-/Timeouttest durchfuehren. Kein Wechsel der
heutigen DEV/PROD-Datenbasis.

## Nachweise

DocumentUploadTest prueft verschluesselte Sicherung, Deduplizierung,
Timeout-Recovery ohne zweiten PUT,404-Replay desselben Pfads,503/Pruefsummen-
Konflikt, manuelles Verwerfen, Ziel-/Rechtewechsel, Spesenreferenzen/Revisionen
und Freigabesperre. RegistrationBlobStoreTest prueft create-only und Content-MD5.
Bestehende Anmelde-/Dokument-/Sync-/Bexiotests werden gezielt erneut ausgefuehrt.
Der explizite Azure-Livetest verwendet ausschliesslich ein synthetisches PDF,
prueft private Erreichbarkeit und berechtigte Leser und entfernt seine eigene
Blob-Testdatei anschliessend nach Pruefsummenvergleich.

Abschlusspruefung lokal:58 Tests/432 Assertions erfolgreich, kein Skip.
Inklusive echtem privaten Azure-Upload, SHA256-Ruecklesen, anonymem Zugriff
abgewiesen, Mitglieder-/Elternberechtigungen und sicherem Testblob-Cleanup.
Alle synthetischen DB-Datensaetze wurden per Testtransaktion zurueckgerollt.

## Separater Fachtest GS-00005

GS-00005 wurde im Bexio-Web gezielt mit RE-00013 (Kontakt3, CHF20.00 inkl.MWST)
verrechnet. ClubUnio-Rechnung9774, Kundenguthaben41: CHF10.00 Providerzuordnung
bestaetigt, Guthabenrest0.00. Zweiter Aufruf erzeugt keine zweite Zuordnung.
Der regulaere zentrale Rechnungsabgleich wurde angewandt: Gesamt20.00,
Gutschrift10.00, offen10.00. Lokale Gutschrift18 bestaetigt; enthaltene
Originalsteuer0.748933 bleibt erhalten. Bexio zeigt GS-MWST0.75. Original
RE-00006 bleibt bezahlt40.25 mit0.00 Gutschrift: keine falsche Rueckwirkung.
Zusaetzliche Finanzregression:33 Tests/161 Assertions erfolgreich
(CustomerCreditTest und CreditNoteTest). Zusammen91 Tests/593 Assertions.

Testaufbau: Der normale allgemeine Sandbox-Versand verwendet absichtlich
einen eigenen Testkontakt je Mitglied. Erste Testrechnung9773/RE-00012 gehoerte
daher nicht Kontakt3 und wurde ohne Zahlung/Gutschrift storniert. Ziel9774
nutzt die bestehende bestaetigte Eltern-Fixture und den geprueften Testdispatch;
Kontakt3 wird vor Transmission explizit geprueft. Keine Produktivrechte erweitert.
Bexios Sammelaktion auf der Rechnungsseite buchte zunaechst GS-00004; genau
diese neue Zuordnung wurde wieder entfernt. Bestehende Zuordnungen bleiben.
Erst die Aktion auf GS-00005 selbst verrechnete gezielt mit RE-00013. ClubUnio
hat ausschliesslich die abschliessende Providerzuordnung fuer GS-00005 erfasst.
Keine echte Bankzahlung, keine E-Mail und kein neuer Providerendpoint.

Das Hilfswerkzeug tools/clubunio-customer-credit-check.php ist standardmaessig
lesend; explizite Schalter fuer synthetisches Ziel, Versand/Freigabe, belegten
Guthabenabgleich und regulaeren Rechnungsabgleich. Vorhandene Dispatches werden
wiederverwendet, unklare Ergebnisse nicht erneut angelegt. Kein Cron/Worker.

Bereitgestellt:20261016-clubunio-72-uploads, Ruecksprung20261016-clubunio-navigation.
Config-/Routen-/Viewcache, Mandanten-/Dokumentguards,195 verschluesselte Settings
und46 Nachrichtenvorlagen erfolgreich geprueft.17 Runtime-Quellhashes lokal/Pi
identisch. Azure-Prueffilter angemeldet auf Desktop und390px Mobil geprueft,
kein Seitenoverflow. Die parallele Navigation ist vollstaendig erhalten.
Alle23 bereitgestellten Runtime-/Migrations-/Doku-/Werkzeugdateien haben
abschliessend denselben SHA256 wie lokal. Keine lokalen Geheimnisse deployt.
Am synthetischen offenen Spesenbeleg1083 ist Beleg hinzufuegen verfuegbar,
Dateiuploaddialog korrekt gerendert und ohne Speicherung wieder geschlossen.

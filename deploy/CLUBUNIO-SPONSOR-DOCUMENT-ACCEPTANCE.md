# Dokumentenserien und Sponsorenlauf: Live-Abnahme

25.10.2026. Explizit freigegebener Test mit neuen synthetischen Daten in ClubUnio,
Azure und Bexio SVS Api Test / ID1. Keine Legacy-DEV-/PROD-Aenderung, keine echte
Bankzahlung, keine reale Mitgliederanmeldung oder Materialuebergabe.

## Azure und Dokumentenserien

QA-Mitglied78939, QA-Vorlage72, Serie41, Empfaenger77, Dokument388. Eigene
QA-Saison3389 und TeamSaison3559. Vorschau genau eines Empfaengers, Erstellung
ueber DocumentSeriesService, echter Upload ueber DocumentUploadService/SyncWork.
Privater Container svs-prod-private, neuer Pfad clubunio/members/4/78939/ mit UUID.
Keine Bestandsdatei ueberschrieben oder verschoben.

Original22684 Bytes, SHA256:
`c6c6cb364b5ffa9fd1093c4e1799c0c8f116d04a2093704914318c8fb99bf7ab`.
Direkter Rueckabruf ueber AzureDocumentReader und geschuetzte Dokumentroute mit
identischer Pruefsumme. Anonymer unsignierter Azure-GET404. Wiederaufnahme erzeugt
keine zweite Datei/Empfaengerzeile. Portal-Freigabe bewusst false, deshalb keine
Materialquittung oder externe Benachrichtigung. PDF visuell A4 geprueft.
Keine echte Unterschrift simuliert und keine rechtliche Erklaerung abgegeben.

## Bexio-Sandbox

Anlass134, Teilnehmer134, Zusage mit100 Rappen/Runde, fuenf freigegebene Runden,
Obergrenze300 Rappen. Resultat CHF3.00. Kontakt8 ausschliesslich synthetische
Testadresse, E-Mail admin@svsissach.ch. Lokale Abrechnung42, Bexio17 / RE-00017.
Fachservice: vorbereiten, Kontakt erstellen, Entwurf erstellen, ausstellen,
Testversand und Statusrueckfluss erfolgreich. Bexio bestaetigt den Versand;
tatsaechlicher Eingang im persoenlichen Postfach wurde nicht geprueft.
Bezahlt0, Gutschrift0, Zustand Versendet. Keine Zahlung ausgeloest oder behauptet.

Kataloge frisch gelesen, nicht geraten: Benutzer/Sprache/Bank/Waehrung/Einheit1,
Zahlungsart4, Steuer14 (8.1%), freies Ertragskonto143, MWST-Typ0, Ziel30 Tage.
MWST-haltige Rechnung inkl. Steuerbetrag0.22 und Total3.00 von Bexio berechnet.
Zweiseitiges Bexio-PDF106965 Bytes einschliesslich QR-Zahlteil visuell geprueft.
QR-Code wurde nicht fuer eine Zahlung verwendet.

Vorherige Modulkonfiguration wiederhergestellt (inaktiv). PDF bleibt lesbar;
erneute Abrufe lokal und auf Pi erfolgreich. Keine bestehenden Bexio-Settings
ueberschrieben, keine produktive Verbindung angelegt.

## Gefundene Korrektur

Der echte Laenderkatalog liefert `iso_3166_alpha2`, nicht `iso3166_alpha2`.
Adapter und Testdouble auf das tatsaechliche Feld korrigiert. Zuvor stoppten zwei
Versuche in der Vorpruefung, ohne Kontakt-/Rechnungsanlage. Entsprechende
Sync-Auftraege bleiben als Fehlerhistorie erhalten. Kein unsicherer Schreibaufruf
wurde wiederholt. Quelle fuer den Providervertrag: https://docs.bexio.com/.

## Isolation und Wiederholung

CLI-Werkzeug tools/clubunio-sponsor-document-acceptance.php erfordert
CLUBUNIO_LIVE_ACCEPTANCE=1, feste ClubUnio-DB, vorhandenen Admin und festen
SVS-Verein. Journal unter storage/app/private, nicht in Git und nicht oeffentlich.
Nur neue QA-Datensaetze, keine Berechtigungs-/Accountaenderungen, kein DB-Refresh.
Vorheriger Bestand in sieben Fachtabellen per ID-Liste und Hash unveraendert.
QA-Saison/Teams/Zuordnungen anschliessend deaktiviert, Anlass geschlossen.
QA-Mitglied und unveraenderliche Dokument-/Abrechnungsnachweise bleiben fuer
geschuetzte Nachpruefungen erhalten. Keine Aktivierung oeffentlicher Zusagen.

Wiederholung nur auf vorhandener Journalidentitaet. Keine neue Kontakt-/Rechnungs-
Anlage durch pauschale Wiederholung; Schreibschritte sind einzeln benannt und
statusgebunden. Keine Bereinigungs-/Loeschfunktion fuer finanzielle oder immutable
Evidenz. Versand wird nicht erneut ausgefuehrt, wenn bereits Versendet.

## Testgrenzen

14 Billing-/Provider-Tests mit Doubles:73 Assertions erfolgreich. Zusaetzlich
drei explizit freigegebene Live-HTTP-/Livewire-Pruefungen:27 Assertions erfolgreich,
echte Azure-/Bexio-Lesezugriffe, keine neuen Schreibaufrufe im Test.
Diese Pruefungen laufen im authentifizierten Laravel-Webtestkernel; sie ersetzen
keine manuelle Desktop-/Mobilpruefung durch den Pi-Proxy. Die Chrome-Sitzung
zeigte weiterhin Login. Keine Browserabnahme behaupten.
Keine neue Schemamigration. Code-Rollback behaelt QA-Evidenz/Journal/Dateien.
API, SaaS, Betrieb und bestehendes PHPMaker DEV/PROD unveraendert.

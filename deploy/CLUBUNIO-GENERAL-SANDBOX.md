# Allgemeiner Webversand und Statusrueckfluss, Release46

## Umfang

Finanzen > Bexio-Versandpruefung verwendet weiterhin die normalen Freigabe-,
Empfaenger-, Positions-, Rabatt-, Parameter- und Azure-Anhangpruefungen.
Admins koennen eine aktuelle eigene Vorschau ausdruecklich in SVS Api Test
uebertragen. Keine freie Payload und keine Umstellung des allgemeinen
Mock-Adapters. Der reale Transport bleibt ausschliesslich sandboxgebunden.
Zulaessig sind aktive, nicht importierte Belege der in der Sandbox-Registry
registrierten synthetischen Mitglieder (Member fuer Rechnungen, Supplier fuer
Spesen). Andere Mitglieder und importierte Belege sind serverseitig gesperrt.
Freigabe Rechnung released bzw. Spesen Z bleibt Pflicht.

Migration44 ersetzt die Ein-Beleg-je-Art-Grenze durch eindeutige lokale
Dokumentzuordnungen, behaelt pro Art genau einen festen Basistest. Zusaetzlich
source_trial_id, erwarteter Centbetrag und dauerhaft gespeicherte Upload-UUIDs.
Vorschau vor Versand frisch validiert, Belegschnappschuss unter Tenant-Lock,
eindeutiger Auftrag je Dokument, Journal vor POST, kein automatischer Retry.
Azure-Anhaenge vor Upload erneut auf SHA256/Groesse geprueft; persistierte UUIDs
werden beim Ruecklesen geprueft. Mehrfachreferenzen derselben Datei dedupliziert.
Teilweise/unklare Uploads nicht blind zuruecksetzen. Generischer Text im Journal,
keine Token, Originaldateinamen, Providerbodies oder Dateiinhalte.

Ruecklesen prueft Dokumentmarker, Kontakt, Betrag, Entwurfsstatus, Waehrung und
Anhaenge. Erst danach lokale Bexio-ID. Wiederholter Versand accepted ist No-op.
Statusabgleich ist im Sandboxmodus ausschliesslich fuer accepted-Registrybelege
und Admins erlaubt; lokale ID, Mitglied und Verbindungshash erneut geprueft.
Remote-Profil, Marker/Kontakt und ID vor Statusvorschlag geprueft. Allgemeine
Aktivierung bleibt aus; Ausnahme betrifft nur diese expliziten Sandbox-Belege.
Keine automatische Aufgabe, kein API-/SaaS-/Betriebsumbau.

## Testdaten und Liveergebnis

Werkzeug tools/clubunio-bexio-general-check.php --prepare legt idempotent zwei
synthetische Belege an. Nur synthetische Mitglieder erhalten CH/Empfaengerlookup;
ein eigener Spesentyp sandbox_web bekommt das bereits gepruefte Sandboxkonto.
Builder-Parameter werden auditiert aus den erfolgreich geprueften Basis-Payloads
uebernommen. Kein importierter Spesentyp und kein Originalmitglied geaendert.
Die Freigaben dieser synthetischen Fixtures sind technische Testvorbereitung,
kein Nachweis eines vollstaendigen fachlichen Freigabeprozesses.

- Rechnung7765: zwei Positionen brutto13/9, Rabatt je1, netto12/8, SummeCHF20.
  Allgemeiner Versand erfolgreich, Bexio3, accepted. Live Status7 rueckgelesen
  und ueber vorhandenes Mapping auf Rechnung/Positionen angewendet (Check164).
- Spesen1114: zwei Positionen12/8, SummeCHF20, freiwillig ohne Pflichtbeleg.
  Allgemeiner Versand erfolgreich, Bill ebb98b7c-ac67-4ce0-bda4-b0e598c8a708.
  Live DRAFT unveraendert (Check165); bleibt korrekt Z, nicht bezahlt.
- Azure-Anhangpfad wird automatisiert mit synthetischen PDF-Bytes getestet;
  der echte PDF-Upload des festen Basistests wurde bereits in Release45 geprueft.
- PAID/Restbetrag0, lokale Uebernahme Z->B und Ereignis-Deduplizierung werden
  automatisiert mit gefakten Providerantworten getestet. Keine Bexio-Zahlung
  erzeugt; Live-PAID-Uebernahme bleibt damit eine separate Fachabnahme.

--send verwendet genau die Services der Webaktion; --status liest und
--apply-status uebernimmt ausschliesslich einen bereiten Vorschlag. Ohne Flags
nur Auftragsmetadaten. Bestehende accepted-Belege werden nicht erneut angelegt.

## Migration und verbleibende Grenzen

Release46 auf Pi deployed; Status-Nachtest180/181 unveraendert. Tests31/183 sowie
59/220 erfolgreich (ueberlappende Suites). Letzter Lauf inklusive Livewire-Senden,
Azure-PDF-Manifest/Upload und PAID-Uebernahme/Deduplizierung. Desktop-Sandbox,
Versandpruefung, Statusabgleich und Versandansicht390px visuell geprueft.

Nur eigene gemeinsame ClubUnio-DB. Keine Aenderung an PHPMaker DEV/PROD.
Migration44 additiv bis auf Ersatz der Unique-Regel; bei Ruecksprung45 bleiben
neue Auftraege erhalten, dessen Ein-Beleg-Anzeige/Prepare-Auswahl ist fuer mehrere
Belege nicht vorgesehen. Deshalb nicht auf45 zurueckrollen und weiter senden;
zuerst Schreibaktionen stoppen und Auftraege fachlich pruefen. Keine automatische
down-Migration, keine externen Sandbox-Objekte loeschen.

Offen: Elternempfaenger im echten Sandboxversand (aktuell nur Member), Artikel-
und weitere Steuerfaelle, Rechnungsfreigabe/QR/Stornierung, Zahlungsauftraege,
Live-PAID und allgemeine Produktivfreigabe. Keine neuen Cronjobs, Zahlungen,
Mitgliedermails oder produktiven Push-Nachrichten. API, SaaS und Betrieb geparkt.

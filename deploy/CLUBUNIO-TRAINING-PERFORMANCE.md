# ClubUnio: KI-Trainingsplanung und Trainings-PDFs

18.10.2026. Nur ClubUnio-Web; API, SaaS, Betrieb und PHPMaker DEV/PROD
unveraendert. Keine echten Trainingsaenderungen oder externen KI-Aufrufe.

## Umsetzung

Die paginierte20er-Liste liest nur ID, Titel, TeamSaisonID, Freigabe und
Aenderungsdatum. Keine input_json/plan_json/sonstigen Vollspalten. Filter,
Archivwahl, Sortierung und Querystring unveraendert; Teamlabels einmal nach
ID indizieren statt je Zeile linear suchen. Detail/Bearbeitung/PDF laden
weiterhin vollstaendige Planinhalte ausschliesslich nach Rechtepruefung.

visible prueft den frischen training.view-Zugang und spart bei expliziten
unbeschraenkten Fachleserechten die ungenutzte Teamliste. Trainer/TL behalten
die bisherigen aktiven Mannschaften und owner/shared-Regel. Keine neuen
Rechte, kein Cache ueber Aufrufe hinweg, keine Abkuerzung fuer Schreibrechte.

Anfragelimits als eine PostgreSQL-Aggregatabfrage statt drei getrennten
Zaehlungen unter demselben Vereinslock. Unveraendert: KI1/Minute und20/Tag je
Account,100/Tag je Verein; Kopien getrennt10/Minute,100/Tag,500/Tag. Strikte
Zeitgrenzen und abgeschlossene/fehlgeschlagene Anfragen zaehlen weiterhin.
Vorhandene Requestkeys, Idempotenz, Version, Audit und frische Nachpruefung
nach Providerantwort bleiben erhalten. Keine automatische Wiederholung.

PDF: nur der autorisierte Datenzugriff optimiert. Dompdf-Renderer, Schrift,
Spielfeld-SVG, Inhalt, Format und no-store/inline/Sicherheitsheader unveraendert.
Kein PDF-Cache und keine externe Ressource; Remote/PHP/JavaScript bleiben aus.
Der Renderer war in der Ausgangsmessung bereits kurz; keine riskante Aenderung
an Diagrammen oder Qualitaet allein fuer eine kleinere Millisekundenzahl.

KI-Request unveraendert: file_search zwingend, striktes Schema, store=false,
vollstaendige Retrieval-Antwort erforderlich, SV Sissach/SFV, Phasenreihenfolge
und exakte Dauer validieren. Timeout85s bleibt; externe Anbieterzeit wird nicht
als lokale Seiten-/PDF-Laufzeit ausgegeben. Kein kostenpflichtiger Live-Aufruf
fuer diese Performancepruefung, keine neue API oder Hintergrundverarbeitung.

## Messung und Tests

tools/clubunio-training-performance-check.php prueft CLI und exakte ClubUnio-DB;
erstellt synthetischen Verein/Account/Team und25 Plaene mit drei Uebungen.
Misst Listencontroller inkl. Viewrender, autorisierten Planread, PDF-Ende-zu-Ende
und private Kopie ohne Provider. Queryzeit/Anzahl und geladene Listenbytes
ausgeben, nicht Planinhalte oder Identitaeten. HTTP gesperrt, Mail gefaked.
Alles im finally zurueckrollen; PostgreSQL-Sequenzen koennen Luecken erhalten.

Ausgangsmessung Pi: Liste568ms/18 Queries/527ms DB,20 Zeilen37041 Bytes.
Planread142ms/5 Queries/141ms DB. PDF391ms/6 Queries/168ms DB.
Private Kopie684ms/22 Queries/608ms DB. Einzelstichproben, kein Lasttest/SLA;
PDF-Groesse schwankt durch Dokumentmetadaten.

Nachmessung Pi: Liste476ms/16 Queries/435ms DB;20 Zeilen2441 Bytes (rund93%
weniger geladene Zeilendaten). Planread91ms/3 Queries/90ms DB. PDF333ms/
4 Queries/115ms DB. Private Kopie588ms/18 Queries/510ms DB. Rund16% bzw.15%
weniger Zeit fuer Liste bzw. PDF in dieser Stichprobe, keine Zusage fuer alle
Plaene oder externe KI-Antworten. Alle synthetischen Aenderungen zurueckgerollt.

Erfolgreich:10 bisherige TrainingPlanTest-Faelle/82 Assertions, vier neue
Tests/35 Assertions fuer Metadatenprojektion, frische Teamsperre beim PDF,
alle KI-Limits und unveraenderte Speicherung bei falscher Qualitaet/Dauer/
Phasenreihenfolge. Zwei SportsProfilePermissionTest-Faelle/46 Assertions und
drei LegacyTrainingMigrationTest-Faelle/26 Assertions: getrennte Downloadrechte,
Nur-Lesen, Archiv-/Kopiergrenzen und Altplan-Qualitaet erhalten. Getrennte Laeufe.

Pint/Syntax, View-/Routen-/Tenant-/Profilchecks erfolgreich. Wiederholung des
bestehenden PDF-Web-/Revisionstests1/17 erfolgreich; synthetisches A4-PDF mit
Poppler auf allen drei Seiten visuell geprueft: Schrift/Umlaute, Text und
Spielfeldgrafiken korrekt, kein JavaScript. Kein PDF-Testartefakt im Repository.

Angemeldeter Chrome-Test nach Deployment: Archivliste, Suche mit beibehaltener
Archivwahl und Teamlabels korrekt; Archivdetail92 mit privatem Altplan und
ausstehender Qualitaetspruefung sichtbar. Keine Kopie/Generierung/Bearbeitung
oder Wiederherstellung ausgefuehrt. Bestehendes Archiv-PDF92 serverseitig
zweimal HTTP200/39906 Bytes im Accesslog bestaetigt. Die Browserautomation des
PDF-Tabs blieb durch eine fremde Erweiterungsoberflaeche blockiert; daher keine
vollstaendige visuelle Browserabnahme dieses Archiv-PDFs behaupten. Keine
neue Anwendungsexception zum PDF-Aufruf erkennbar. Release
20261018-clubunio-training-performance aktiv.

## Migration und Rollback

Keine Migration, Settingsaenderung, Datenuebernahme oder neue Abhaengigkeit.
Controller, Service und Listenview gezielt gemeinsam ausrollen; Caches und
bestehende Tenant-/Profil-/Routenchecks vor Aktivierung. Basis/Ruecksprung:
20261018-clubunio-dossier-ui-fixes. Keine .env, Secrets, Uploads, vendor oder
Laufzeitdateien committen. PDF-/Listenmessungen beruehren nur Rollback-Fixtures.

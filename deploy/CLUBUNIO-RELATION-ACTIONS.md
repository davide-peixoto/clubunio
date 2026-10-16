# Untertabellen: direkte Lebenszyklusaktionen

Stand 16.10.2026. Nur ClubUnio, keine PHPMaker-DEV/PROD-Aenderungen.

- Spieler, Staff, TL, Mannschaft/Saison, Familien, Beitragsregeln und
  Trainingszeiten: Wiederherstellen neben vorhandener Kopie/Deaktivierung.
- Bankkonten, Bemerkungen, Dokumente und Veranstaltungsteilnehmer: direkte
  bestaetigte Deaktivierung/Wiederherstellung, ueber Aktivfilter erreichbar.
- Schluessel: Rueckgabe erfassen deaktiviert die Zuordnung mit Audit;
  Wiederherstellen korrigiert eine irrtuemliche Rueckgabe. Keine physische
  Ausgabe, keine neue Schluesselkopie und kein eigenes Rueckgabedatumfeld.
- Manuelle Rechnungspositionen: Kopie nur innerhalb bearbeitbarer Rechnung;
  keine Beitragspositionen kopieren, keine externen IDs uebernehmen.
- Spesenpositionen: Kopie ohne Datei, externe ID oder KI-Nachweis. Der
  Fachservice berechnet einen allfaelligen Dressreinigungs-Vorschlag neu.
  Direkte Aktivstatusaktionen pruefen Positions- und Belegrevision.

Alle Aktionen nutzen bestehende Fachservices fuer Mandant, Dossierbindung,
Rechte, Dubletten, Revisionen und Audit. Ein Wiederherstellen ist keine
Umgehung inaktiver Stammdaten oder gesperrter Finanzbelege. Dokumente behalten
ihre Portalfreigabe; Wiederherstellen kann deshalb die bestehende interne
Benachrichtigung erneut ausloesen. Es wird keine Datei geloescht/kopiert.

Migration: keine Schemaaenderung, kein Import oder DB-Refresh. Historische
Quittungen, gebuchte Finanzen und Verrechnungsnachweise bleiben unveraendert.
API/SaaS/Betriebserweiterungen ausgeschlossen. Keine echten Bankzahlungen.

Tests: transaktionale Livewire-Fachtests fuer Zuordnungswiederherstellung,
Konten/Bemerkungen/Dokumente, Schluessel und Gaeste, Positionskopien und
Spesenaktivstatus. Vorhandene Rollen-/Mandanten-/Versionsregressionen.
Rollout als Dateidelta auf aktuellem ClubUnio-Release; Caches neu bauen.
Rollback durch vorheriges Release ohne Datenruecksetzung.

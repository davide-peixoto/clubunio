# Sportneutrale Anlagen und Trainingszeiten

## Bedienung

- Neue Vereine aus der Plattformverwaltung erhalten nach der Migration den
  sportneutralen Modus. Bestehende Vereine bleiben im bisherigen Modus.
  Plattformadmins koennen bestehende Vereine einzeln umstellen; SV Sissach
  bleibt explizit im bisherigen Modus.
- Unter **Anlagenstatus > Buchbare Anlagen verwalten** in der Kategorie
  **Buchbare Anlage** Name, stabilen Code, Reihenfolge und Typ angeben:
  Spielfeld, Halle, Court oder andere Anlage. Der gewaehlte Anfangsstatus wird
  zusammen mit der Anlage gespeichert. Statuswerte sind wie bisher
  mandantenbezogen und werden im Anlagenstatus-Katalog gepflegt.
- Eine Anlage ohne Teilflaechen wird ganz gebucht. Bei Bedarf Teilflaechen in
  der Anlagenbelegung einrichten und im Training explizit waehlen. Zwei
  Sportarten, die dieselbe physische Halle nutzen, waehlen dieselbe Anlage,
  nicht zwei separate Stammsaetze. Fuer gleichzeitig nutzbare Zonen einer
  Halle Teilflaechen unter derselben Anlage verwenden.
- Im Trainingsformular und im Saisonassistenten ist die buchbare Anlage
  verbindlich; Freitext-Halle und die drei Sissacher Varianten werden dort
  ausgeblendet. Beispiel: Fussballteam im Sommer auf dem Aussenfeld, im Winter
  in der Halle. Die Perioden sind frei benennbare Auswahlwerte. Die erste
  aktive Periode initialisiert bei einem neuen Verein die Einstellung
  **Aktiver Trainingsplan**. Danach wechselt die Verwaltung die Periode unter
  **Allgemein**. Es erfolgt kein automatischer Wechsel nach Kalendermonat.
- Web-Plantafel und native Tagesansicht lesen dieselbe datierte Belegung
  einschliesslich einmaliger Verschiebungen. Sperren und Ueberschneidungen sind
  dort als Konflikte sichtbar. Signage zeigt gesperrte Anlagen im Status, aber
  keine betroffenen Trainings in der Tagesliste. Die Wochenvorlage unter
  **Meine Teams** zeigt die aktive Periode; fuer tagesbezogene Ausnahmen und
  Sperren gilt der verlinkte Kalender. Das bestehende mobile DTO bleibt
  kompatibel; `facility_kind` ist in der Kalenderprojektion nur additiv.

## Migration und Rueckweg

1. ClubUnio-Datenbank sichern. Die additive Migration
   `2026_11_09_000100_sport_neutral_facilities.php` erweitert nur
   `clubora.tenants` und `clubora.training_options`. Bestehende Vereine behalten
   `legacy`; bestehende Optionen erhalten keinen geratenen Anlagentyp.
2. Web-, API- und Signage-Code zusammen ausrollen. Mit einem separaten
   Testverein das Anlegen einer Anlage inklusive Anfangsstatus pruefen.
   SV Sissach weder umstellen noch bestehende Trainingszeiten umschreiben.
3. Bei einem bestehenden Verein zuerst alle aktiven Wochenzeiten mit
   bestehenden Anlagen-IDs abgleichen, Freitext-Hallen zuordnen, Status
   vervollstaendigen und alternative Platzvarianten bereinigen. Die
   Plattformaktion verlangt einen Grund und stoppt bei unklaren aktiven
   Zuordnungen. Die Umstellung legt weder Anlagen an noch korrigiert sie
   Bestandsbuchungen. Die aktive Periode nach der Umstellung kontrollieren.
4. Vor der ersten neutralen Nutzung ist ein Ruecksprung auf die vorherige
   Version mit verbleibenden Spalten moeglich. Nach der ersten Nutzung den
   kompatiblen Leser erhalten; Legacy-Code bildet neutrale Plaene nicht
   zuverlaessig ab. Kein automatischer Down-Migrate oder Moduswechsel zurueck.

Keine Mandanten-Sportartenliste, Verbandsanbindung, KI-Vorlagen oder
sportartspezifischen Spielregeln in diesem Paket. Keine Aenderung in
PHPMaker DEV/PROD.

## Abnahme

- Drei isolierte Vereinsfixtures: eine Winterhalle, zwei Courts und vier
  gemeinsam nutzbare Anlagen. Livewire-Plantafel und Signage-HTML gerendert;
  mobile Kalender-, Status- und Mannschaftsprojektion geprueft.
- Winter-/Sommerwechsel, Pflichtstatus, Sperren, Ganzflaeche, Konflikte zwischen
  Mannschaften, parallele Belegung verschiedener Anlagen, Deaktivierungsschutz
  und Mandantentrennung getestet. Flutter-Widgettests mit 1, 2 und 4 Anlagen.
- DDL auf einer isolierten PostgreSQL-Instanz geprueft. Keine Migration
  in ClubUnio DEV angewendet und kein Server- oder App-Release vorgenommen.
  Angemeldete Browser-/native Geraeteabnahme auf einer bereitgestellten
  Testumgebung bleibt getrennt.

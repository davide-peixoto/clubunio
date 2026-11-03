# Zentraler aktiver Trainingsplan

Stand: 03.11.2026. Nur ClubUnio; PHPMaker DEV/PROD unveraendert.

Unter Einstellungen > Allgemein > Aktiver Trainingsplan waehlt ein Admin
Sommer oder Winter aus den aktiven Trainingsperioden des Vereins.
Nicht festgelegt zeigt keine Trainingszeiten auf Signage.
Die bestehenden Adminrechte fuer diese Auswahl bleiben erhalten.
Speichern verwendet SettingsService und protokolliert den Akteur in setting_changes.

Trainingszeiten zeigen den aktiven Plan im Seitenkopf. Alle Perioden bleiben
bearbeitbar; bestehende Listenfilter bleiben erhalten. Die Signage-Vorschau folgt
standardmaessig dem aktiven Plan, erlaubt aber eine temporaere Vorschauperiode.
Die oeffentliche Signage folgt ausschliesslich dem zentralen Wert, auch bei alten
Links mit period_id. Aenderungen greifen bei der naechsten Aktualisierung.
Das Vereinsdesign bietet keine zweite schreibbare Periodenauswahl mehr.

## Speicherung und Migration

Migration 2026_11_03_000090_active_training_plan verschiebt SIGNAGE_PERIOD_ID von
signage nach general und benennt die Beschriftung in Aktiver Trainingsplan um.
Technischer Schluessel, native training_options-ID, Datensatz-ID, verschluesselter
aktiver Wert und vorhandener Aenderungsverlauf bleiben erhalten. Bisher nicht
freigegebene oder fehlende Einstellungen werden explizit als 0/Nicht festgelegt
abgebildet. Der alte Importwert SommerWinter bleibt schreibgeschuetzter Altbestand.
Kein automatischer Wechsel nach Kalenderdatum und keine Aenderung der Trainingszeiten.
Vor Umstellung: SV Sissach, Setting14255, Periode22/Sommer. Diese Auswahl bleibt erhalten.

ActiveTrainingPlan validiert Verein, Kategorie und Aktivstatus beim Lesen.
Unbekannte/inaktive Werte liefern keinen Plan, keine fremden Daten. Die Aufloesung
unterstuetzt vor der Migration noch den alten Speicherort, ohne Schreibfallback.

Nur diese Migration ausfuehren, nicht alle parallelen Mobile-Migrationen:
`php artisan migrate --database=clubunio --path=database/clubunio/2026_11_03_000090_active_training_plan.php --force`

Bei Rollback zuerst diese Migration gezielt zuruecknehmen (area wieder signage),
danach auf den vorigen Release wechseln; keine anderen Migrationsbatches rollen.
Der gewaehlte Plan bleibt auch beim Down erhalten. Lokale Instanz und ClubUnio-Pi
nutzen dieselbe ClubUnio-DB; Migration deshalb nur einmal ausfuehren.

## Pruefung

SettingOptionsTest, SignageDesignTest, PublicWebTest: 24 Tests/161 Assertions.
Zusaetzlicher Rechte-Retest: 1 Test/16 Assertions.
ClubUnioTest --filter signage: 6 Tests/146 Assertions.
Rollback-Fixtures pruefen Auswahl, Audit, Adminpflicht, inaktive/unbekannte Werte,
Livewire-Dialog, Trainingszeiten-Kopf, Vorschau, unveraenderte Designwerte,
idempotente Migration und den oeffentlichen Signage-Abruf ohne URL-Uebersteuerung.

Release20261103-clubunio-active-training-plan aktiviert, Migration000090 einzeln
ausgefuehrt, vier Servergates erfolgreich. Angemeldete Desktop-Sichtpruefung:
Dropdown Sommer/Winter/Nicht festgelegt, Trainingszeiten-Kopf und zentrale
Signage-Vorschau korrekt; Design nur Farbe/Name/Logo. Keine Werte beim Browsertest
gespeichert. Vorher/nachher Setting14255, Periode22/Sommer. Siehe PI.md.

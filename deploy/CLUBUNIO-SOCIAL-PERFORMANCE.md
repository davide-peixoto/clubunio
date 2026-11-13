# Social Media: Ladezeit

DEV-Release `20261113-clubunio-social-performance`, 13.11.2026. Der
Browser laedt pro Tab nur dessen Daten. Der Job-Tab nutzt einen Aufruf;
Canvas-Code und Auswahllisten werden erst beim Oeffnen des Editors geladen.
Ein Tabwechsel bricht den vorherigen Browserabruf ab. Alte Browserclients
koennen weiterhin die ungeteilte Antwort und den separaten Job-Endpunkt lesen.
Mandantenkontext und Modulrechte werden weiterhin pro Request geprueft;
private Daten bleiben `no-store`.

| DEV-Kernelmessung | SQL alt → neu | JSON alt → neu | Median alt → neu |
| --- | ---: | ---: | ---: |
| Spiele/Start | 14 → 9 | 1660 → 352 B | 653 → 419 ms |
| Geplante Jobs | 26 → 10 | 5655 → 4220 B | 1232 → 530 ms |

Siebener-Median nach einem Aufwaermaufruf, gleicher DEV-Datenbestand, ohne
Browser und Netzweg zum Endgeraet. Studio-JS beim Start: rund 98 → 10 KB
komprimiert; Canvas bleibt ein eigener, bedarfsgeladener Chunk. Der
CLI-Read-only-Probe `check-pi-dev-social-performance.php` vergleicht die
Projektionen mit der alten Antwort und prueft veralteten Mandantenkontext,
ungueltige Abschnitte, Cache-Schutz und ausbleibende Provideraufrufe.
Desktop-/Mobil-Browsertest mit synthetischen Daten: Lazy Loading, ein GET
fuer Jobs, Vorlageneditor, Zuschnitt und nicht leere JPEG-Exporte.

Keine Datenmigration oder Aenderung an Jobregeln, Entwuerfen, Scheduler
oder Meta-Konfiguration. Das vorgefundene deaktivierte DEV-Ausfuehrungsflag
blieb unveraendert. Bei Ruecksprung auf `20261113-clubunio-social-jobs-sfv`
PHP-FPM wie beim Release neu laden; gemeinsame Dateien und DB beibehalten.
Stage und PROD blieben unveraendert.

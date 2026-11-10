# Statische Livewire-Dateien bei Installation und Update

Livewire ist eine Composer-Abhaengigkeit. Die Installation des Pakets stellt
seine Dateien unter `vendor` bereit, veroeffentlicht sie aber nicht automatisch
im Webroot. `filament:assets` allein veroeffentlicht Livewire ebenfalls nicht.
Ohne statisches Manifest liefert Livewire sein JavaScript ueber eine PHP-Route.
Auf Stage scheiterte Symfony dabei an der nicht verfuegbaren PHP-Funktion
`ignore_user_abort()`: Loginseite HTTP 200, Livewire-JavaScript HTTP 500.

## Installationspflicht

- Normale Composer-Installationen und -Updates veroeffentlichen und pruefen
  die Dateien im Post-Autoload-Schritt.
- Bei `composer install --no-scripts` oder bei einem Release, das `vendor`
  separat uebernimmt, nach Einrichten der privaten Konfiguration und vor
  Aktivierung mit dem PHP der Zielumgebung ausfuehren:

  ```sh
  php tools/publish-livewire-assets.php
  ```

- Auch bei RunCloud oder manueller Installation in den Build aufnehmen.
  Der Schritt ist wiederholbar und vergleicht das Manifest und die vier
  JavaScript-Varianten mit dem aktuell installierten Paket. Bei fehlenden
  oder veralteten Dateien den Release nicht aktivieren.
- Webroot ist `public/`; `/vendor/livewire/` statisch ausliefern und
  den PHP-Fallback nicht als Abnahme akzeptieren. Das Verzeichnis ist ein
  generiertes Release-Artefakt und wird nicht in Git gespeichert.
- Nach Aktivierung: Loginseite laden, URL unter `/vendor/livewire/` mit
  HTTP 200 pruefen und die Formularantwort pruefen. Keine Aenderung an
  der PHP-Sicherheitskonfiguration erforderlich.

## Stage-Nachweis

10.11.2026: Dateien auf Stage mit PHP 8.3 veroeffentlicht und byteweise mit
dem installierten Paket verglichen. Loginseite referenziert nun das statische
Livewire-Skript (HTTP 200, 261969 Bytes). Synthetischer Login mit ungueltigen
Daten liefert eine regulaere Validierungsantwort ueber Livewire (HTTP 200).
Anmeldung mit einem echten Benutzerpasswort nicht getestet. Nur Stage-Dateien
ergaenzt, keine Datenbank-, DEV- oder PROD-Aenderung. Direkter SSH-Aufruf
beendet; kein Tunnel oder Hintergrundprozess.

# Zentrale Sprachfreigabe für Mobile

04.11.2026. Codeänderung ohne Migration oder neue Sprachfreigabe. Maßgeblich bleibt ausschließlich `config/clubunio-languages.php` über `LanguagePreferences::enabled()`; aktuell Deutsch/Englisch. Diese Implementierung ändert weder die zentrale Konfiguration noch gespeicherte Sprachwerte bestehender Benutzer.

## Wirksamer Vertrag

`MobileLanguages` bildet die Schnittmenge der zentral freigegebenen Sprachen mit den fünf implementierten Mobile-Sprachen. Backendcode `pt` wird für Mobile als `pt-PT` ausgegeben und bei explizitem Speichern normalisiert. Nicht implementierte zentrale Codes werden ignoriert; Aliasduplikate entfernt. Eine ungültige oder gesperrte Standardvorgabe wird auf den ersten tatsächlich erlaubten Code aufgelöst. Ohne erlaubte Sprache bleibt `default=null`; es wird keine Freigabe für Deutsch erfunden.

Context, persönliches Profil und gespeicherte App-Präferenzantwort liefern:

```json
{"language":"de","language_policy":{"enabled":["de","en"],"default":"de"},"language_fallback":true}
```

`language_fallback` ist nur dann wahr, wenn ein tatsächlich gespeicherter Code nicht mehr erlaubt ist. Ohne gespeicherte Präferenz wird die erlaubte Standardvorgabe ohne Warnung ausgegeben. GET liest die bisherige Datenbankpräferenz, ändert sie aber nicht – auch nicht bei einem gespeicherten französischen Altwert. Bei leerer Freigabe ist die wirksame `language` null.

ChatPreferences GET/POST und damit die bereits vorhandenen Conversation-DTOs liefern dieselbe `language_policy` plus `language_fallback`. `target_language` ist wirksam erlaubt oder null. War die gespeicherte Zielsprache gesperrt, wird `translation_enabled=false`; die frühere Zustimmung wird niemals stillschweigend für die Ersatzsprache übernommen. Die gespeicherte Revision und der alte Datenbankwert bleiben beim Lesen erhalten. Erst ein expliziter zulässiger Schreibauftrag kann die neue Zielsprache/Zustimmung speichern.

## Frische Schreib- und Providergrenzen

App-Präferenzen, Chat-Präferenzen und alle KI-Sprachoperationen validieren über die aktuelle zentrale `Rule::in`-Menge. Ein Client kann keine der nur technisch implementierten weiteren Sprachen freischalten. Der Alias `pt` wird nur akzeptiert, wenn Portugiesisch tatsächlich zentral freigegeben ist; gespeichert/ausgegeben wird dann `pt-PT`.

Die KI prüft die aktuelle Sprachfreigabe zusätzlich bei jedem bestehenden frischen Quellencheck: vor Cache-/Budgetwahl unter dem Tenantlock, vor Providerdispatch und vor Ausgabe. Chatübersetzung erfordert zusätzlich eine weiterhin gültige ausdrückliche Zustimmung für genau das aktuelle Ziel. Gesperrte/fehlende Ziele ergeben Validierungsfehler vor Provider-/Budgetarbeit; der generell geschlossene KI-Schalter bleibt unverändert maßgeblich.

## Prüfnachweise und Auslieferung

- Bestehende Kommunikationsbaseline: 49 Tests / 768 Assertions grün; deren gezielte französische/portugiesische Fälle aktivieren die fünf Sprachen ausdrücklich nur im isolierten Test-Setup.
- Final lokal: 51 SQLite-Kommunikationsfälle und 6 Policy-Unitfälle zusammen **57 Tests / 817 Assertions**. Enthalten echte zentrale DE/EN-Defaultprüfung, Implementierungsschnittmenge, Portuguese-Alias, leere Freigabe/null, frische Entfreigabe, HTTP-Saves, keine übernommene Chat-Zustimmung und Providerablehnung.
- Echter PostgreSQL-/HTTP-Kernellauf: **1 Test / 3 Assertions**, zusätzlich **28 eigenständige Kernel-/Privatheitsprüfungen** aus `deploy/check-mobile-language-policy.php`. Synthetischer eigener Mandant/Konten/Sitzungen und gespeicherte französische Altwerte, vollständiger Rollback/Cleanup. Kein weiterer PG-Prozess offen.
- Neue Gate direkt ausführbar: `php deploy/check-mobile-language-policy.php --synthetic-rollback`. Funktioniert ebenso bei CLI-Require-Wrappern; Klassen-only-Include ausschließlich über explizite Testkonstante. Keine PHPUnit-Abhängigkeit auf DEV. Prüft die tatsächliche zentrale DE/EN-Freigabe und verändert diese nicht. Nur für den synthetischen Negativnachweis wird der KI-Schalter prozesslokal unter HTTP-/Mail-/Queue-Fakes angehoben und im finally zurückgesetzt; keine externe Zustellung oder Providerarbeit.
- Bestehende `check-mobile-chat-experience.php` prüft die erweiterte Defaultantwort und führt ihre französische Fachprobe mit ausdrücklich prozesslokaler Testfreigabe innerhalb der Rollbackklammer durch. Die originale zentrale Konfiguration wird danach wiederhergestellt; keine Configdateiänderung.

Runtimepaket: neue `app/ClubUnio/Mobile/MobileLanguages.php`, gezielte bestehende `MobileController.php`, `MobileAccountController.php`, `ChatPreferences.php`, `MobileAiService.php`, bestehende Chat-Experience-Gate und neue Language-Policy-Gate. Doku und Tests getrennt. DEV-Bereitstellung, echte Gateausführung, Commit und Sync erfolgen durch die Releasekoordination; keine Schema-/Provider-/Produktionsfreigabe.

# Assistent als Chat

## Tagesuebersicht als Dashboard (23.11.2026)

Sechs kompakte Kennzahlen ersetzen die technischen Kennzahlentabellen:
Anmeldungen in Bearbeitung, Spesen mit ausstehender Freigabe/Zahlung,
offene Rechnungen, offene Bus-/Platzanfragen ueber alle Tage und der
aktive Mitgliederbestand. Mitgliederzahlen sind keine offenen Aufgaben.
Reservierungen am gewaehlten Tag stehen getrennt darunter.

Die Arbeitslisten sind standardmaessig geschlossen. Pro Bereich bleibt
die begrenzte Vorschau mit sicheren Fachlinks, Status und formatiertem
Betrag erhalten. Reservierungslisten enthalten nur den gewaehlten Tag,
nicht saemtliche offenen Anfragen. Technische Zaehler, unbekannte
Ueberfaelligkeit und die Abgrenzung zu Trainings/Spielen/Sperren stehen
unter Datengrundlage. Keine zusaetzlichen Datenbank- oder Provideraufrufe.

Migration: Page, Hauptview, neues Dashboard-Partial, versioniertes CSS und
beide Sprachkataloge gemeinsam bereitstellen; releasebezogenen View-Cache
erneuern. Keine Schema-, Rechte-, KI-Freigabe- oder Datenmigration.
Rueckweg: DEV-Release `20261123-clubunio-tenant-ai`; dessen parallel
bereitgestellte KI-Konfiguration bleibt im Dashboard-Release erhalten.
48 lokale Tests / 726 Assertions, Pint und DE/EN-Katalogpruefung erfolgreich.
DEV-Datenlesetest in zurueckgerollter Transaktion: sechs Bereiche korrekt
projiziert (14 Anmeldungen, 19 Spesen, 162 Rechnungen, 0 Bus-/Platzanfragen,
988 aktive Mitglieder). Kein Provideraufruf oder dauerhaftes Testgespraech.

Browserabnahme mit synthetischen Daten in DE/EN: Desktop mit drei und
Mobilbreite 390 px mit zwei Kennzahlenspalten, kein horizontaler Ueberlauf.
Rechnungsliste aufklappbar, CHF 380.00 statt langer Nachkommastellen,
HTML im Datensatznamen weiterhin escaped. Angemeldete Sichtpruefung auf
DEV noch offen: bestehende Chrome-Sitzung abgelaufen, Neuanmeldung erfragt.
DEV-Release `20261123-clubunio-assistant-daily-dashboard-v2`.
Stage und PROD unveraendert.

## Ursache und Freigabe

Am 23.11.2026 war auf DEV der Assistent freigeschaltet, aber der externe
Provider deaktiviert: `provider_enabled=false`, `privacy_reviewed=false`,
kein Assistentenschluessel. Das war eine bewusste Freigabesperre und kein
OpenAI-Ausfall. Die Tagesuebersicht funktionierte weiterhin lokal.

Externe Freigaben, Projektbindung und Secrets werden nicht durch dieses
UI-Paket veraendert. Parallel entwickelte mandantenbezogene KI-Einstellungen
sind kein Bestandteil dieses Releases. Bei deaktiviertem Provider bleiben
freie Fragen ohne lokale Entsprechung gesperrt; die UI erklaert dies.

## Lokale Abfragen

Tagesuebersicht, offene Rechnungen und offene Reservierungsanfragen verwenden
die vorhandenen rollen- und mandantengeprueften Read-Tools. Die beiden
Beispielfragen sind direkt ausfuehrbar. Dieselben Fragen sowie
`Was steht heute an?` / `What is on today?` koennen auch eingetippt werden.
Es gibt keinen generischen SQL- oder Freitextparser und keinen
automatischen Rueckfall auf einen externen Anbieter.

Lokale Antworten und Fragen werden im bestehenden verschluesselten,
kontenbezogenen Verlauf gespeichert. Keine Providerkosten, Finanzaktionen,
Rechteerweiterungen oder Nachrichten an Vereinsmitglieder.

## Oberflaeche

Getrennte Nachrichten fuer Benutzer und Assistent, Antwortstatus waehrend
des Sendens, Scrollen zur neuesten Antwort, kompakter Composer mit
Enter/Shift+Enter und ausklappbare Ergebnisdetails. Fehlermeldungen erhalten
den eingegebenen Text. Die bestehende zweistufige Request-ID fuer bezahlte
Aufrufe und die expliziten Aktionsfreigaben bleiben erhalten.

DE/EN: `lang/{de,en}/assistant_chat.php`. Keine Schemaaenderung.
Migration: nur Page, Service-Delta, Blade, CSS und beide Sprachdateien
gemeinsam ausrollen; View-Cache releasebezogen erneuern. Rollback auf den
vorherigen Release behaelt bereits gespeicherte Gespraeche.

## Abnahme

`BackofficeAssistantPageTest` und `AssistantLocalQueriesTest` pruefen unter
anderem Provider-aus, lokale Abfragen trotz KI-Kostenlimit, unbekannte
Abfragen, verschluesselten Verlauf, Rechtewechsel und bezahlte Wiederholungen.
Tests verwenden synthetische Daten und verhindern echte Provideraufrufe.

Auf DEV lokal mit der bestehenden Vereinsdatenbank in einer zurueckgerollten
Transaktion geprueft: alle drei Abfragen, sechs verschluesselte Nachrichten,
keine Provider-Run- oder Fachmutation. Im angemeldeten Chrome-Browser:
Reservierungsbutton, Tagesfrage per Enter, Antwortstatus und Verlauf
erfolgreich; Mobilbreite 390 px ohne horizontalen Seitenueberlauf.
Externe KI blieb deaktiviert. Stage und PROD unveraendert.

DEV-Release: `20261123-clubunio-assistant-chat-v2`, funktionaler Rueckweg
`20261123-clubunio-support-workspace`. Eigener Paketbranch:
`codex/assistant-chat-ui`, um parallele KI-Konfigurationsarbeiten nicht
mitzunehmen. 46 lokale Tests / 689 Assertions und DE/EN-Check erfolgreich.

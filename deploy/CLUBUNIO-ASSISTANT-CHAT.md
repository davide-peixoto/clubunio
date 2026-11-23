# Assistent als Chat

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

# Materialrückgabe V1

Unter **Verein → Materialrückgabe** zeigt ClubUnio die Positionen ausgestellter und bestätigter Materialquittungen. Die Liste startet mit offenen Positionen. Sie lässt sich nach Mitglied und überfälligem Solltermin filtern. Über die abgeschlossenen Positionen bleibt der Rückgabeverlauf erreichbar.

Unter **Rückgaben und Solltermin** können berechtigte Mitarbeitende pro Position ein Soll-Datum setzen und Teilrückgaben mit Datum, Menge und Zustand erfassen. Das erfassende Konto wird mit jeder Buchung gespeichert. Die Buchung sperrt die Quittung während der Mengenprüfung. Ein wiederholter identischer Aufruf derselben Buchung erhöht die Rückgabemenge nur einmal. Die Quittung und ihr Empfangsnachweis bleiben als eigene Dokumente erhalten.

Die additive Migration `2026_11_16_003030_material_returns.php` legt zwei Tabellen für Solltermine und Rückgabebuchungen an. Sie muss vor Aktivierung der neuen Seite ausgeführt werden. Beim Rückweg zum vorherigen Web-Release bleiben die Tabellen mit den erfassten Rückgaben erhalten.

Geprüft mit einer synthetischen Quittung über fünf Stück in einer zurückgerollten PostgreSQL-Transaktion. Zwei intakte und eine beschädigte Rückgabe ergaben zwei offene Stück. Erneutes Senden derselben Buchung, Überbuchung, Mitgliedsfilter, Überfälligkeit und vollständige Rückgabe wurden geprüft. Quittungshash und die drei Rückgabebuchungen blieben nachvollziehbar.

Auf DEV aktiv als `20261116-clubunio-material-returns`. Rückweg ist `20261116-clubunio-remove-local-mfa`. Vor der Migration wurde die DEV-Schemasicherung geprüft. Auf dem aktiven Release wurden die Livewire-Seite und ihre Buchungsaktionen im authentifizierten Anwendungskontext mit echten DEV-Tabellen geprüft. Die Testtransaktion wurde zurückgerollt. Die Anmeldung und Bedienung über einen Browser mit Keycloak stehen aus.

## Stage-Vorbereitung

Die einzige neue Migration ist `2026_11_16_003030_material_returns`.
Sie ergänzt `clubunio.material_return_deadlines` und `clubunio.material_returns`.
Stage besitzt die referenzierten Tabellen und Schlüssel. Die beiden
Zieltabellen und der Journaleintrag fehlten bei der lesenden Vorprüfung.

Vor dem freigegebenen Webrollout Schema sichern und
`php deploy/apply-stage-material-returns.php` aus dem vorbereiteten
Release ausführen. Der Runner prüft Stage-URL und Datenbank und führt
nur diese Migration aus. Danach Journaleintrag und Tabellen prüfen.
Beim Rückweg den vorherigen Webcode wiederherstellen und die
Rückgabedaten erhalten.

Isolierte Abnahme mit dem Stage-Schema in PostgreSQL 16 und zwei
synthetischen Vereinen: Migration und Journal, Solltermin, Überfälligkeit,
Teilrückgaben 2 + 1 + 2, Wiederholungsschutz, Mengenlimit, Verlauf,
unveränderter Quittungshash und Mandantentrennung bestanden.

## Stage-Rollout (17.11.2026)

RunCloud lieferte den Funktionscommit `29f6a2d` aus. Die gezielte
Materialmigration wurde nach Schemasicherung als Batch 128 verbucht.
Routen und Blade-Views wurden neu gecacht. Auf Stage bestanden die
Materialprüfung mit zwei synthetischen Vereinen und die Trainerprüfung
mit einem synthetischen Trainerkonto. Die Trainerprüfung im HTTP-Kernel
bestätigte vier Seiten in DE/EN, die aktuelle Mannschaft, HTTP 404 für
den historischen Direktlink und den Kalenderexport. Die Rückgabeprüfung
bestätigte 2 + 1 + 2 Stück, Solltermin, Wiederholungsschutz, Verlauf und
Mandantentrennung. Beide Transaktionen wurden zurückgerollt.

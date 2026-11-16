# Materialrückgabe V1

Unter **Verein → Materialrückgabe** zeigt ClubUnio die Positionen ausgestellter und bestätigter Materialquittungen. Die Liste startet mit offenen Positionen. Sie lässt sich nach Mitglied und überfälligem Solltermin filtern. Über die abgeschlossenen Positionen bleibt der Rückgabeverlauf erreichbar.

Unter **Rückgaben und Solltermin** können berechtigte Mitarbeitende pro Position ein Soll-Datum setzen und Teilrückgaben mit Datum, Menge und Zustand erfassen. Das erfassende Konto wird mit jeder Buchung gespeichert. Die Buchung sperrt die Quittung während der Mengenprüfung. Ein wiederholter identischer Aufruf derselben Buchung erhöht die Rückgabemenge nur einmal. Die Quittung und ihr Empfangsnachweis bleiben als eigene Dokumente erhalten.

Die additive Migration `2026_11_16_003030_material_returns.php` legt zwei Tabellen für Solltermine und Rückgabebuchungen an. Sie muss vor Aktivierung der neuen Seite ausgeführt werden. Beim Rückweg zum vorherigen Web-Release bleiben die Tabellen mit den erfassten Rückgaben erhalten.

Geprüft mit einer synthetischen Quittung über fünf Stück in einer zurückgerollten PostgreSQL-Transaktion. Zwei intakte und eine beschädigte Rückgabe ergaben zwei offene Stück. Erneutes Senden derselben Buchung, Überbuchung, Mitgliedsfilter, Überfälligkeit und vollständige Rückgabe wurden geprüft. Quittungshash und die drei Rückgabebuchungen blieben nachvollziehbar. Die angemeldete Webabnahme folgt nach dem DEV-Release.

# Persönlicher OCR-Betragsnachweis

Stand:04.11.2026. Quellpaket vorbereitet, defaultgeschlossen. Keine900-Anwendung.

Das Feld AiBetragUebernommen stammt ausschliesslich aus einem tatsächlich
erfolgreichen persönlichen Beleg-OCR-Auftrag. Die vorhandenen Felder
suggested_amount/suggestion_accepted beschreiben Regel-/Pauschalvorschläge und
werden unverändert weitergeführt; sie sind kein Beweis für einen KI-Betrag.
Ohne bestätigte OCR-Quelle bleibt der Marker null. Ein manuell abweichender
Betrag ergibt false, ein unverändert übernommener bestätigter Betrag true.
Der Marker prüft zusätzlich die fortbestehende Zuordnung des echten gebundenen
Draft-/Upload-SHA. Bei veröffentlichten Dateien muss die genaue Referenz noch
an der Position hängen. Eine extern ersetzte Datei erbt kein veraltetes true.

MobileExpenseOcr hält bei erfolgreicher Dokumentanalyse Draft-ID/-Revision,
Datei-SHA, Scanversion/-zeit im bestehenden verschlüsselten Ergebnis. Ein alter
Ergebnisdatensatz ohne diese Quellenmetadaten wird nicht nachträglich zertifiziert.
Der Client erhält bei ausdrücklich aktivierter Provenienz nur Work-ID, Draft-ID,
Revision und SHA als Herkunftsverweis; private Inhalte bleiben serverseitig.
Der echte OCR-Vorschlagsbetrag wird beim Speichern erneut aus dem erfolgreichen,
eigenen, noch gültigen Work-/Draftstand gelesen. Fremder OCR-Auftrag, falscher Draft,
abweichende Revision, andere Scanquelle oder erfundener Vorschlagsbetrag werden
vor Positionsschreibzugriff abgewiesen. Bestehende Session-/Tenant-/Versionsrechte
und atomare Draft-/Positionsbindung bleiben vorgeschaltet.

Migration2026_11_04_000900_mobile_expense_ocr_provenance.php ergänzt eine private
History pro Tenant/Position/Positionsrevision. Fremdschlüssel binden Position,
Draft und echte Tenant-/Actor-/Workkombination; dafür wird auf mobile_ai_work
ausschliesslich ein zusätzlicher UNIQUE(tenant_id,account_id,id) angelegt.
Keine bestehende KI-Zeile wird geändert. Gespeichert werden nur Herkunfts-IDs,
SHA, Quellrevision, Vorschlags-/Positionsbetrag, Übernahmeentscheidung und Autor.
Keine Dateien, Extraktionstexte oder Providerzugangsdaten werden dupliziert.
Die History überlebt die reguläre Entfernung privater OCR-Ergebnisse nach24h.
Geänderte neue Belegquelle ohne neuen bestätigten OCR-Auftrag verwirft die
bisherige Übernahmeentscheidung, erhält aber ihren historischen Nachweis.

Aktivierung erfolgt nur über clubunio-expense-provenance.enabled=false→true nach
geprüfter einzelner900-Anwendung. Defaultfalse liest/schreibt keine neue Tabelle;
laufende Spesenfunktionen bleiben ohne900 nutzbar. Vor Anwendung sind betroffene
Spalten/Indizes und unveränderte mobile_ai_work-Zeilen mit gesichertem Digest und
Backup zu prüfen. Kein Verzeichnis-Migrationslauf; kein automatisches down().
Wiederherstellung bedarf geplanter Prüfung, History und bisherige Regeln erhalten.

Der originale Belegeditor und aiSuggestedAmount-Parameter bleiben erhalten.
Der persönliche Adapter merkt nur tatsächlich empfangene OCR-Herkunftsverweise,
aktiviert sie ausschliesslich nach dem ausdrücklichen Übernehmen im bestehenden
Reviewdialog, übermittelt sie für den passenden Beleg und bewahrt sie bei unklarem Speichern
zusammen mit CallerUUID. Nach Bestätigung oder Kontokontextwechsel wird der
temporäre Nachweis verworfen. Regelvorschläge ohne OCR-Beleg erhalten keinen
erfundenen Work-Verweis. Auch ein abgebrochener Review und ein zufällig gleich
hoher Regelbetrag aktivieren keinen Herkunftsverweis. HEIC-Normalisierung, Mehrfachbelege und die bestehende
Sitzungsbindung bleiben unverändert.

Bisheriger Nachweis:3 DB-freie Boundaryfälle/16 Assertions und5 neue Flutterfälle
grün; bestehende persönliche Spesen-/OCR-/Mehrfachbelegfälle15 grün. PostgreSQL:
bestehende und neue Controllerfälle zusammen6/194 grün,900 ausschliesslich TEMP
mit synthetischen Eltern/FKs und Transaktionsrollback. Azure-Ergebnis über
Http::fake, niemals bezahlte Provideraufrufe. Die synthetische Veröffentlichung
eines Uploadrecords dient nur der Positions-/Herkunftsregression und beweist
keine reale Blob-/Scanner-/Azureabnahme. Vollständige OCR-Regression9/95 grün.
Abschliessender gezielter Controllernachlauf2/52 grün: echte fortbestehende
Uploadreferenz, externe Dateiersetzung und fremder Draft404. Flutter gesamt20
grün, gezielte Analyse ohne Befund.900 ist weiterhin nicht angewendet; kein
Runtime-/Providerrelease behauptet. Root übernimmt Commit/Sync und eine etwaige
gesonderte DEV-Freigabe nach Backup-/Metadaten-/Digestprüfung.

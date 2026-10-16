# ClubUnio: Spesen, Anmeldungen und Dokumentzugriffe

16.10.2026. Nur ClubUnio; PHPMaker DEV/PROD bleiben unveraendert.

## Befund und Anpassung

Die Anmeldeliste lud den Mandanten pro Zeile erneut. RegistrationResource
laedt die tenant-Relation jetzt gesammelt, ohne bestehende Filter oder Policies
zu ersetzen. Keine Ergebnis- oder Berechtigungsablage ueber Requests.

Die Positionsbuttons eines Spesenbelegs fragten Beleg und Status neunmal ab.
Nur innerhalb des bestehenden GET-/Livewire-Renderkontexts wird das Ergebnis
der Button-Sichtbarkeit pro Tabelleninstanz wiederverwendet. Ausserhalb des
Lese-Kontexts wird frisch geprueft. Alle Fachaktionen behalten ihre frischen
Sichtbarkeits-, Status-, Revisions- und Schreibpruefungen. Keine Positionsdaten
oder Rollen werden global gecacht.

Anmeldungsdokumente laden ihre iframe-Vorschau erst beim Klick auf das Auge.
Schliessen entfernt die Vorschau wieder; Downloads bleiben direkt erreichbar.
Damit werden nicht mehr alle Anhaenge gleichzeitig von Azure geladen.
Mitgliederdokumente hatten bereits eine bedarfsgesteuerte Einzelvorschau.

AzureDocumentReader unveraendert: ein begrenzter Abruf, keine Retries oder
Redirects, private/no-store, kein oeffentlicher SAS-Link. Fehler behalten
sanitisierte Korrelationsreferenzen. Kein Dokumentcache oder Fallback,
kein echter Testupload und keine Abschwaechung von Berechtigungen.

## Ausgangsmessung auf dem Pi

Lesendes CLI-Profil mit eigener ClubUnio-DB, READ ONLY und Rollback. Ausgabe
nur Status, Dauer, Speicher und aggregierte Query-Fingerprints; keine Bindings,
Dateiinhalte, Namen oder Secrets. Server-Lifecycle ohne Browser/Proxy,
Einzelstichproben, kein Lasttest und keine SLA.

| Ansicht | ms vorher | Queries vorher |
| --- | ---: | ---: |
| Spesenliste | 922 | 22 |
| Spesendetail | 737 | 21 |
| Spesenpositionen (separate Livewire-Ladephase) | 767 | 26 |
| Anmeldeliste | 1628 | 45 |
| Mitgliederdokument inklusive Azure | 554 | 5 |
| Spesenbeleg inklusive Azure | 457 | 5 |
| Anmeldungsdokument inklusive Azure | 554 | 5 |

Dokumentstichproben alle HTTP200; vorhandene aktive Dokumente, keine Inhalte
ausgegeben. Messungen gegen alte URLs waren nur HTTP302 und sind keine
Azure-Laufzeitmessung. Profiler verwendet jetzt kanonische URLs.

## Migration und Ruecknahme

Keine Tabellen-, Index- oder Datenmigration. Bestehende Dokumentpfade,
Azure-Konfiguration, Rollen und Mitglieder-/Teamzuordnungen unveraendert.
Beim spaeteren Import diese Zuordnungen und Dokumentmetadaten erhalten.
Ruecknahme durch vorherigen Release-Symlink und graceful PHP-FPM reload;
kein Schema-Rollback notwendig. API/SaaS/Betrieb nicht erweitert.

## Bereitgestellt und nachgemessen

Release `20261016-clubunio-expense-document-performance`, Basis/Ruecksprung
`20261016-clubunio-db-read-performance`. Ausschliesslich eigenes Delta;
parallele Dossier-Erweiterungen nicht pauschal uebernommen.

| Pi-Stichprobe | ms vorher/nachher | Queries vorher/nachher |
| --- | ---: | ---: |
| Anmeldeliste | 1628 / 937 | 45 / 21 |
| Spesenpositionen | 767 / 336 | 26 / 10 |
| Spesenliste (unveraenderte Abfragezahl) | 922 / 873 | 22 / 22 |
| Anmeldungsdokument inklusive Azure | 554 / 564 | 5 / 5 |

Die reine Dokumentlaufzeit wurde bewusst nicht als verbessert ausgewiesen:
Netzwerk/Azure bleibt massgeblich; vermieden werden unnoetige gleichzeitige
Vorschauen. Konfiguration, Routen und Views neu gecacht; Navigations-/Workspace-
Guards, verschluesselte Einstellungen und Vorlagen auf Pi verifiziert.
Login und Health HTTP200. Sichtpruefung zunaechst durch abgelaufene Browser-
Anmeldung blockiert; bedarfsgesteuertes Template automatisiert geprueft.

Tests: Auswahl von 49 Tests (Anmeldungen, Spesen, Reader, Readkontext und
Mitgliederdokumente). Ein neuer Test musste den Filament-internen Action-
Sichtbarkeitscache explizit leeren, um unseren Callback erneut auszufuehren;
anschliessend Spesensuite erneut vollstaendig erfolgreich: 10 Tests,
250 Assertions. Die uebrigen ausgewaehlten Tests ohne Fehler, optionaler
echter Azure-Uploadtest ohne Opt-in uebersprungen. Insgesamt 48 unterschiedliche
Tests erfolgreich, ein optionaler Test uebersprungen. Keine echten Uploads,
E-Mails, Buchungen oder Bankzahlungen. Runtime-Hashes lokal/Pi identisch.

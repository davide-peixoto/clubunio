# Vereins-KI: Konfiguration und Migrationsplan

Stand23.11.2026. Auf ClubUnio DEV, Stage und PROD bereitgestellt.

## Einrichtung

Vereinsadmins: Einstellungen > Zugaenge & System > KI-Einstellungen (`/ai-settings`).
Projekt-ID/Key speichern, Verbindung pruefen, Datenschutz bestaetigen, danach
gezielt aktivieren. Leeres Schluesselfeld erhaelt den Wert; vorhandene Keys werden
nie an Browser/App ausgegeben. Konfigurationsrevision und aktiver Verein werden
auch bei Livewire-Aktionen erneut geprueft.

| Modus | Verantwortlich | Abrechnung |
| --- | --- | --- |
| own | Vereinsadmin, eigener OpenAI-Projektzugang | Direkt durch OpenAI an den Verein |
| clubunio | Plattformverwaltung > Verein > KI-Einstellungen | Dediziertes Vereinsprojekt; ClubUnio bezahlt |

Plattformadmins koennen verwaltete Zugaenge ohne Vereinsrolle bereitstellen;
das erteilt keinen Zugriff auf Vereinsdokumente. Vereinsadmins duerfen verwaltete
Keys/Projekte nicht ersetzen und deren Budget nicht erhoehen. Keine automatische
OpenAI-Projektanlage, SaaS-Faktura oder echte Zahlung.

Projekt und Key-Fingerprint sind pro Instanz eindeutig; getrennte Projekte pro
Umgebung verwenden. Keys werden Laravel-verschluesselt gespeichert. Kein globaler
Fallback, Keycache oder Kopieren von DEV-Zugaengen nach Stage/PROD.

## KI-Aufrufe und Kosten

Training nutzt Terra und eigene freigegebene Trainings-Stores. App-Textfunktionen
nutzen Luna, Backoffice Terra. Bestehende globale Funktions-/Datenschutzgates,
geschlossene Tools, Berechtigungen und Pflichtbestaetigungen bleiben erhalten.
Plattforminterner Support bleibt bei globalem Support-Key und oeffentlichen FAQs;
keine Vereinsdokumente. Azure-/Translator-Adapter werden nicht umgestellt.

TenantAiGateway bindet feste API-Ziele, Projekt, Modell und Store-IDs an den Verein.
Monatsbudget wird unter Vereinssperre reserviert; Revision und aktuelles Limit
nach dem Warten erneut lesen. Nach Konfigurationswechseln keine alten Ergebnisse
ausliefern. Unsichere bezahlte Calls bleiben attention mit Reserve, ohne Retry.

Journal: Modus, Projekt, Modell, Tokens, Suchaufrufe und geschaetzte Mikro-USD.
Standardraten ohne angenommenen Cache-Rabatt, Reasoning bereits in Output enthalten.
Suche wird mit2500 Mikro-USD je Aufruf kalkuliert. Das Modell-/Suchbudget ist kein
Limit fuer laufende Speicherkosten. Stores zeigen zuletzt abgefragte usage_bytes;
vor Weiterverrechnung muss der offizielle Anbieter-Kostenabgleich erfolgen.
Keine automatisch erzeugte Rechnung oder behauptete exakte Anbieterabrechnung.

## Wissensspeicher

Neue oder eigene bestehende Stores fuer training/club. Bestehende IDs werden mit
dem eigenen Projekt geprueft; fremde Bindungen/ClubUnio-Metadaten werden abgewiesen.
Nach Remotevalidierung unter Vereinssperre die Konfigurationsrevision revalidieren.
Projekt-/Moduswechsel nur ohne nicht archivierte Stores. Dateien muessen bei einem
Projektwechsel explizit neu indexiert werden, nicht still auf neue Keys umhaengen.

PDF/TXT/MD/DOCX: Backend maximal20 MiB, Browser derzeit12 MiB. Original zuerst ueber
TenantDocumentStorage im privaten Vereinsspeicher sichern, dann OpenAI-Upload und
Indexierung. UUID-Dateinamen und Namespace ai bei den bestehenden Speicheradaptern;
keine neuen Dateitypen fuer Mitglieder-/Spesen-Namespaces. Kein lokaler Notfallback.
Das private Speicherziel muss eingerichtet sein.

Original, Hash, Store, Providerdatei und Indexstatus bleiben nachvollziehbar.
Ersetzen: neue Datei hochladen, pruefen, alte separat entfernen. Entfernen setzt
zuerst removing und erhoeht die Profilrevision. Suche sperren und laufende alte
Ergebnisse verwerfen; erst nach Remote-Detach archivieren. Nach unklarem DELETE
darf Aktualisieren nur erneut abhaengen, nicht freigeben. Kein globales File-Delete;
private Originale bleiben berechtigt herunterladbar. Unklare Erstellung/Uploads
bleiben attention, statt bezahlt blind wiederholt zu werden.

Upload und Entfernen koordinieren Profilrevision und Dokument-Zeilensperre.
Spaete Statusantworten duerfen removing/archived nicht ueberschreiben. Ein unklarer
Remote-Attach quarantiniert den ganzen Store; Entfernen eines Dokuments gibt diesen
nicht automatisch wieder frei. Suche nutzt nur Stores mit freigegebenen Dokumenten.

## Migration

Additiv: `2026_11_23_000200_tenant_ai.php`, fuenf Tabellen mit tenant_ai_-Praefix:
connections, stores, documents, usage und events. Mandantenbezogene Fremdschluessel.
Keine Aenderung an Fachtabellen, Konten, Rollen oder bestehenden Provider-Zugaengen.

1. Aktive Instanzbasis und CLI-PDO-PgSQL pruefen. SSH direkt von Windows, danach
   schliessen. DEV-App bleibt auf dem Pi, DEV-Datenbank auf dem VPS.
2. `emit-tenant-ai-migration.php <database>` erzeugt ausschliesslich Ziel-SQL.
   `migrate-tenant-ai.sh` erstellt privates pg_dump-Fc-Backup und prueft pg_restore-list;
   Zielguard, Transaktion, Ledger und nur die benoetigten neuen Tabellen-/Sequenzrechte.
3. `release-tenant-ai.sh` setzt das gepruefte Delta auf die aktive Basis. Source,
   Cache und private Umgebung sichern; fremde Aenderungen erhalten. Keine Vollmigration
   anderer Pakete. Auf dem Pi vor Freigabe aktuellen Symlink nochmals vergleichen.
4. `prepare-tenant-ai-config.php` persistiert CLUBUNIO_TENANT_AI_ENABLED=true und
   erhaelt alle anderen Gates. Routen/Views unter korrektem Instanzbenutzer neu bauen.
5. `adopt-tenant-ai-legacy.php <database>` uebernimmt nur vorhandene Vereins-Keys in
   inaktive own-Profile. Keine globalen Keys kopieren, keine Projekte raten, keine
   ungeprueften Stores uebernehmen. Alte Parameter bleiben erhalten, sind aber in
   der neuen Betriebsart unbenutzt und im generischen Editor gesperrt/ausgeblendet.
6. `check-tenant-ai.php`: isolierte synthetische Vereine/Konten, HTTP-Fakes, echter
   privater temporaerer Originals-Speicher. Alle DB-Aenderungen zurueckrollen,
   Dateien entfernen. Neue Projekte/Keys und Storezuordnung danach einzeln pruefen.
7. Keine Aktivierung durch Migration. Datenschutz-/Vereinsfreigabe erst nach Pruefung.

## Abnahme23.11.2026

- Lokal133 gezielte Tests/1296 Assertions; davon25 neue Tenant-AI-Tests/112 Assertions.
  Beide Modi, Verschluesselung, fremde IDs, fehlende globale Fallbacks, Gate-Erhalt,
  Budget, deterministische Projekt-/Budgetwechsel, Fehler/Timeout/Entfernung bestanden.
- DE/EN:3075 JSON-Schluessel plus PHP-Gruppenschluessel/Platzhalter geprueft.
- DEV, Stage und PROD: je21 PostgreSQL-Fachpruefungen mit echten Constraints,
  privatem TXT-Original-Roundtrip, simuliertem Indexieren/Abhaengen, Gesamtrollback.
  Keine echten Nachrichten, externen Dokumente oder Providerkosten.
- DEV aktiv20261123-clubunio-tenant-ai; vorher20261123-clubunio-assistant-chat-v2.
  Schema-/Account-/Push-Outbox-Lesecheck gruen. Vorherige parallele Releases erhalten.
- Stage/PROD gezieltes Delta. Private Releasebackups stage-20261123-212105 und
  prod-20261123-213430 unter storage/app/private/tenant-ai-releases.
- DB-Backups unter /root/clubunio-backups: clubunio_dev-before-tenant-ai-20261123-211339.dump,
  clubunio_stage-before-tenant-ai-20261123-211341.dump und
  clubunio_prod-before-tenant-ai-20261123-213427.dump; jeweils Lesbarkeit geprueft.
- DEV angemeldet auf Desktop und390 Pixel geprueft, einschliesslich Dialog ohne
  bestehenden Klartext-Key. Mobiler Seitenueberlauf behoben. Am Ende bestehende
  Sitzung abgelaufen (401/OIDC-Anmeldung); keine Session-/MFA-Grenze umgangen.
- Stage-Geschaeftsstelle korrekt403. Auf ausdruecklichen Benutzerauftrag Konto
  davide.peixoto@svsissach.ch ausschliesslich bei SV Sissach (Mandant1) von office
  auf admin gesetzt, ueber den auditierten Plattformdienst. Mandant3 und DEV-/PROD-
  Rollen unveraendert. Anschliessend positive Stage-Sichtpruefung auf Desktop und
  390 Pixel: Einstellungen und Konfigurationsdialog erreichbar, Keyfeld leer,
  keine Aktivierung oder Speicherung; Dialog abgebrochen, Viewport zurueckgesetzt.
- CSS auf allen drei Instanzen200, anonymer PROD-Maskenaufruf302 zur Anmeldung.
- Legacyprofile uebernommen: DEV1, Stage2, PROD1. Alle deaktiviert; Stage/PROD ohne
  nutzbare Projektzugangsdaten. Kein Kopieren von DEV-Secrets.

Offen: echte Projekt-/Key-/Retrievalabnahme mit synthetischen Dateien pro Verbindung,
Anbieter-Kostenabgleich, Mehrprozess-Lasttest und Plattformmasken-Sichtabnahme.
Fakes ersetzen diese Nachweise nicht. Keine vollstaendige Sprach-/Trainingsqualitaet
oder Datenschutz-/Aufbewahrungsfreigabe aus technischen Tests ableiten.

## Rueckweg

Neue Vereins-KI/Uploads sperren; unklare Calls und Reserven erhalten. Source, Cache
und Umgebung nur gemeinsam auf gesicherte passende Basis zuruecknehmen. Additive
Tabellen, verschluesselte Keys, Journal und Originale behalten, kein destruktives down().
Alte globale Vereins-KI nicht ungeprueft wieder aktivieren. Externe Dateien/Usage
werden durch DB-Rollback nicht rueckgaengig gemacht; im urspruenglichen Projekt klaeren.
Keine fremden Stores oder globalen Dateien loeschen. Plattform-Support bleibt separat.

Quellen: [Vector Stores](https://developers.openai.com/api/reference/resources/vector_stores/methods/create),
[Storedateien](https://developers.openai.com/api/reference/resources/vector_stores/subresources/files/methods/create),
[Files](https://developers.openai.com/api/reference/resources/files/methods/create),
[API-Schluessel](https://developers.openai.com/api/docs/guides/api-keys).

# Kommunikation: Folgepaket Chatkontrolle und private Dateien

Stand02.11.2026. Lokal implementiert, nicht auf dem Server aktiviert.
Fuehrt P2 weiter, ergaenzt den Dateiteil von P3 und die Sprachpraeferenz aus P5.
P3 Push/Echtzeit und P6 Geraeteabnahme bleiben offen.

## Nachrichten, Moderation und Wiederaufnahme

Eigene Textnachrichten/Ankuendigungen koennen30 Minuten bearbeitet werden,
Ankuendigungen weiterhin nur durch aktuell berechtigte Admins. Umfragen und
Terminkarten werden nicht inhaltlich umgeschrieben. Erwartete revision ist
Pflicht; parallele/veraltete Aenderung ergibt409. Eigene Nachrichten koennen
entfernt werden; zustaendige Trainer/technische Leiter und Admins duerfen in
ihrer aktuell berechtigten Gruppe moderierend entfernen. Keine private
Mitlese- oder Loeschberechtigung fuer unbeteiligte Admins.

Loeschen ueberschreibt den verschluesselten Nachrichteninhalt mit Leertext,
erhoeht Revision und belaesst einen sichtbaren Platzhalter. Zugehoerige
Anhangbytes und gespeicherte Uebersetzungsergebnisse werden entfernt; bei
Umfragen auch Frage/Optionen/Stimmen. Aenderungsjournal enthaelt nur IDs,
handelndes Konto, Aktion, Revision und Zeitpunkt, keine alten Textkopien.
Backups/offline zugestellte Daten sind dadurch nicht rueckwirkend geloescht.

Aenderungscursor erfasst Senden/Bearbeiten/Loeschen/Abstimmen. Die App liest
50er-Seiten bis zum Ende, auch fuer aeltere bereits geladene Nachrichten.
Erster Verlaufsabruf liefert change_cursor; aktuelle Revisionen werden nie
mit aelteren Antworten ueberschrieben. Live-WebSocket ist weiterhin offen.

Gruppennachrichten melden: Begründung verschluesselt, eine Meldung je Konto/
Nachricht. Nur aktuell berechtigter Gruppenmoderator sieht Begründung und
aktuelle Nachricht nach seiner eigenen Eintrittsgrenze, kann entfernen oder
abschliessen. Abschluss entfernt die Begründung, behaelt Bearbeitungsmetadaten.
Kein pauschaler privater Moderationszugriff. Melden/Pruefen privater Direktchats
ist daher noch gesperrt; deren eigene Loeschung und Kontaktsperre sind moeglich.

Direktkontakt blockieren sperrt Nachrichten in beiden Richtungen und entfernt
den Kontakt aus erlaubten Direktkontakten. Teamchats bleiben erreichbar.
Aufheben nur fuer die eigene Sperre im Profil; Gegenueber-Sperre bleibt wirksam.
Chat-Stummschaltung hat nun eine Oberflaeche; Push ist noch nicht implementiert.

Admin-Ankuendigungen zeigen vor Versand die aktuelle namentliche Empfaengerliste
und Anzahl. Fingerprint der IDs ist im Senderequest verpflichtend. Unter der
Vereinssperre erneut geprueft; Aenderung ergibt409 und erfordert neue Vorschau.
Gleiche UUID/Inhalt nach verlorener Antwort liefert weiter genau eine Meldung.
Posteingang besitzt einen berechtigten, idempotenten Gelesen-Endpunkt.

## Private Bilder und PDFs

Ein Bild/PDF pro Nachricht, maximal5 MiB (5242880 Bytes). PDF/JPEG/PNG/WebP;
kein SVG/HTML/Archiv. Serverseitig reale MIME-Erkennung und Bildabmessungen,
maximal10000px je Seite und25 Megapixel. PDF-Signatur/Endmarker erforderlich;
dies ersetzt den obligatorischen Virenscan nicht.

Multipart-Upload mit eigener Client-UUID -> verschluesselte Quarantaene in
ClubUnio-SQL -> expliziter Scan -> clean -> Nachricht bindet die Datei einmalig.
Gleiche Upload-UUID mit anderen Bytes/Chat ergibt409. Die Nachricht hat eine
eigene stabile UUID; verlorene Antworten erzeugen weder Datei- noch Nachrichtendubletten.
Solange kein vollstaendiger sauberer Scan vorliegt, weder Versand noch Download.
Abgewiesen/cancelled/entfernt entfernt die gespeicherten Bytes; fehlgeschlagene
Pruefung bleibt in Quarantaene und kann bewusst erneut angestossen werden.

ClamAV-Adapter nutzt ausschliesslich konfigurierten lokalen Unix-Socket
`CLUBUNIO_CLAMD_SOCKET`, z.B. /run/clamav/clamd.ctl, mit INSTREAM und
begrenzten Fristen. Keine oeffentliche TCP-Schnittstelle und kein Aufruf einer
Cloudpruefung. Nur exaktes `stream: OK` gibt frei; FOUND verwirft Bytes,
Timeout/ERROR/unklare Antwort sperrt. scanner_version speichert aktuell die
Adapterprotokollversion, nicht die Signaturdatenbankversion; deren Aktualitaet
muss der Scannerbetrieb vor Aktivierung sicherstellen. Protokoll:
https://docs.clamav.net/manual/Usage/ClamdProtocol.html

Scan-I/O ausserhalb SQL-Transaktion; vor Freigabe Sitzung und Chatrechte frisch
unter Sperre pruefen. Besitz der Upload-ID erteilt keine Berechtigung.
Download verlangt veroeffentlichte, saubere Datei und aktuelle Nachrichtensicht
inklusive Eintrittsgrenze. Rueckgabe private/no-store, nosniff, sandbox und feste
Content-Disposition; keine oeffentliche Azure-URL. App prueft SHA256, Laenge und
MIME und zeigt PDF/Bild innerhalb der App. Kein dauerhafter Dateicache.

Startgrenze je Verein100 gehaltene Dateien /100 MiB. Dies ist ein begrenzter
Entwicklungs-Zwischenspeicher, kein skalierter Dokumentenbetrieb. Vor groesserem
Pilot Azure-/Objektablage, Speicherquoten, Aufraeumen/Retention und Last pruefen.
Keine automatische Loeschung verwaister Quarantaene; manuelles Verwerfen ist
vorhanden. Noch kein fortsetzbarer Teilupload; Wiederholung ueber Client-UUID.
Uploads bleiben standardmaessig false; ohne Scanner nie aktivieren.

## Sprache und KI

Sprachwahl DE/EN/FR/IT/PT-PT wird kontobezogen auf dem Server gespeichert und
nach Login/Neustart ueber context geladen. Menues bleiben kuratierte Texte;
noch nicht alle Sekundaerdialoge sind uebersetzt. Die App behaelt KI-Request-IDs
bei Wiederholung desselben Entwurfs/Nachricht/Revision/Sprache in ihrer Laufzeit,
statt nach Timeout blind einen neuen bezahlten Auftrag zu erzeugen. Edit/Delete
invalidiert abgeleitete Ergebnisse; eine waehrend Provideraufruf geaenderte
Nachricht erzeugt kein veraltetes Uebersetzungsergebnis. Kein automatisches Senden.

## Neue API-Endpunkte

Basis /api/mobile/v1; bestehende persoenliche Auth-/Domain-/Vereinsregeln gelten.

| Methode/Pfad | Daten |
| --- | --- |
| POST preferences | language; eigenes Konto |
| GET clubs/{t}/chats/{c}/changes | after; data(cursor,message), meta(cursor,has_more) |
| POST clubs/{t}/messages/{m}/edit | revision, text |
| POST clubs/{t}/messages/{m}/delete | revision |
| POST clubs/{t}/messages/{m}/report | reason; nur Gruppen |
| GET clubs/{t}/chats/{c}/reports | offene Meldungen im eigenen Moderationsbereich |
| POST clubs/{t}/reports/{r}/resolve | Meldung abschliessen |
| GET clubs/{t}/announcements/preview | recipients, count, fingerprint |
| GET / POST clubs/{t}/blocks | eigene Sperren / account_id, blocked |
| POST clubs/{t}/inbox/{n}/read | eigenen berechtigten Hinweis markieren |
| POST clubs/{t}/chats/{c}/attachments | Multipart client_id, file |
| POST clubs/{t}/attachments/{uuid}/scan | eigene Quarantaene pruefen |
| DELETE clubs/{t}/attachments/{uuid} | eigenen unveroeffentlichten Upload verwerfen |
| GET clubs/{t}/attachments/{uuid} | private, aktuell berechtigte Bytes |

Nachricht optional attachment_id; Ankuendigung zusaetzlich
recipient_fingerprint. DTO zusaetzlich can_edit/can_delete/attachment.
Chatliste liefert can_moderate, other_account_id und uploads.

## Migration, Nachweis und offene Gates

Additive Migration83 nach82: mobile_preferences, chat_changes, chat_blocks,
chat_reports, chat_attachments. Keine Ausfuehrung auf der gemeinsamen DB.
Betriebsflags bleiben aus, kein Serverrelease und kein Provider-Schreibauftrag.
Rueckweg: Flags aus/alter Code; Daten und APP_KEY behalten. down gesperrt.

36 gezielte PHPUnit-Tests/343 Assertions erfolgreich:25 Featuretests dieses
Moduls,4 Provider-Unitests und7 vorhandene Administration/Dokumenten-Regressionen.
SQLite mit expliziter PG-Dialektanpassung und synthetischen Dateien/Providern.
13 persoenliche Transportchecks erfolgreich, davon4 neu fuer Dateien/Kontrollen.
Flutteranalyse/Webbuild separat pruefen. Widgetlauf nach Apple-Zusammenfuehrung
erfolgreich: persoenlicher Login/Navigation390 und1024px mit Fake-API. Die
vorherige Socket10057-Sperre trat dabei nicht mehr auf. Kein Nachweis fuer native Dateiauswahl, echten clamd,
PG-Sperren/Last, Antivirus-Signaturaktualitaet oder physische Pushzustellung.

Naechste Pakete: Push-Outbox/Geraeteregistrierung/Deep Links, WebSocketbetrieb,
fachlicher Mitgliedschaftsabgleich, Termin-Aenderungsmeldungen, vollstaendige
Oberflaechensprachen und Loesch-/Backup-/Geraeteabnahme. P0-P6 nicht als vollstaendig
abgeschlossen markieren. Freigaben fuer SaaS/Store/Betriebswechsel bleiben separat.


Simulator-Login: am02.11.2026 ohne Zugangsdaten live geprueft. GET
https://dev.clubunio.ch/api/mobile/v1/context und POST
https://dev.clubunio.ch/api/mobile/v1/session mit leerem JSON antworten404.
Neuer Vertrag ist dort nicht bereitgestellt; dies prueft kein Benutzerpasswort.
DEV-Bereitstellung/Migration/Aktivierung ist Voraussetzung fuer echte Anmeldung.
Parallel eingetroffener Apple-Commit d36141c vollstaendig erhalten; dessen
Lockdatei einschliesslich Archivhashes zusammengefuehrt und strikt aufgeloest.

Abschluss dieses Pakets:3 Widgettests erfolgreich (Login/Navigation390px,
1024px und fehlender Login-Endpunkt404). Kein echter Benutzerlogin.

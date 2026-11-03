# Bestehende Planungs-/My-Teams-Masken im persoenlichen Einstieg

Fortsetzung03.11.2026. Teamplanung: Originalkalender, Einzel-/Serieneditor,
Teilnahmen, Anwesenheit, Gaeste, zeitgenaue Absenzen, Spielerstatistik,
SFV-Uebernahme und zusaetzliche Einladungen verwenden persoenliche Vertraege.
My Teams: Originalteamansichten, Zuordnungen, Trainings, Kontaktansicht und
berechtigtes Mitgliederdossier mit Kontaktbearbeitung/Aenderungsantrag,
Antragspruefung, Rechnungslist/PDF. Kein AppState mit erfundenen SVS-Rollen.
Die vorhandene Teamplanung ersetzt beim freigegebenen Vertrag den frueheren
begrenzten Ersatzkalender. Heute-/Chat-/Notificationlinks nutzen denselben
persoenlichen Originaldetailablauf mit frischer Berechtigungspruefung.

Serverseitig getrennte Schalter fuer native Planung, My Teams und Spesen.
Zusaetzlich meldet der Client seine umgesetzten Featurevertraege im Header
X-ClubUnio-Mobile-Features. Erst die Kombination aus vorhandenen echten Rechten,
Serverfreigabe und Clientunterstuetzung macht einen neuen Menueeintrag bedienbar.
Der Header ist nur ein Kompatibilitaetshinweis, niemals eine Zugriffsfreigabe.
Alte Appversionen erhalten dadurch keine Menueziele, die sie nicht darstellen
koennen. Auch Custom Builds behalten Origin-/Mandantenbindung und zentralen Chat.

Mandantenwechsel schliesst Detailrouten, verwirft den persoenlichen
Planungsrepository und erstellt die Fachrepositories im neuen Kontext neu.
Spaete HTTP-Antworten werden weiterhin gegen die Sitzungs-/Mandantenversion
geprueft. Dossierrecht stammt frisch aus dem sichtbaren MemberAccess-Scope;
blosse Kadernamenssicht oeffnet keine privaten Daten oder Finanzdokumente.

Nachweise vor Release:36 Kommunikationstests/525 Assertions;51 PG-Assertions
fuer Kader-/Staffdarstellung, getrennte Funktionsrechte und Menuefreigaben.
SFV2Tests/53 Assertions, Einladungen2Tests/79 Assertions, Dossier4Tests/92Assertions.
35 Flutterfaelle fuer echten App-Einstieg, bestehende Masken, Konten-/
Mandanten-/Notificationgrenzen sowie My-Teams-Dossierpfad erfolgreich.
Zusatz21 Flutterfaelle fuer Originalplanung/SFV/Einladungen und native Menueziele.
Private Dateitransporte haben gesonderte MIME-/Signatur-/Groessen-/Sitzungstests.

Keine zusaetzliche Migration fuer dieses Kernpaket. Spesen88 und echte
Dokumentsignaturen89 werden getrennt vorbereitet; keine pauschale Fertigmeldung
fuer alle Fachpakete oder echte Android-/iPhone-/Push-/Storeabnahme.
DEV-Aktivierung wird nach bestandenem Serverlauf separat ergaenzt.

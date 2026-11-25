# Keycloak: Glasdesign, Social Login und SSO

Stand: 25.11.2026. DEV: CSS-v10/JavaScript-v8. Stage/PROD: CSS-v7/JavaScript-v6.

## Pr�zisierung: Ecken w�hrend der Eingabe sichtbar (25.11.2026)

Die vorherige Interpretation des �ffnungsabschlusses war falsch: Die Ecken
sollen sich beim �ffnen von der Mitte nach au�en bewegen und w�hrend der
Eingabe sichtbar bleiben. Nur beim Absenden treffen sie sich in der Mitte
und blenden danach aus. Das gilt f�r Login und OTP.

CSS-v10 korrigiert ausschlie�lich die �ffnungszeitleiste: 100 ms Auftakt,
1,05 s �ffnung, durchgehend Deckkraft 1 und Endposition au�en. Das Formular
erscheint parallel ab 350 ms. Submit-Zeitleiste, JavaScript-v8, OTP-Automatik,
Paste und anhaltender Pr�fstatus bleiben erhalten. Reduzierte Bewegung zeigt
die Ecken ohne �ffnungsanimation au�en und blendet sie beim Absenden aus.

Beide DEV-Themes referenzieren CSS-v10. Chrome best�tigt sichtbare �u�ere
Ecken auf der echten SV-Sissach-DEV-Anmeldeseite. Synthetische native Login-
und OTP-Formulare best�tigen sichtbare Ruheposition und ausgeblendete Ecken
bei weiterhin aktivem Pr�fstatus nach dem Absenden; unver�nderte POST-Werte.
Hintergrundtabs k�nnen CSS-Fortschritt drosseln, daher erg�nzen abschlie�ende
DOM-Aufnahmen die zeitlichen Proben. Keine echte Benutzer-/MFA-Anmeldung.
DE/EN-Katalogpr�fung: 3075 Schl�ssel. Stage/PROD unver�ndert.

[Pr�fprotokoll](evidence/keycloak-idle-corners-20261125/checks.json),
[DEV mit sichtbaren Ecken](evidence/keycloak-idle-corners-20261125/dev-visible-corners.jpg).
Sicherung: `/root/clubunio-releases/keycloak-idle-corners-20261125/dev-before-v10.tar.gz`,
SHA-256 `442a0eb0a5abc17e14ff15ef9d1245f1e7b29198b7d824b118c0e6b5235c37fc`.
R�ckweg: nur gesicherte DEV-Themes wiederherstellen und Theme-Cache kontrolliert
durch Dienstneustart erneuern. Keine Schema- oder Datenmigration.

## Erneuter Vorlagenvergleich: DEV CSS-v9/JS-v8 (25.11.2026)

Beide vom Betreiber geöffneten Facebook-Reels erneut im Browser angesehen und
mit `Glassy login v3.zip` / `Glassy otp v20.zip` vom NAS verglichen. Die vorige
Umsetzung wich in der Geometrie und beim Öffnen ab: 88-px-Kartenausschnitt statt
118,3 px; bereits in der Mitte beginnende Rückbewegung statt Annäherung von
außen. Zusätzlich begrenzte ein geerbtes `max-width: 500px` die Karte innerhalb
der 580-px-Bühne und verschob ihre Mitte. Diese Abweichungen sind korrigiert.

- Kartenausschnitt und 88-px-Glasecken mit -2-px-Außenposition aus der Vorlage;
  Dockdistanz aus der aktuellen Kartengröße und 46-px-Halbabstand berechnet.
- Eine CSS-Zeitleiste ersetzt die konkurrierende Web-Animations-Öffnung.
  Start außen, 520 ms Auftakt, 720 ms Annäherung, 140 ms Kontakt/Ausblendung;
  anschließend erscheint das Formular in 500 ms. Der Vorlagen-Rücksprung wird
  bewusst durch den vom Betreiber gewünschten Ausblendeabschluss ersetzt.
- Beim Absenden erneut 620 ms Annäherung und 140 ms Ausblendung; Login-POST
  nach 900 ms, OTP weiterhin 540 ms Scan und 340 ms Zusammenführung. Ein
  einzelnes OTP-Feld bleibt in der Mitte; die anderen Felder und Ziffern
  verschwinden. Kein vorgetäuschter Erfolg oder grüner Erfolgszustand.
- Prüfstatus/Spinner laufen bis zur Antwort/Weiterleitung weiter. Sein Platz
  bleibt reserviert, damit die Karte beim Absenden ihre Größe nicht ändert.
  Native Felder, Paste, OTP-Tools und automatische Bestätigung bleiben erhalten.
- Sprachauswahl im normalen Layout über dem Titel; keine mobile Überlagerung.
  Lichtimpuls erzeugt keinen horizontalen Scrollbereich. Die geerbte maximale
  Kartenbreite ist aufgehoben; Bühne und Karte haben dieselbe Mitte.
- Reduzierte Bewegung verzichtet auf Animation und zusätzliche Wartezeit.

Nur DEV-Themes aktualisiert; stabile Stage-/PROD-Dateien per elf SHA-256-Werten
gegen das bestehende Release-Manifest unverändert bestätigt. Account-, Admin-,
E-Mail-Themes, Clients, Realm-Regeln, Konten und MFA-Konfiguration nicht geändert.
CSS-v9 hat einen neuen Dateinamen, damit Browser keine CSS-Zwischenfassung aus
dem Cache weiterverwenden. Aktuelle Dateien stehen in beiden DEV-Properties;
`node tools/build-keycloak-glass.mjs` erzeugt JavaScript-v8.

Prüfung: vier echte HTTPS-Anmeldeseiten (ClubUnio-/SV-Sissach-DEV, DE/EN mit
geprüftem HTML-Sprachattribut), CSS-/JS-Hashes bytegleich. Chrome Desktop und
390 × 844: Karte exakt mittig, Sprachauswahl oberhalb des Titels und keine
horizontale Bildlaufleiste. Lokale native Formular-Fixture mit synthetischen
Werten: Login und OTP-Paste automatisch übermittelt; nach 2,5 s weiterhin
`aria-busy=true`, sichtbarer Status und beide Glasecken unsichtbar. Kartengröße
stabil, ursprüngliche Formularwerte und Credential-ID erhalten. Stille
OTP-Tool-Füllung sowie reduzierte Bewegung mit konkurrierenden Submit-Aufrufen
zusätzlich vor der abschließenden CSS-Layoutkorrektur geprüft. Keine erneute
echte Benutzer-/MFA-Anmeldung in diesem Durchlauf.

[Prüfprotokoll](evidence/keycloak-motion-v8-20261125/checks.json),
[DEV Desktop](evidence/keycloak-motion-v8-20261125/dev-sv-sissach-login.jpg),
[DEV Mobil](evidence/keycloak-motion-v8-20261125/dev-mobile.jpg),
[äußere Ecken, angehaltene lokale Startphase](evidence/keycloak-motion-v8-20261125/intro-corners.jpg).
Die Fixture unterstützt `probe=1` für zeitliche DOM-Beobachtungen und `freeze=1`
für eine angehaltene Startansicht; sie lauscht ausschließlich auf Loopback.

DEV-Sicherung/Rückweg:
`/root/clubunio-releases/keycloak-motion-v8-20261125/dev-before-v8.tar.gz`,
SHA-256 `90cbff82cfb237ac25856f2b0575e5c889bb0b9118ace969426ddcbe2503f7c7`.
Zum Rückweg nur die beiden gesicherten DEV-Theme-Verzeichnisse wiederherstellen
und den Theme-Cache durch kontrollierten Dienstneustart erneuern. Auf dem
geteilten Keycloak-Dienst wurden für die Aktivierung kontrollierte Neustarts
durchgeführt; Stage-/PROD-Themeinhalte blieben unverändert. Vor einer erneuten
Promotion die neue Bewegung auf DEV durch den Betreiber ansehen lassen.

## Stage-/PROD-Promotion (25.11.2026)

Nach ausdrücklicher Betreiberfreigabe den auf DEV geprüften Stand in die
bereits zugeordneten stabilen Themes übernommen. Stage/PROD verwendeten bereits
CSS-v6 und das mit DEV bytegleiche JavaScript-v6. Die verbleibende Änderung ist
CSS-v7 (Ecken nach Kontakt ausblenden). Keine Client-/Realm-Bindung geändert.

| Client | Stabiles Login-Theme |
| --- | --- |
| clubunio-platform-stage | clubunio-glass |
| clubunio-verein-stage | sv-sissach-glass |
| clubunio-platform-prod | clubunio-glass |
| clubunio-verein-prod | clubunio-glass |

Die stabilen Themes erben von `clubunio` bzw. `clubunio-glass`, niemals von
einem DEV-Theme. Ihre Ressourcen liegen unabhängig unter
`/opt/keycloak/themes/clubunio-glass` und `/opt/keycloak/themes/sv-sissach-glass`.
Damit verändert eine spätere DEV-Theme-Änderung Stage/PROD nicht automatisch.
Die vorhandene ClubUnio-/SV-Sissach-Zuordnung bleibt erhalten. Account-, Admin-
und E-Mail-Themes sowie Benutzer, MFA, SSO, Clients, SMTP und App-Releases
werden durch diese Promotion nicht verändert.

Im Repository liegen die stabilen `login/theme.properties` separat. Die
Ressourcen stammen aus denselben geprüften Quellartefakten wie DEV, werden bei
der Installation aber physisch in die stabilen Theme-Verzeichnisse kopiert.
Quellen, Zielpfade und SHA-256 stehen in
`deploy/keycloak/glass-stable-release.json`. Für eine Neuinstallation erst die
Hashwerte des Manifests prüfen, dann die dort genannten Dateien installieren;
Basis-Theme `clubunio` und bestehende Keycloak-Eltern ebenfalls bereitstellen.
Nicht pauschal einen künftigen DEV-Stand als stabil kopieren.

Sicherung vor Promotion:
`/root/clubunio-releases/keycloak-stage-prod-v7-20261125/stable-themes-before-v7.tar.gz`,
SHA-256 `77fd83a5e54a7e00a1c0bf504297d1b1f7fb4f09264bb9e4fd9664c65a6c71be`.
`before-manifest.json` daneben enthält die bisherigen Ressourcenhashes.
Tatsächliches Delta: neues v7-CSS im stabilen Basistheme und zwei Properties-
Dateien mit dem v7-CSS-Verweis; JavaScript und Branding-Ressourcen unverändert.
Nach kontrolliertem Dienstneustart die Anmeldeseiten und ausgelieferten Assets
prüfen. Rückweg: die zwei stabilen Theme-Verzeichnisse aus der Sicherung
wiederherstellen und den Theme-Cache erneut kontrolliert erneuern. DEV und
Anwendungsdatenbanken dabei nicht überschreiben.

Abnahme der Promotion: elf Manifestdateien auf dem Server per SHA-256 geprüft.
Zehn HTTPS-Prüfungen bestanden: Plattform-/Vereinslogin auf
`stage.clubunio.com` und `app.clubunio.com` sowie Vereinslogin auf
`stage-clubunio.svsissach.ch`, jeweils DE/EN. Alle führten zum erwarteten Client
und stabilen Theme; CSS-v7/JS-v6 stimmen bytegenau mit DEV überein. Chrome
zeigte SV Sissach auf Stage und ClubUnio auf PROD mit dem richtigen Logo und
aktivierter React-Ergänzung. Keycloak aktiv, externe Discovery HTTP 200.
[Prüfprotokoll](evidence/keycloak-stage-prod-v7-20261125/checks.json),
[Stage-Sichtprüfung](evidence/keycloak-stage-prod-v7-20261125/stage-login.jpg),
[PROD-Sichtprüfung](evidence/keycloak-stage-prod-v7-20261125/prod-login.jpg).
Keine gültige Benutzer-/MFA-Anmeldung auf Stage/PROD durchgeführt; der native
OTP-/Ladeablauf wurde zuvor auf dem identischen DEV-Stand geprüft.

## v7: Glasecken nach dem Zusammentreffen ausblenden (25.11.2026)

Die gelieferten ZIP-Templates wurden erneut geprüft: `handleModeSwitch` bzw.
`runDock` bewegen beide Ecken in 0,62 s nach innen, zeigen einen kurzen Kontakt
und lassen sie danach zurückfedern. Auf ausdrücklichen Wunsch wird der letzte
Teil beim Absenden stattdessen ausgeblendet: Nach 0,62 s Annäherung folgt eine
0,14-s-Ausblendung. Während der weiteren Prüfung bleiben die Ecken unsichtbar;
ihre eigene Leuchtanimation endet. Der separate Prüfstatus und Spinner bleiben
bis zur Antwort/Weiterleitung erhalten. Bei reduzierter Bewegung verschwinden
die Ecken sofort. Erneuter Formularaufruf/abgebrochene Übermittlung stellt den
Ausgangszustand wieder her. Die Öffnungsanimation bleibt unverändert.

Nur CSS ist geändert: `clubunio-glass-v7.css`, JavaScript bleibt Version 6.
Browserprüfung mit verzögerter nativer Antwort: Login und OTP jeweils beide
Ecken `opacity=0`, während `aria-busy` und Prüfstatus aktiv blieben; Spinner
lief weiter. Reduzierte Bewegung ebenfalls geprüft. Alle drei synthetischen
POSTs behielten ihre ursprünglichen Formularwerte, OTP blieb automatisch.
[Prüfprotokoll](evidence/keycloak-corners-20261125/checks.json) und
[synthetische OTP-Sichtprüfung](evidence/keycloak-corners-20261125/otp-corners-hidden.jpg).

DEV-Rückweg: `/root/clubunio-releases/keycloak-corners-20261125/themes-before-v7.tar.gz`
wiederherstellen und Theme-Cache kontrolliert erneuern. Keine Konten-, MFA-,
SMTP-, DB-, Stage- oder PROD-Änderung.

## v6: Ladezustand bis zur Antwort/Weiterleitung

Auch das Passwort-Login besitzt jetzt eine Absendeanimation: Glasecken treffen
sich in der Mitte, Licht läuft weiter, und ein DE/EN-Status zeigt die laufende
Prüfung an. Für OTP bleiben Scan/Zusammenführung und Ladeindikator nach dem
nativen POST aktiv. Es gibt keinen Timer mehr, der den Zustand vor der
Serverantwort entfernt. Neue Antwortseite/Weiterleitung beendet ihn natürlich;
Zurücknavigation aus dem Browsercache setzt das Formular zurück. Eine durch
einen anderen Submit-Handler ausdrücklich abgebrochene Übermittlung wird
ebenfalls zurückgesetzt. Es wird kein Erfolg simuliert.

Statusmeldungen: `loginPending` und `otpPending` im DE/EN-React-Katalog.
`role=status` und `aria-busy` vermitteln den Zustand auch ohne Bewegung.
Mit eingeschalteter Bewegung wird vor dem nativen Passwort-POST 650 ms für die
Eckbewegung gewartet; OTP verwendet weiterhin 880 ms für Scan/Zusammenführung.
Danach laufen Spinner/Licht bis zur Antwort weiter. Reduzierte Bewegung
verzichtet auf diese Verzögerung. Zugangsdatenfelder werden währenddessen
schreibgeschützt, nicht deaktiviert: Der native POST behält alle Werte.
Automatische OTP-Bestätigung aus v5 bleibt erhalten.

Synthetische Prüfung mit bewusst angehaltener Antwort: Nach mehr als 2,5 s
blieben Login- und OTP-Status sichtbar, `aria-busy=true`, und Spinner bzw.
OTP-Lichtlauf aktiv. Erst nach Freigabe der Antwort erfolgte die Weiterleitung.
Die native Formular-Fixture nutzt dafür einen versteckten Antwortframe, damit
das Hauptdokument während des wartenden POST inspizierbar bleibt. Kein echter
Authentifizierungsnachweis; synthetische Zugangsdaten/Code und native Felder
blieben am Empfänger unverändert. Achtstellige Auto-Bestätigung bei reduzierter
Bewegung und Schutz gegen paralleles Absenden erneut bestanden.
[Prüfprotokoll](evidence/keycloak-pending-20261124/checks.json) und
[synthetische OTP-Ladeansicht](evidence/keycloak-pending-20261124/otp-pending.jpg).

Nach DEV-Aktivierung liefern beide Domains in DE/EN das bytegleiche v6-CSS/JS.
Ein echter Keycloak-POST mit ausschließlich synthetischen, nicht existierenden
Zugangsdaten wurde abgelehnt und lieferte das native Fehlerformular zurück.
Eine erneute gültige Benutzeranmeldung wurde für diese UI-Korrektur nicht
angefordert. Die v4-Abnahme mit echtem Konto bleibt separat dokumentiert.

DEV verwendet `clubunio-glass-v6.css` und `clubunio-glass-v6.js`. Sicherung:
`/root/clubunio-releases/keycloak-pending-20261124/themes-before-v6.tar.gz`,
SHA-256 `f6e49fff5cfd06d595ccb6114d72cb24437d173480eab3ad1c1a8d57fc5e7921`.
Rückweg wie oben: nur DEV-Themes wiederherstellen, Theme-Cache kontrolliert
erneuern. Realm-/MFA-Regeln, Konten und Stage/PROD unverändert.

## v5: OTP automatisch bestätigen (in v6 enthalten)

Nach Vervollständigung des Codes wird das native Formular automatisch bestätigt:
Tastatur, Paste und OTP-Tools mit/ohne Input-Ereignis werden unterstützt.
Die aktuelle Realm-Policy wurde vor Release lesend geprüft: sechs Ziffern.
Nur exakt sechs ASCII-Ziffern lösen die automatische Übermittlung aus; keine
verkürzte Übermittlung der ersten sechs Zeichen eines längeren eingefügten Codes.
Eine abweichende UI-Policy kann über `data-otp-length` gesetzt werden (4–10).
Bei Änderung der Realm-Policy muss die UI-Länge entsprechend nachgeführt werden.

Nach 120 ms stabiler vollständiger Eingabe ruft die Ergänzung `requestSubmit()`
auf. Der bestehende Scan-/Zusammenführungsablauf und der native Keycloak-POST
bleiben erhalten. Mit reduzierter Bewegung folgt die Übermittlung direkt.
Unvollständige oder nicht numerische Eingaben sowie laufende Texteingabe-
Komposition werden nicht automatisch gesendet. Automatik, Enter und Button
verwenden dieselbe Sperre gegen doppelte Übermittlung.

Eine Seite mit serverseitig abgelehntem Code wird nicht durch eine stille
Tool-Wiederbefüllung automatisch erneut gesendet. Bewusstes Klicken/Tippen/Paste
im Codefeld aktiviert die Automatik erneut; manuelles Absenden bleibt möglich.
Die Ergänzung speichert und protokolliert keine Codes.

Browser-Fixture bestanden: fünf Ziffern ohne POST, sechste getippte Ziffer mit
genau einem POST, Paste mit Leerzeichen, stille Tool-Füllung, achtstellige
Testpolicy (keine Übermittlung nach sechs Zeichen), reduzierte Bewegung,
Schutz nach Fehler und erneute bewusste Korrektur. Gleichzeitige Automatik plus
zwei manuelle Submit-Aufrufe erzeugten genau einen nativen POST.
Alle Tests verwendeten synthetische Codes; selectedCredentialId blieb erhalten.
[Prüfprotokoll](evidence/keycloak-otp-auto-20261124/checks.json).

v5 verwendete `clubunio-glass-v5.js` mit unverändertem v4-CSS. Sicherung:
`/root/clubunio-releases/keycloak-otp-auto-20261124/themes-before-v5.tar.gz`,
SHA-256 `421e22c0d4f698ceac786a198b0b1d3ad4669eebe4b7c0d272ae7f61aa583d42`.
Rückweg: gesicherte DEV-Themes wiederherstellen und Theme-Cache durch
kontrollierten Dienstneustart erneuern. Keine Authentifizierungsflow-,
Benutzer-, DB- oder Stage-/PROD-Änderung.

## Korrektur v4: Videoanimationen und native OTP-Eingabe

Der Betreiber wies die erste gestalterische Abnahme zurück: Die typischen
Videoanimationen fehlten, und das unsichtbare OTP-Feld störte Paste/OTP-Tools.
Die damalige Sichtprüfung und der einfache POST-Test waren dafür unzureichend.

v4 übernimmt die abgeschrägte Karte und großen Glasecken der Vorlagen. Die
Ecken treffen sich in der Mitte und federn zurück; Licht läuft um die Kontur,
Reflexe ziehen über die Karte. OTP-Paneele zeigen Scan/Zusammenführung beim
Absenden. CI-Farben bleiben je Client erhalten. Der Metallhintergrund ist lokales
SVG. Die Perspektivbewegung stoppt beim Fokussieren eines Formularfelds.

Das native OTP-Feld ist jetzt wirklich sichtbar: `opacity:1`, native Ziffern
und Cursor. Es gibt keine nachgezeichneten Ziffern. Glasflächen liegen nur
dekorativ dahinter; Platz für OTP-/Passwortmanager-Badges ist reserviert.
`type=text`, `name=otp`, `autocomplete=one-time-code`, `inputmode=numeric`,
Beschriftung, Auswahl und native POST-Felder bleiben erhalten. Normales Paste
bleibt nativ; Leerzeichen/Bindestriche in rein numerisch kopierten Codes werden
normalisiert. Längere Codes werden nicht abgeschnitten. Ein Tool, das `.value`
ohne Ereignis setzt, macht den Code sofort nativ sichtbar. Ein 180-ms-Abgleich
aktualisiert lediglich Umrandung/Fortschritt.

Die OTP-Animation beginnt erst beim ausdrücklichen Absenden. Nach maximal
880 ms setzt `requestSubmit()` den ursprünglichen POST samt Keycloak-Handler
fort. Doppelklicks erzeugen keine zweite geplante Übermittlung. Die Animation
behauptet keinen grünen Erfolg: Nur Keycloak validiert den Code. Bei reduzierter
Bewegung wird ohne Verzögerung nativ übermittelt.

Chrome meldete auf dem Betreiberrechner `prefers-reduced-motion:reduce`.
Standardmäßig wird diese Vorgabe respektiert. Ein sichtbarer DE/EN-Schalter
erlaubt das ausdrückliche Ein- und Ausschalten. Nur diese Darstellungspräferenz
wird unter `clubunio-auth-motion` im lokalen Browser-Speicher je Origin gehalten;
keine Konten, Codes oder Tokens. Für die Videoabnahme muss Bewegung auf
`auth.clubunio.com` ausdrücklich eingeschaltet werden. Texte stehen in
`resources/js/keycloak-glass-messages.js`; DE/EN-Schlüssel/Werte prüft der Build.

Reproduzierbarer synthetischer Test: `python tools/keycloak-glass-preview.py`
bindet nur 127.0.0.1:8766 und liefert eine native Formular-Fixture sowie explizit
erlaubte Theme-Dateien aus. Kein Keycloak-/DB-Zugriff. Der Empfänger hält nur
Länge und Vergleichsergebnis bekannter Testcodes, keine Codewerte.
Geprüft: echtes Zwischenablage-Paste mit Leerzeichen, Tool-Füllung ohne Ereignis,
Tool-Füllung mit Input-Ereignis, sechs/acht Ziffern, unveränderter nativer POST
und Erhalt von `selectedCredentialId`. Desktop/390×844 und Bewegungsschalter
ebenfalls geprüft. Zwischenablage anschließend zurückgesetzt. Das ersetzt
keinen E2E-Test eines bestimmten installierten OTP-Produkts.

Aktive Ressourcen: `clubunio-glass-v4.css` und `clubunio-glass-v4.js`.
v3-Ressourcen bleiben erhalten. Sicherung vor Austausch:
`/root/clubunio-releases/keycloak-motion-20261124/themes-before-v4.tar.gz`,
SHA-256 `d37863ddc53dee2385e5346910c1963578bb5c21b2ddeb9e49b7a61c78652e56`.
Rückweg: nur die zwei gesicherten DEV-Theme-Verzeichnisse wiederherstellen,
Theme-Cache durch kontrollierten Dienstneustart erneuern. Keine Flow-,
Benutzer-, DB- oder Stage-/PROD-Clientänderung.

Live-Abnahme v4 am 24.11.2026: Der Betreiber bestätigte „Paste und Anmeldung
funktionieren“. Anschließend im Browser das angemeldete Vereinsportal unter
`https://dev-clubunio.svsissach.ch/` mit SV-Sissach-Navigation beobachtet.
Ein bestimmtes OTP-Produkt wurde dabei nicht benannt. Der generische silent-
write-/Input-Ereignis-Pfad ist durch die oben genannten synthetischen Tests
abgedeckt. HTTP-Prüfung DE/EN auf beiden DEV-Domains: alle Assets HTTP 200,
v4-CSS/JS bytegleich mit dem lokalen Build. Übersetzungsprüfung erfolgreich.
[v4-Prüfprotokoll](evidence/keycloak-motion-20261124/checks.json) und
[synthetische OTP-Sichtprüfung ohne Kontodaten](evidence/keycloak-motion-20261124/otp-native-desktop.jpg).

## Ursprüngliche Gestaltung (v3)

Die vom Betreiber bereitgestellten Vorlagen `Glassy login v3.zip` und
`Glassy otp v20.zip` sowie beide Code-XR-Facebook-Reels wurden geprüft.
Die Vorlagen simulieren erfolgreiche Anmeldungen per Timer, enthalten starre
englische Texte, deaktivieren beim Login teilweise Passwortmanager und bringen
eigene Paketabhängigkeiten mit. Diese Teile werden nicht übernommen.
Übernommen ist die Gestaltungsrichtung: Glasflächen, Lichtkanten, abgesetzte
Ecken und OTP-Paneele, angepasst an die vorhandenen ClubUnio-Farben und Radien.
Keine ZIP-Abhängigkeiten, Fremdschriften oder externen Hintergrundbilder geladen.

Neue Themes:

| Client | Login-Theme | Rückweg |
| --- | --- | --- |
| clubunio-platform-dev | clubunio-glass-dev | clubunio |
| clubunio-verein-dev | clubunio-glass-dev | leere Client-Auswahl/Realm-Fallback |
| clubunio-sv-sissach-dev | sv-sissach-glass-dev | sv-sissach |

Die Themes erben die echten Keycloak-Formulare. React 19.2.0 ergänzt dekorative
Lichtkanten und eine visuelle OTP-Eingabe. Das native, beschriftete OTP-Feld bleibt
das einzige Eingabefeld und wird unverändert an Keycloak gesendet. Einfügen,
`one-time-code`, Tastatur, Passwortmanager und serverseitige Fehlermeldungen
bleiben erhalten. Die Paneele sind für Screenreader ausgeblendet. Keine
Erfolgssimulation, automatische Codeübermittlung, Protokollierung oder Speicherung
von Zugangsdaten. Ohne JavaScript bleiben die nativen Formulare bedienbar.
Bewegung folgt `prefers-reduced-motion`; Forced Colors zeigt native Eingaben.
Die visuelle Mindestzahl von sechs Paneelen entspricht der aktuellen Realm-
OTP-Policy. Längere Eingaben werden nicht abgeschnitten.

Build: `node tools/build-keycloak-glass.mjs` (vorhandenes esbuild/React).
Quellcode: `resources/js/keycloak-glass.jsx`. Ergebnis im Theme-Verzeichnis
`deploy/keycloak/themes/clubunio-glass-dev/login/resources/js` wird mit versioniert.
Installation: beide neuen Theme-Verzeichnisse nach `/opt/keycloak/themes/` kopieren,
Client-Theme in der Admin-Konsole setzen. Keine Realm-weite Login-Theme-Änderung.
Account-, Admin- und E-Mail-Themes bleiben beim bestehenden Stand.

Beim Nachbessern gecachte Ressourcen berücksichtigen: CSS-Revisionsnamen ändern
und Theme-Cache kontrolliert durch Dienstneustart erneuern. Nicht pauschal alle
Keycloak-Caches oder laufenden Sitzungen löschen. Rückweg nur über die drei
Client-Theme-Felder; bisherige Themes bleiben installiert.

## Social Login: Registrierung fehlt

Der Betreiber hat bestätigt, dass noch keine OAuth-Anwendungen registriert sind.
Deshalb ist kein Anbieter als betriebsbereit aktiviert. Es werden keine
funktionslosen Social-Buttons eingeblendet. Die nativen Keycloak-Providerlinks
werden nach Freischaltung automatisch im Glasdesign angezeigt.

Im installierten Keycloak 26.8.2 sind Google, Microsoft und Facebook enthalten.
Apple ist nicht in der angebotenen nativen Social-Providerliste. Für Apple
braucht es eine geprüfte OIDC-/Broker-Erweiterung mit Apples POST-Callback,
korrekter Verarbeitung von Name/E-Mail bei erstmaliger Zustimmung und
Rotation des signierten Client-Secrets. Keine ungetestete Erweiterung installieren.

| Anbieter | Registrierung | Exakte Rücksprungadresse bei Alias wie angegeben |
| --- | --- | --- |
| Google | OAuth-Webanwendung, Zustimmungskonfiguration und DEV-Testnutzer | https://auth.clubunio.com/realms/clubunio/broker/google/endpoint |
| Microsoft | Entra-Webanwendung; organisationsübergreifende und persönliche Konten, falls beide gewünscht | https://auth.clubunio.com/realms/clubunio/broker/microsoft/endpoint |
| Facebook | Meta-App mit Facebook Login, E-Mail-Berechtigung und DEV-Testern | https://auth.clubunio.com/realms/clubunio/broker/facebook/endpoint |
| Apple | Services ID, primäre App ID mit Sign in with Apple, Team ID, Key ID und privater Schlüssel | https://auth.clubunio.com/realms/clubunio/broker/apple/endpoint (geplanter Alias; erst nach Brokerprüfung) |

Die Rücksprungadresse führt zu Keycloak, nicht zur Vereinsdomain. App-Registrierungen
und Secrets gehören in die Anbieterportale und die Keycloak-Verwaltung, nie ins Git.
`deploy/keycloak/social-login/providers.example.json` ist eine deaktivierte,
secretfreie Vorlage für die drei eingebauten Provider. Sie ist ohne echte
Client-ID und Secret nicht als ausführbares Importpaket zu verwenden.
Aktivierung zunächst für DEV-Abnahme; im gemeinsamen Realm außerdem die
Sichtbarkeit und Zulässigkeit pro Client prüfen, bevor ein Provider aktiviert wird.

Sicherheitsregeln für die Anbindung:

- Bestehendes Keycloak-Konto erst nach Nachweis der bisherigen Anmeldung
  verknüpfen. Kein automatisches Linking nur anhand derselben E-Mail-Adresse.
- `trustEmail=false`, `storeToken=false`; keine unnötigen Google-/Facebook-
  Zugriffstokens in der Anwendung speichern.
- Standard-First-Broker-Login mit Bestätigung/Authentifizierung bestehender Konten
  beibehalten und mit vorhandenen Konten testen. Private Apple-Relay-Adressen
  sind keine automatische Zuordnung zu einer Vereinsmitgliedschaft.
- Vereinszugang weiterhin anhand issuer/sub und ClubUnio-Mitgliedschaft prüfen.
  Externer Login erstellt keine Vereinsrolle und keine SuperAdmin-Berechtigung.
- Der aktuelle eigene DEV-MFA-Flow muss für den Brokerpfad erweitert und geprüft
  werden; ein Social-Login darf die benötigte MFA-Stufe nicht umgehen.
- Abnahme DE/EN: neuer Nutzer, vorhandenes Konto, abgelehnte Zustimmung,
  fehlende E-Mail, Widerruf, Verknüpfen/Trennen, MFA/Passkey und falscher Verein.

Quellen: [Keycloak Identity Brokering](https://www.keycloak.org/docs/latest/server_admin/#_identity_broker),
[Google OIDC](https://developers.google.com/identity/openid-connect/openid-connect),
[Microsoft OIDC](https://learn.microsoft.com/en-us/entra/identity-platform/v2-protocols-oidc),
[Apple Web-Konfiguration](https://developer.apple.com/help/account/capabilities/configure-sign-in-with-apple-for-the-web).

## SSO zwischen DEV und Stage: Befund und Vorgehen

Beide Instanzen verwenden den Realm `clubunio` auf `auth.clubunio.com`.
Die Code- und Datenbankprüfung am 24.11.2026 zeigte drei konkrete Hindernisse:

1. `PlatformOidcController::start()` fordert für den Keycloak-only-Vereinslogin
   `max_age=0` und den Essential-ACR `clubunio-mobile-mfa` an.
2. `KeycloakMobileAssurance::accepts()` akzeptiert `auth_time` nur bis fünf Minuten.
3. Der Client-Override `clubunio-verein-dev-mfa` enthält keinen Cookie-Schritt;
   sein LoA-2-Condition-Config hat `loa-max-age=0`.

Dieser trotz seines Namens gemeinsame Flow wird auch von den Vereinsclients
DEV/Stage/PROD und Plattformclients Stage/PROD verwendet. Nicht direkt ändern.
Die Design-Aktivierung ändert keine dieser Sicherheitsregeln.

Gewünschtes SSO bedeutet: Stage startet seinen eigenen Authorization-Code-PKCE-
Ablauf; Keycloak erkennt seine vorhandene Sitzung im Browser und gibt Stage
einen neuen, für dessen Client bestimmten Code/Token. Der DEV-Token, App-Key
oder Laravel-Sitzungscookie wird nicht zwischen Umgebungen kopiert. Rollen,
Mitgliedschaft und Kontozuordnung werden in jeder Instanz erneut geprüft.
Beim erstmaligen Besuch kann ein Klick auf Anmelden nötig bleiben, wenn die
Anwendung den OIDC-Ablauf nicht automatisch startet.

Umsetzung als gesonderter Authentifizierungsrelease vorbereiten:

1. Eigenen DEV-Step-up-Flow gemäß Keycloak-Dokumentation mit Cookie und
   getrennten LoA-1-/LoA-2-Bedingungen erstellen. Den bestehenden Flow erhalten.
2. Gemeinsame begrenzte Web-SSO-Frist in Keycloak und Backend konfigurieren;
   Web-Prüfung von der weiterhin frischen mobilen Gerätebindung, Einladungs-
   und Admin-Migrationsprüfung trennen. Nicht nur `max_age` entfernen.
3. Passwort+OTP und Passkey mit User Verification müssen die tatsächlich
   erreichte Stufe belegen. Niedrige/fehlende/falsche ACR, alter Nachweis und
   fehlende Mitgliedschaft müssen weiterhin scheitern.
4. DEV zuerst mit zweitem Testclient abnehmen: frischer Login, Clientwechsel,
   Fristablauf, Abmelden, neuer Browser, widerrufene Berechtigung und mobile Bindung.
5. Erst danach Stage-Client und Stage-Backend gemeinsam umstellen; PROD separat.

Siehe [Keycloak Step-up](https://www.keycloak.org/docs/latest/server_admin/#_step-up-flow).
Es wurde keine vollständige DEV→Stage-SSO-Abnahme behauptet oder die MFA
zur Umgehung der erneuten Anmeldung abgeschwächt.

## Historische erste Abnahme (v3)

Die drei DEV-Client-Theme-Felder wurden über die angemeldete Admin-Konsole
gespeichert. Neue Theme-Dateien auf dem VPS installiert; Keycloak nach
Cacheerneuerung aktiv, lokale und externe Discovery HTTP 200. DE/EN auf beiden
DEV-Domains HTTP 200; alle referenzierten Styles/Skripte abrufbar und neue
CSS-/JS-Dateien bytegleich mit dem Quellstand. Native Passwort-Autovervollständigung
vorhanden. `php tools/check-translations.php`: 3075 Schlüssel DE/EN erfolgreich.

Chrome prüfte den echten DEV-Login in beiden Sprachen und die echte Keycloak-
Ablehnung synthetischer, nicht existierender Zugangsdaten. Desktop und 390×844:
ein Logo, mittiges Formular, sichtbarer Fokus, kein horizontales Scrollen.
Der isolierte lokale OTP-Oberflächentest prüfte sechs und acht sichtbare Ziffern
sowie die unveränderte native POST-Übermittlung eines synthetischen achtstelligen
Codes. Dieser Test ersetzt keine erfolgreiche Keycloak-MFA-Anmeldung.

Die echte OTP-Seite wurde nach Benutzeranmeldung ebenfalls aufgerufen: sechs
Paneele, ein natives POST-Eingabefeld, Autocomplete und Tastatur-Sprachwechsel
bestätigt. Der deutsche OTP-Text wurde dabei ausdrücklich als `Einmalcode`
ergänzt. Die erfolgreiche Codeprüfung bis ins Vereinsportal wurde bei der
v4-Abnahme nachgeholt (siehe oben). Social-Login-E2E hängt von den Anbieterregistrierungen
und dem geprüften Broker-/MFA-Pfad ab. Die SSO-Umstellung ist als eigener
Authentifizierungsrelease offen. Stage-/PROD-Client-Theme und Authentifizierungs-
regeln wurden nicht geändert. Kein DB-Schema und keine Fachmigration erforderlich.

[HTTP-Prüfprotokoll](evidence/keycloak-glass-20261124/http-checks.json) und
[SV-Sissach-Sichtprüfung](evidence/keycloak-glass-20261124/sv-sissach-login.jpg).

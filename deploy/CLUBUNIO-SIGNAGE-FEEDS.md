# ClubUnio: Wetter und Instagram in der Signage-Vorschau

Release42: angemeldetes Vollbild in Signage-Vorschau. Admin kann vorhandenen
INSTAGRAM_ACCESS_TOKEN unter Einstellungen aktivieren/deaktivieren, mit
Zustandsvergleich und Audit. Kein Tokenwechsel/Livefreischaltung in diesem
Release ausgefuehrt. Die bisherige Aussage "kein enabled-Schalter" unten ist
historisch. Weitere Grenzen siehe CLUBUNIO-WEB-COMPLETION.md.

Release35, 12.10.2026. Nur ClubUnio-Web; kein oeffentlicher Displaybetrieb,
keine App-API, kein SaaS/Cutover und keine Aenderung an PHPMaker DEV/PROD.

## Anzeige und Grenzen

- Anlagen und Spielbetrieb -> Signage-Vorschau zeigt Wetter aktuell und Instagram.
- Wetter: Temperatur, Wetterzustand, Wind und drei Tagesprognosen fuer Sissach.
  Abrufzeit und Modellzeit sind sichtbar, Zeitzone Europe/Zurich.
  Auch bei historischem Spieldatum bleibt dies ausdruecklich aktuelles Wetter.
  Keine automatische Platzsperre oder vermeintliche Messwertanzeige.
- Instagram: maximal acht Beitraege, Videos als Vorschaubild, Link zum Original.
  Texte sind escaped; defekte Bilder zeigen einen Link statt kaputter Vorschau.
- Initialer Seitenaufbau ruft keinen Anbieter auf. Isolierte Livewire-Komponente
  laedt danach nach; externe Fehler blockieren nicht den Spielplan.
- Polling alle60 Sekunden, Anbieterabruf hoechstens alle15 Minuten bei Erfolg.
  Kurze Zeitlimits (Verbindung2s/Gesamt5s je Anbieter), keine Weiterleitungen.
  Fehlversuche haben5 Minuten Pause. Letzte Daten bleiben maximal6 Stunden mit
  Warnung und unveraendertem Abrufzeitpunkt sichtbar; danach nicht verfuegbar.
- Cache und Sperre sind je Club/Feed/Konfigurationsfingerabdruck getrennt.
  Deaktivierung oder Tokenwechsel verwendet keine alten Posts weiter.
- Aktive Backoffice-Berechtigung wird bei jedem Abruf erneut geprueft;
  Mandant und Feed-Daten koennen nicht vom Browser veraendert werden.

## Konfiguration und Migration

Migration `database/clubunio/2026_10_12_000033_signage_weather_settings.php`
auf der eigenen gemeinsamen ClubUnio-DB ausgefuehrt. Vier verschluesselte Werte
im vorhandenen Bereich integrations, nur falls noch nicht vorhanden:

| Key | Initialwert |
| --- | --- |
| SIGNAGE_WEATHER_ENABLED | 1 (0 deaktiviert) |
| SIGNAGE_WEATHER_LAT | 47.46408 |
| SIGNAGE_WEATHER_LON | 7.80888 |
| SIGNAGE_WEATHER_LABEL | Sissach |

Wetterwerte in Verwaltung/Einstellungen aenderbar. Ausserhalb gueltiger
Koordinaten oder bei fehlender Konfiguration wird kein externer Abruf versucht.
Keine neue Tabelle, kein Cronjob, keine Aenderung von Abhaengigkeiten.
Ruecksprung auf Release34 kann diese additiven Einstellungen behalten.

Instagram verwendet den schon vorhandenen INSTAGRAM_ACCESS_TOKEN, jedoch nur
wenn sein enabled-Flag gesetzt ist. INSTAGRAM_SECRET/INSTAGRAM_USER_ID werden
fuer diesen Feed nicht benoetigt und nicht geloescht. Alle drei importierten
Instagram-Parameter sind derzeit deaktiviert geblieben. Aktivierung und realer
Feed-Test sind separat offen; die Einstellungsmaske ersetzt Werte, schaltet
aber das enabled-Flag nicht um. Keine automatische Token-Erneuerung implementiert.

## Sicherheit und Diagnose

Anbieter-URLs fest vorgegeben. Instagram-Token nur als Authorization-Header
an graph.instagram.com, nie in Browserzustand, URL, Cache-Nutzdaten oder Logs.
Logs enthalten Club-ID, Feedname und HTTP-Status, keine Antworttexte/Secrets.
Instagram-Bilder nur HTTPS von cdninstagram.com/fbcdn.net samt Unterdomains;
Links nur instagram.com. Keine Userinfo oder abweichenden Ports zugelassen.
Der Browser laedt Bilder direkt von Meta-CDNs mit no-referrer. Das ist keine
eigene Medienarchivierung; ablaufende CDN-URLs koennen trotz Cache ausfallen.

Wetterquelle: [Open-Meteo MeteoSwiss API](https://open-meteo.com/en/docs/meteoswiss-api),
Modell meteoswiss_icon_seamless. Quellenangabe in der Anzeige vorhanden.
Kommerzielle Nutzung/Anbieterbedingungen vor einem spaeteren SaaS-Betrieb pruefen.
Instagram-Login-Feed folgt der bestehenden funktionierenden Legacy-Anbindung
`https://graph.instagram.com/v25.0/me/media`, nicht Facebook-Graph-Login.

## Pruefung

SignageFeedsTest:9 Tests/43 Assertions, rollback-isolierte Testdaten und gefakte
Anbieter. Cache, Fehlerzustand, Datenvalidierung, Tokenwechsel, Redaction,
URL-Allowlist, deaktivierte Feeds, entzogene Rechte und gesperrte Properties.
Echter Wetterabruf lokal erfolgreich; Desktop und390px Mobilbreite geprueft.
Instagram ohne Livefreischaltung nur automatisiert getestet.
Regression/Deploymentabschluss siehe PI.md und CLUBUNIO-ROADMAP.md.

# Teilgutschriften und Kundenguthaben

Fachpaket67,15.10.2026. Nur ClubUnio/eigene Datenbank und Bexio-Sandbox.
Keine Bankzahlungen, keine externe Zustellung, kein API-/SaaS-/Betriebsprojekt.
Bestehendes PHPMaker DEV/PROD bleibt unangetastet.

## Webablauf

1. Bei einer eigenen freigegebenen Rechnung eine positionsbezogene Teilkorrektur
   erstellen und intern freigeben. Originalpreise, Rabatt, Rundung und Steuern
   bleiben als Snapshots erhalten; weitere Korrekturen verbrauchen nur den Rest.
2. Gutschrift manuell in Bexio erstellen. Unter Gutschriften > Details >
   Als Kundenguthaben fuehren die GS-Nummer angeben und Gesamtbetrag/Steuer
   anhand der Originalkorrektur manuell bestaetigen. Nur Admin.
3. Finanzen > Kundenguthaben zeigt Gesamtbetrag, nachgewiesene Verrechnungen,
   noch nicht verrechneten Betrag und urspruengliche Steuerkorrektur.
   Gleiche Ansicht unter Mitglied > Kundenguthaben. Kein negativer Rechnungsrest.
4. Nach manueller Verrechnung in Bexio: Bexio-Verrechnung abgleichen, Zielrechnung
   suchen/waehlen. Nachweis wird erneut gelesen; kein Bexio-Schreibaufruf.
   Auch Zielrechnungen anderer Kinder desselben verifizierten Rechnungsempfaengers
   sind zulaessig; blosse Familien-/Mitgliedsnamensgleichheit reicht nicht.
5. Vollstaendige Verrechnung bestaetigt die lokale Korrektur. Teilverrechnung
   belaesst sie freigegeben, mit separatem Journal. Zielrechnung zeigt ihre
   nachgewiesene Verrechnung und Link zum Guthaben.

Noch nicht verrechnet ist ein MANUELL attestierter Rest, kein automatisch
verifizierter Bexio-Kontostand und kein auszahlbares Bankguthaben. Der Betrag
stammt zwingend aus genau einer Originalkorrektur, nicht aus einem freien
Betragsfeld. Freie/unspezifische Kundengutschriften ohne Ursprungsrechnung sind
nicht Teil dieses Vertrags. Geld-Ueberzahlungen bleiben im bisherigen
Zahlungslisten-/Rueckerstattungsprozess getrennt. Mitglied > Rechnungen zeigt
nun Bexio-Rest/Gutschriften/Geldguthaben, reservierte/bestaetigte Rueckerstattungen
und Zeitpunkt des letzten Abgleichs; keine Liveabfrage je Listenzeile.

## Sicherheitsregeln

Frische Adminpruefung, Vereinssperre/Revision und Transaktion. GS-Nummer,
Sandbox-Verbindung, Provider-Gutschrift-ID, verifizierter Rechnungsempfaenger,
Waehrung, Rechnungs-ID und Zahlungs-Eintrags-IDs muessen passen. Summen duerfen
den attestierten Originalbetrag nicht uebersteigen. Keine Doppelanlage pro
Rechnung/Provider-Gutschrift, auch nicht ueber den bisherigen Einzelabgleich.
Bestehende bestätigte Einzelgutschriften werden nicht automatisch umgebucht.
Reine Gutschriftsverrechnung erzeugt keinen Rueckerstattungsanspruch.

Vor jeder weiteren Zuordnung werden alle bisherigen Nachweise erneut gelesen.
Geaenderte/entfernte Providerverrechnungen blockieren die Fortsetzung zur
Klaerung, statt still wieder Guthaben freizugeben. Maximal50 Verrechnungen pro
Guthaben im synchronen Webweg. Keine Behauptung atomarer externer API-Snapshots:
Providerbeobachtungen sind zeitgestempelte Lesestaende vor der lokalen Transaktion.
Bereits abgeglichene Verrechnungen sind unveraenderlich. Aufhebung/Neubuchung in
Bexio oder nachtraegliche Vergroesserung derselben Verrechnung erfordert eine
gesonderte fachliche Korrektur, keinen erneuten blinden Klick.
Fehleingaben der GS-Nummer sind vor der ersten Verrechnung revisionsgeschuetzt
korrigierbar und auditiert. Gebundene Korrektur kann nicht einfach storniert
oder parallel ueber den alten Weg bestaetigt/ausgezahlt werden.

## Steuer und Liveabnahme

Der bisherige Originalsteuer-/Restbetragsschutz bleibt erhalten. Testfall:
ClubUnio-Korrektur18 zu Rechnung8440/Bexio RE-00006, Rechnungsbetrag40.25CHF,
Teilkorrektur10.00CHF auf Position1/8.1%, Steuer intern0.748933CHF (Anzeige0.75).
ID18 ist KEINE Bexio-Gutschriftsnummer. RE-00006 ist voll bezahlt. Eine neue
Gutschrift bleibt daher zuerst Kundenguthaben und kann gegen eine andere
offene Rechnung desselben Sandboxkunden verrechnet werden.
Lesetest15.10.: Bexio liefert auf RE-00006 noch0 Gutschrift/keine Verrechnung.
Manuelle Anlage und GS-Nummer stehen aus; dieser Live-Steuertest ist NICHT
abgenommen. Die API-Zahlungsliste belegt Nummer/Betrag/Verrechnung, nicht den
Steuerinhalt der separat manuell angelegten Bexio-Gutschrift.
RE-00009/GS-00004 erneut lesend bestaetigt:1005Rappen, Cash0, Rest0, Eintrag20.
Keine echte Zwei-Rechnungen-Verrechnung in Bexio vorgetaeuscht; diese wird
automatisiert mit kontrollierten Providernachweisen getestet.

## Migration / Ruecksprung

Eigene additive Migration000066 erstellt customer_credits und
customer_credit_allocations samt Eindeutigkeitsregeln und Journalnachweisen.
Keine Bestandsbelege/Originalsteuern/Altdateien/ini-Werte umgeschrieben.
Neue Daten sind fuer alte Releases unsichtbar. Nach Erfassung echten
Testguthabens vor Ruecksprung die offenen Bindungen klaeren; kein destruktives
down und keine automatische Wiederfreigabe der Originalkorrektur.

## Fachabgleich

PMP-Metadatencheck:104 Objekte/814 Felder/31 Beziehungen/218 Eventreferenzen
weiter konsistent. Finanzzuordnung in der Matrix um neue Tests/Journal erweitert.
Restluecke behoben: CatalogService prueft den Aktivstatus auch bei veraltetem
Actorobjekt frisch und erneut vor der Stammdatenmutation.
Automatisierte Rollen-/Workflow-/Listenpruefung ersetzt keine vollstaendige
Benutzerabnahme aller218 Legacy-Events. Hardware, historische Zuordnungen und
fehlende manuelle Sandboxnachweise bleiben transparent offen.

Testnachweis:55 Tests392 Assertions (Gutschriften, Kundenguthaben, PMP,
Dossierpflege und Anmeldungen) erfolgreich. Abschliessend8 Tests112 Assertions
fuer echte Livewire-Aktionen, Dossier/Filter, deaktivierten Stammdatenbenutzer,
Rechnungsworkflow, Beitraege, Familienliste/Export und Dashboard erfolgreich;
teilweise ueberlappende Abdeckung. Der komplette448-Test-Lauf wurde nicht
abgeschlossen und ist keine Gesamtfreigabe. Alle synthetischen DB-Daten
zurueckgerollt; neue Tests verhindern unerwartete externe HTTP-Aufrufe.

Deployment:20261015-clubunio-67-customer-credits aktiv, Ruecksprung66 erhalten.
195 verschluesselte Settings/46 Vorlagen geprueft, Outbound aus. Angemeldete
Webliste auf Pi erreichbar, Servicehashes stimmen. Kein reales Kundenguthaben
fuer eine noch nicht angelegte GS behauptet; Liste bleibt bis Erfassung leer.
Paket bewusst aus Basis66 plus eigenem Delta statt fremden lokalen
Workspace-Zwischenstaenden erstellt.

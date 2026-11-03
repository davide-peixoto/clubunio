# Persoenliches Mitgliederdossier: bestehende SVS-Masken

Stand03.11.2026, Implementierung lokal; Deployment durch den gemeinsamen
Releaseablauf nach Integration. Keine neue Ersatzoberflaeche.

MobileMemberController/Data bilden den vorhandenen MemberDetailScreen mit
persoenlichem Account und Mandantenkontext ab. Reine Kadernamenssicht wird
nicht zu Dossier-/Finanzzugriff erweitert. Selbst-/Elternvertretung, aktuelle
Teamverantwortung, members.view und members.update werden frisch geprueft.
Trainer erhalten fuer sichtbare Spieler nur Name/E-Mail/Mobile/Ort. Eltern-,
Geburts- und Passdaten nur fuer vertretene Mitglieder oder members.view.
AHV, providerinterne Antworten und technische Mitgliedsdaten werden nie
ausgegeben. Beendete Elternvertretung und widerrufene Tokens gelten sofort.

Kontaktbearbeitung verwendet die angezeigte MemberService-Version. Mitglieder
und wirksame Elternvertretungen stellen echte MemberChangeService-Antraege;
direkte Aenderung nur mit members.view+update. Die bestehende Maske zeigt
die serverseitigen editierbaren Felder und den tatsaechlichen Pruefhinweis.
Homepage ist ohne Direktupdate nicht im bestehenden Antragsschema erlaubt.
Laendercodes werden mandantengebunden eindeutig auf Lookup-IDs aufgeloest.
Rechnungsempfaenger stammen aus konfigurierten IDs/Labels, nicht aus einer
Annahme ueber numerische oder fremde SVS-IDs. Inaktive bisherige Lookupwerte
bleiben zur Anzeige/Beibehaltung verfuegbar, Neuwerte folgen den Fachregeln.

Die bestehende Aenderungspruefung verwendet persoenliche member_changes-Rechte.
Listen sind an das geoeffnete Mitglied gebunden; Vergleiche kommen aus dem
echten verschluesselten Antragssnapshot. Genehmigen/Ablehnen senden die im
Detail angezeigte Revision. Genehmigen prueft weiterhin die Mitgliedsversion
und noch gueltige Selbst-/Elternvertretung; Ablehnen erfordert eine Begruendung.
Separate CanApprove/CanReject steuern die vorhandenen Buttons.

Rechnungslisten und PDF-Zugriff folgen dem bestehenden persoenlichen
MobileAccess.invoice-Vertrag: eigene/vertretene Mitglieder oder persoenlicher
Admin. Team-/Mitgliedssicht alleine gibt keinen Finanzzugriff. Entwuerfe
werden fuer persoenliche Mitglieder gemaess InvoiceAccessService ausgeblendet.
Betrag aus InvoiceTaxService in Rappen, Anzeige in CHF; keine Bexio-Rohfehler.
PDFs durchlaufen bei jedem Oeffnen erneute Serverpruefung. Die native Ansicht
verwendet Speicherbytes statt des alten unmandantierten Festplattencaches.
Explizites externes Oeffnen schreibt eine neu autorisierte Datei in einen
zufaelligen temporaeren Ordner, ohne wiederverwendeten Cache.

Original MyTeamsScreen/PeopleSection oeffnen bei CanOpenDossier ein neu
gebundenes PersonalMembersRepository. Andere Kaderkontakte bleiben in der
vorhandenen eingeschraenkten Kontaktansicht. Konto-/Repositorywechsel verwerfen
Details; ein fehlgeschlagener persoenlicher Refresh zeigt keine alten privaten
Dossierdaten. Der geerbte HTTP-Client ist gesperrt, keine Integrationstokens
oder PHPMaker-Fallbacks. Dokumente/Ausweis bleiben ohne Vertrag deaktiviert.

## Schnittstellen

Alle unter /api/mobile/v1/clubs/{tenant}/:
GET members/{member}, GET members/{member}/contact-options,
POST members/{member}/contact mit _version,
GET members/{member}/invoices,
GET members/{member}/change-requests,
POST members/{member}/change-requests/{change}/decide mit Revision/approve.
PDF: bestehendes GET invoices/{invoice}/pdf.

Keine Schemaaenderung fuer diesen Kernabschnitt. Vier gezielte PG-Tests mit92
Assertions erfolgreich; alle Fixtures synthetisch und vollstaendig
zurueckgerollt. PDF-Bytes/Provider im Fachtest gestubbt, die echte persoenliche
Rechtepruefung laeuft davor; kein externer Bexioaufruf oder Zahlung.
Neue Flutterfaelle pruefen Repository und echte vorhandene Detail-/Editor-/
Rechnungs-/Antragsmasken, angezeigte Revisionen, falsche Antworten und
Fehler-/Kontokontext. Gemeinsame Regression und Deploynachweis folgen im
Root-Releaseprotokoll.

## Weiter offen

Der komplette Dokumenten-/Unterschriftsvertrag und der bisherige SVS-Ausweis
sind noch nicht migriert. Vorhandene ClubUnio-Materialbestaetigung oder Upload
einer extern unterschriebenen Datei ersetzen keine gezeichnete SVS-Signatur.
Echte PDF-Signaturevidence mit unveraendertem Original, Hashbindung, wirksamer
Vertretung und privatem Versions-/Idempotenzvertrag wird gesondert umgesetzt.
Gesamtauftrag und native Gesamtfreischaltung bleiben offen.

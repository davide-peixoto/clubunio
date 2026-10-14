# ClubUnio: Benutzer und Rollen

Stand 12.10.2026, Web-Fachpaket33. Nur ClubUnio, kein Eingriff in PHPMaker.

## Masken und Rechte

- Verwaltung -> Benutzer und Rollen: /clubunio/sv-sissach/tenant-accounts.
  Nur aktive Administratoren des gewaehlten Vereins sehen oder bearbeiten sie.
  Geschaeftsstelle/Trainer/TL/Mitglieder erhalten auch bei direktem Aufruf403.
- Bestehende Benutzerzuordnungen bleiben separat: eigenes Mitglied bzw.
  Elternvertretung und deren Freigabe. Die neue Maske ersetzt diese nicht.
- Konten anlegen, Anzeigename bearbeiten, aktivieren/deaktivieren, Rolle und
  Portalzugriff setzen, optional Passwort neu setzen. Benutzername nach Anlage
  unveraenderlich; Neuanlage normalisiert Kleinbuchstaben, keine Leerzeichen.
- Neue Konten sind standardmaessig inaktiv, Rolle member, Portal aus. Keine
  automatische Mitgliedszuordnung oder Aktivierung bestehender importierter Konten.
- Rollenbezeichnungen/Auswahl stammen aus account_roles, nicht aus einem
  frei eingegebenen Userlevel. Aktuelle Codes: admin,office,technical_lead,
  trainer,member. Fachliche Berechtigungen bleiben in den Services definiert;
  kein frei editierbarer Rechtebaukasten und keine neue SaaS-Rollenengine.
- Portal-Schalter schaltet NICHT den Backoffice-Zugang fuer Admin/Office ab.
  Mitglieder/Trainer/TL brauchen zusaetzlich gueltige freigegebene Zuordnungen
  und die bestehenden Mitgliedschafts-/Teamregeln. Konto inaktiv sperrt Login.

## Schutz und Nachvollziehbarkeit

- Service prueft Adminrecht erneut innerhalb der Transaktion. Vereins- und
  Kontosperre, getrennte Konto-/Zuordnungsrevision, Feld-Whitelist und FK-Rollen.
  Fremde Zuordnungen sowie veraltete Formulare werden abgewiesen.
- Letzter aktiver Admin kann weder deaktiviert noch heruntergestuft werden.
  Weitere aktive Admins werden unter derselben Vereinssperre geprueft.
- Globale Kontodaten eines bereits vereinsuebergreifend genutzten Kontos koennen
  nicht hier veraendert werden; nur die lokale Rolle/Portalzuordnung ist erlaubt.
  Dies verhindert verdeckte Auswirkungen auf andere Vereine, ohne SaaS auszubauen.
- Passwort mindestens12 Zeichen, maximal72 UTF-8-Bytes, Bestaetigung erforderlich,
  Hashing mit Laravel Hash. Bei Bearbeitung leer lassen bedeutet unveraendert.
  Keine Passwort-/Hashausgabe in Listen, Vorbelegung oder Audit. Keine Email-
  oder Pushzustellung, kein Einladungs-/Reset-Link-Versand dieses Fachpakets.
- Jede Aenderung mit Pflichtbegruendung und Vorher/Nachher in object_changes;
  Passwortaenderung nur als boolean password_changed, niemals als Geheimnis.
- Konten werden nicht physisch geloescht. Selbstherabstufung ist nur mit einem
  zweiten aktiven Admin moeglich; weitere Adminaktionen sind danach verboten.

## Migration und Test

Migration000031 auf eigener ClubUnio-DB: accounts.revision, tenant_accounts.id
und revision, neue account_roles samt FK. Bestehende Rollen/Aktivflags/Passwoerter
werden nicht veraendert. Kein erneuter Legacy-Import. Ruecksprung32 kann die
additiven Felder behalten; keine destruktive Schema-Rueckmigration.

Automatisierte Tests: Anlage/Edit/Filter, Hashing und Audit ohne Geheimnisse,
letzter Admin, Entzug wirksam, Office403, fremde Zuordnung, globale Identitaet,
Revisionskonflikte, unerlaubte Rollen/Felder und Passwortbestaetigung.
Visuelle Desktop/Mobilpruefung ohne reale Konten- oder Rechteaenderung.
Deployment/Testabschluss siehe PI.md. API/SaaS/Betrieb bleiben ausgenommen.

Noch offen: Self-Service-Passwortwechsel, sicherer Einladungs-/Reset-Link-Workflow,
MFA sowie ein spaeterer detaillierter Rechtekatalog. Keine bestehende API ersetzt.

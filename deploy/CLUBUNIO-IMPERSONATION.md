# Benutzer simulieren (26.10.2026)

## Umfang

Web-Supportmodus in ClubUnio. Aufruf aus Benutzerverwaltung > Benutzer simulieren
oder `/support/impersonation`. Keine Aenderung der bestehenden App/API-Identitaet.
Kein Benutzer ist automatisch freigeschaltet. Ein Vereinsadmin kann die
persoenliche Freischaltung eines aktiven Vereinsadmins separat setzen/widerrufen;
auch Selbstfreischaltung erfordert das eigene Passwort und eine Begruendung.
Dies ist keine Vier-Augen-Freigabe. Bestehende Rollen bleiben unveraendert.

Start erfordert eigenes Passwort und Supportgrund (10 bis 500 Zeichen).
Zielkonto muss aktiv, nicht technisch und demselben Verein zugeordnet sein.
Admin-Zielkonten, verschachtelte Simulation und Vereinswechsel sind gesperrt.
Laufzeit maximal 15 Minuten, erneute Pruefung von Freischaltung, Aktivitaet,
Rolle und Vereinszuordnung bei jedem geschuetzten Webaufruf.

## Bewusste Grenzen

Erste Version: ausschliesslich Nur-Lesen. Freigegeben sind Dashboard und
ausdruecklich aufgefuehrte Fachlisten/Detailseiten sowie lesende Portalansichten.
Die vorhandenen fachlichen Sichtrechte des Zielkontos gelten unveraendert.
Alle POST/PUT/PATCH/DELETE-Aktionen ausser Simulation beenden sind gesperrt,
einschliesslich Livewire. Deshalb sind auch Suche, Filter, Tabwechsel und
Paginierung mit Livewire in dieser Version nicht bedienbar. Direkte erlaubte
GET-Ansichten und Portal-GET-Filter bleiben nutzbar. Downloads, Integrationen,
Profilverwaltung und Fachprozesse ausserhalb der Allowlist sind gesperrt.
Dies ist noch keine vollstaendige interaktive App-/Backend-Simulation.

Die Identitaet wird nur fuer den aktuellen Request umgestellt, niemals durch
Login als Zielkonto. Die urspruengliche Anmeldung bleibt erhalten. Ein sichtbares
Banner nennt Zielkonto, Ablaufzeit und Beenden. Antworten sind private/no-store.
Ein Ablauf/Widerruf beendet den Modus mit 403; erneutes Laden nutzt wieder das
eigene Konto. Der Beenden-Endpunkt ist auch nach Widerruf erreichbar.

## Nachvollziehbarkeit und Migration

Migration81 legt nur support_grants, support_sessions und support_events im
ClubUnio-Schema an. Keine Rollenkopie, keine Standardfreischaltung, kein
Datenrefresh. Start/Ende, Freischaltung/Widerruf und erlaubte/gesperrte Aufrufe
werden mit Originaladmin, Zielkonto und Verein protokolliert. Keine Passwoerter,
Tokens, Antwortinhalte oder Querystrings im Supportprotokoll. Protokoll ist kein
extern manipulationssicheres Archiv. Kein automatischer Loeschjob eingerichtet.
Rollback des Codes entfernt keine Protokolldaten; Migration-down ist gesperrt.

## Abnahme

ImpersonationTest verwendet ausschliesslich synthetische Konten/Vereine in
Rueckrolltransaktionen und verhindert externe HTTP-Aufrufe. Prueft persoenliche
Freigabe, Passwort, Ziel-/Vereinsgrenze, Sperren, Originalidentitaet, Protokoll,
Beenden, Ablauf/Widerruf, Admin-Demotion, Portalrechte und konkurrierende Freigaben.
PHPMaker DEV/PROD, bestehende Benutzerrechte und Bexio-Buchungen bleiben unberuehrt.

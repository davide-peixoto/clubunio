# Supportartikel und App-Wissensbasis

Stand: 23.11.2026.

## Ursache und Änderung

Auf Stage und PROD war der Support deaktiviert. Alle drei Umgebungen hatten
keine redaktionellen Datenbankartikel; die feste Wissensbasis enthielt nur
Login und Spesen. Die App öffnete zuerst die Ticketliste. Eine Suche ohne
Treffer konnte leer bleiben, und die Wissensbasis hatte keine Wiederholen-Aktion.

Der Support ist jetzt auf DEV, Stage und PROD aktiviert. 18 kuratierte Themen
in Deutsch und Englisch stehen unabhängig von redaktionellen DB-Artikeln bereit:
Login, Spesen, Profil, Mandanten, Chat, Übersetzung, Teamplanung, My Teams,
Mitglieder, Mitgliedschaft, Wallet, Events, Reservierungen, Dokumente,
Schlüsselboxen, Posteingang, Push und Diagnosekontakt. Die Texte erklären auch
Berechtigungen und noch erforderliche Geräte-/Providerkonfigurationen.
Keine fiktiven Tickets oder redaktionellen Artikel wurden angelegt.

Suche berücksichtigt Titel und Inhalt. Veröffentlichungs-, Sprach-, Rollen-
und Mandantengrenzen bleiben wirksam. Mobile Hilfe verwendet die validierte
App-Sprache; Webhilfe weiterhin die Kontosprache. Folgefragen akzeptieren nur
bekannte Themen. Die Triage verwendet das tatsächliche Thema statt eines
Spesen-Fallbacks. Die 16 zusätzlichen Themen erzeugen keine OpenAI-Aufrufe.
Bestehende optionale Login-/Spesen-Zusammenfassung bleibt unverändert und
übermittelt nur öffentliche Hilfetexte, keine Benutzerfragen.

Die App öffnet künftig die Wissensbasis zuerst, zeigt leere Suchergebnisse
verständlich und bietet bei Ladefehlern Wiederholen. Diagnose-/Mailkontakt
bleibt bei verfügbaren Sitzungsdaten auch bei Support-Ladefehlern erreichbar.
Diese nativen Änderungen erfordern einen neuen App-Build; die erweiterten
Serverartikel stehen bereits nach erneutem Laden zur Verfügung.

## Rollout und Prüfung

Migration 2026_11_23_000180 erweitert ausschließlich die erlaubten Triage-Themen.
Auf allen drei Instanzen angewendet; Quellen gezielt mit Live-Hashprüfung und
Sicherung installiert, Konfiguration neu gecacht. Nur SUPPORT_ENABLED wurde
auf true gesetzt. Stage/PROD: Triage, Zammad und unkonfigurierte Support-KI
bleiben ausgeschaltet. Dedizierter DEV-Supportworker neu gestartet und aktiv.

Sicherungen auf dem VPS: /var/backups/clubunio-support-help-db/;
gezielte Quell-/Konfigsicherungen auf dem jeweiligen Host unter
/var/backups/clubunio-support-help-{dev|stage|prod}-<UTC-Zeit>.
Rollback: nur gesicherte betroffene Dateien/Supportfreigabe zurückspielen,
Configcache erneuern und ggf. DEV-Supportworker neu starten. Kein blindes
Zurücksetzen der Themen-Constraint, sobald neue Themen gespeichert wurden.

Validierung: 6 Backendtests mit 120 Assertions; Übersetzungskataloge DE/EN
3075 Schlüssel und Hilfetext-Parität geprüft. Je Umgebung 76 PostgreSQL-
Prüfungen mit vollständigem Rollback: Suche, Rollen-/Mandantenschutz, Locale,
Folgefragen, Triage und keine externe Zustellung/keine bezahlten KI-Aufrufe.
Bestehender Gmail-Zugang liefert auf Stage und PROD jeweils 18 Hilfethemen.
/up jeweils HTTP200, Support-API ohne Anmeldung jeweils HTTP401.
15 Fluttertests erfolgreich; gezielte Analyse sauber; 2172 Texte in fünf
lokalen App-Katalogen vollständig. Echter Geräte-Smoke-Test nach App-Neubau offen.


## Wiki für App und Web – 23.11.2026

25 App- und44 Web-Anleitungen jeweils DE/EN, Schritte/Bilder,138 vertonte
Schrittvideos mit Untertiteln; zusätzlich echte Android-Beispiele für vier Module.
DEV/Stage/PROD mit87 Live-Prüfungen je Umgebung aktualisiert. Native Anzeige
erfordert neuen App-Build. [Inhalt, Medien, Pflege und Abnahme](CLUBUNIO-WIKI.md).

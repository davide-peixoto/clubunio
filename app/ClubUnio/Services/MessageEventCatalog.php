<?php

namespace App\ClubUnio\Services;

/** Event keys and available data are an application contract, not editable routing rules. */
class MessageEventCatalog
{
    public static function all(): array
    {
        $events = [];
        $add = function ($key, $name, $title, $body, $variables, $channels = ['email', 'push']) use (&$events) {
            $events[$key] = compact('name', 'title', 'body', 'variables', 'channels');
        };
        $expense = ['verein', 'mitglied', 'saison', 'runde', 'mannschaft', 'betrag'];
        $add('expense.review', 'Spesen: zur Kontrolle', 'Spesenbeleg zur Kontrolle', 'Ein Spesenbeleg von {{ mitglied }} für {{ saison }}, {{ runde }} ({{ mannschaft }}) wurde zur Kontrolle freigegeben. Betrag: {{ betrag }}.', $expense);
        foreach (['payment_released' => 'zur Zahlung freigegeben', 'paid' => 'bezahlt', 'rejected' => 'abgelehnt', 'reopened' => 'wieder geöffnet'] as $key => $status) {
            $add('expense.'.$key, 'Spesen: '.$status, 'Spesenbeleg '.$status, 'Dein Spesenbeleg für {{ saison }}, {{ runde }} wurde '.$status.'. Betrag: {{ betrag }}.', $expense, $key === 'reopened' ? ['push'] : ['email', 'push']);
        }
        $registration = ['verein', 'mitglied', 'mannschaft'];
        $add('registration.received', 'Anmeldung: Bestätigung', 'Deine Anmeldung bei {{ verein }}', 'Guten Tag {{ mitglied }}\n\nVielen Dank für deine Anmeldung. Wir prüfen deine Angaben und melden uns mit den nächsten Schritten.\n\nFreundliche Grüsse\n{{ verein }}', $registration, ['email']);
        $add('registration.created', 'Anmeldung: eingereicht', 'Neue Mitgliederanmeldung', '{{ mitglied }} hat eine Mitgliederanmeldung eingereicht.', $registration, ['push']);
        $add('registration.review', 'Anmeldung: TL-Prüfung', 'Mitgliederanmeldung zur Prüfung', 'Bitte prüfe die Anmeldung von {{ mitglied }} und ordne eine Mannschaft zu.', $registration, ['push']);
        $add('registration.trial', 'Anmeldung: Probetraining', 'Einladung zum Probetraining', 'Guten Tag {{ mitglied }}\n\nWir laden dich zum Probetraining bei {{ mannschaft }} ein. Das Trainerteam wird dich über die Einzelheiten informieren.\n\nFreundliche Grüsse\n{{ verein }}', $registration, ['email']);
        $add('registration.accepted', 'Anmeldung: angenommen', 'Willkommen bei {{ verein }}', 'Guten Tag {{ mitglied }}\n\nDeine Anmeldung wurde angenommen. Wir freuen uns, dich bei {{ verein }} begrüssen zu dürfen.\n\nFreundliche Grüsse\n{{ verein }}', $registration, ['email']);
        $add('registration.rejected', 'Anmeldung: abgelehnt', 'Deine Anmeldung bei {{ verein }}', 'Guten Tag {{ mitglied }}\n\nLeider können wir deine Anmeldung zurzeit nicht annehmen. Bei Fragen kannst du dich an unsere Geschäftsstelle wenden.\n\nFreundliche Grüsse\n{{ verein }}', $registration, ['email']);
        $add('registration.pool', 'Anmeldung: Warteliste', 'Deine Anmeldung: Warteliste', 'Guten Tag {{ mitglied }}\n\nVielen Dank für dein Interesse an {{ verein }}. Aktuell sind leider keine freien Plätze verfügbar. Wir haben dich auf unsere Warteliste aufgenommen und melden uns, sobald wir dir einen Platz anbieten können.\n\nFreundliche Grüsse\n{{ verein }}', $registration, ['email']);
        foreach (['trial' => 'Probetraining', 'accepted' => 'Akzeptiert', 'rejected' => 'Abgelehnt'] as $key => $status) {
            $add('registration.staff.'.$key, 'Anmeldung: Staff '.$status, 'Mitgliederanmeldung: '.$status, 'Die Anmeldung von {{ mitglied }} für {{ mannschaft }} hat den Status '.$status.'.', $registration, ['push']);
        }
        $add('invoice.open', 'Rechnung: neu offen', 'Eine neue Rechnung wurde erstellt', 'Für dich wurde eine neue Rechnung über {{ betrag }} erstellt.', ['verein', 'mitglied', 'betrag', 'saison'], ['push']);
        $add('invoice.status', 'Rechnung: Statusänderung', 'Rechnung aktualisiert', 'Deine Rechnung hat den Status {{ status }}.', ['verein', 'mitglied', 'betrag', 'saison', 'status'], ['push']);
        $add('pitch.changed', 'Platz: Statusänderung', '{{ platz }}: {{ status }}', '{{ platz }} wurde {{ status }}! Bitte Trainings anpassen.', ['verein', 'platz', 'status'], ['push']);
        $add('bus.requested', 'Bus: neue Anfrage', 'Neue Busreservierungsanfrage', '{{ mitglied }} hat den Bus für {{ mannschaft }} am {{ datum }} angefragt.', ['verein', 'mitglied', 'mannschaft', 'datum'], ['push']);
        $add('bus.approved', 'Bus: bestätigt', 'Busreservierung bestätigt', 'Deine Busreservierung am {{ datum }} für {{ mannschaft }} ist bestätigt.', ['verein', 'mitglied', 'mannschaft', 'datum'], ['push']);
        $add('bus.rejected', 'Bus: abgelehnt', 'Busreservierung abgelehnt', 'Deine Busreservierung am {{ datum }} für {{ mannschaft }} wurde abgelehnt.', ['verein', 'mitglied', 'mannschaft', 'datum'], ['push']);
        $add('bus.changed', 'Bus: umgebucht', 'Busreservierung geändert', 'Die Busreservierung für {{ mannschaft }} wurde geändert. Neuer Beginn: {{ datum }}. Bitte prüfe die Reservation.', ['verein', 'mitglied', 'mannschaft', 'datum'], ['push']);
        $add('bus.cancelled', 'Bus: storniert', 'Busreservierung storniert', 'Die Busreservierung für {{ mannschaft }} am {{ datum }} wurde storniert.', ['verein', 'mitglied', 'mannschaft', 'datum'], ['push']);
        $add('document.created', 'Dokument: neu', 'Neues Dokument', 'Bitte prüfe dein neues Dokument in der App.', ['verein', 'mitglied'], ['push']);
        $add('document.signature', 'Dokument: Unterschrift', 'Dokument zur Unterschrift', 'Bitte prüfe und unterschreibe dein neues Dokument in der App.', ['verein', 'mitglied'], ['push']);
        $add('team.created', 'Teamtermin: neu', 'Neuer Teamtermin', '{{ termin }} am {{ datum }} wurde erstellt.', ['verein', 'termin', 'datum', 'mannschaft'], ['push']);
        $add('team.cancelled', 'Teamtermin: abgesagt', 'Termin abgesagt', '{{ termin }} am {{ datum }} wurde abgesagt.', ['verein', 'termin', 'datum', 'mannschaft'], ['push']);
        $add('team.imported', 'Teamtermin: Verbandsspiel übernommen', 'Verbandsspiel übernommen', 'Ein Verbandsspiel wurde in die Teamplanung übernommen: {{ termin }} am {{ datum }}.', ['verein', 'termin', 'datum', 'mannschaft'], ['push']);
        $add('team.changed', 'Teamtermin: geändert', 'Teamtermin aktualisiert', '{{ termin }} am {{ datum }} wurde aktualisiert.', ['verein', 'termin', 'datum', 'mannschaft'], ['push']);
        $add('team.reminder', 'Teamtermin: Erinnerung', 'Teamtermin Erinnerung', 'Dein Teamtermin steht bevor: {{ termin }} am {{ datum }}.', ['verein', 'termin', 'datum', 'mannschaft'], ['push']);
        $add('team.deadline', 'Teamtermin: Anmeldefrist', 'Anmeldefrist läuft ab', 'Bitte melde dich für {{ termin }} am {{ datum }} an oder ab.', ['verein', 'termin', 'datum', 'mannschaft'], ['push']);
        $add('announcement.manual', 'Allgemeine Ankündigung', '{{ titel }}', '{{ nachricht }}', ['verein', 'titel', 'nachricht']);
        foreach (['register' => 'E-Mail-Adresse bestätigen', 'reset' => 'Passwort zurücksetzen', 'login' => 'Dein Anmeldelink'] as $key => $title) {
            $add('account.'.$key, 'Benutzerkonto: '.$title, $title, 'Guten Tag {{ mitglied }}\n\nBitte öffne diesen Link: {{ link }}\n\nFalls du diese Anfrage nicht gestellt hast, ignoriere diese Nachricht.\n\nFreundliche Grüsse\n{{ verein }}', ['verein', 'mitglied', 'link'], ['email']);
        }
        $add('account.password_changed', 'Benutzerkonto: Passwort geändert', 'Dein Passwort wurde geändert', 'Guten Tag {{ mitglied }}\n\nDas Passwort für dein Benutzerkonto wurde erfolgreich geändert. Falls du diese Änderung nicht selbst vorgenommen hast, kontaktiere bitte umgehend unsere Geschäftsstelle.\n\nFreundliche Grüsse\n{{ verein }}', ['verein', 'mitglied'], ['email']);
        $add('account.otp', 'Benutzerkonto: Bestätigungscode', 'Dein Bestätigungscode', 'Guten Tag {{ mitglied }}\n\nDein Bestätigungscode lautet: {{ code }}\n\nGib diesen Code nicht weiter. Falls du ihn nicht angefordert hast, ignoriere diese Nachricht.\n\nFreundliche Grüsse\n{{ verein }}', ['verein', 'mitglied', 'code'], ['email']);
        foreach ($events as &$event) {
            $event['body'] = str_replace('\\n', "\n", $event['body']);
        }

        return $events;
    }

    public static function get(string $key): array
    {
        return self::all()[$key] ?? throw new \InvalidArgumentException('Unknown message event');
    }

    public static function samples(): array
    {
        return ['verein' => 'Beispielverein', 'mitglied' => 'Alex Beispiel', 'saison' => '2026/2027', 'runde' => 'Vorrunde',
            'mannschaft' => 'Junioren Ba', 'betrag' => 'CHF 85.00', 'status' => 'gesperrt', 'platz' => 'Hauptplatz',
            'datum' => '15.10.2026, 18:00', 'termin' => 'Training', 'titel' => 'Vereinsinformation',
            'nachricht' => 'Am Samstag findet unser Vereinstag statt.', 'link' => 'https://example.invalid/bestaetigen', 'code' => '123456'];
    }
}

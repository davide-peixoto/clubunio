<?php

namespace App\ClubUnio\Services;

/** Stable permission identifiers, never inferred from user input or route names. */
final class PermissionCatalog
{
    public const MODULES = [
        'members' => 'Mitglieder', 'registrations' => 'Mitgliederanmeldungen',
        'member_changes' => 'Mitgliederänderungen', 'documents' => 'Dokumente',
        'families' => 'Familien', 'seasons' => 'Saisons', 'teams' => 'Mannschaften und Zuordnungen',
        'planning' => 'Teamplanung und Absenzen', 'training' => 'KI-Trainingsplanung',
        'training_slots' => 'Trainingszeiten', 'competitions' => 'Wettkämpfe und SFV',
        'invoices' => 'Rechnungen', 'expenses' => 'Spesen', 'contributions' => 'Beiträge',
        'credits' => 'Gutschriften und Rückerstattungen', 'bexio' => 'Bexio',
        'bus' => 'Busverwaltung', 'facilities' => 'Anlagen inklusive Status-Wertetabelle',
        'field_availability' => 'Platzverfügbarkeit und Bemerkungen', 'signage' => 'Signage',
        'announcements' => 'Ankündigungen', 'chat' => 'Chats', 'events' => 'Veranstaltungen',
        'sponsor_runs' => 'Sponsorenlauf',
        'reports' => 'Listen und Auswertungen', 'catalogs' => 'Stammdaten',
        'templates' => 'Nachrichtenvorlagen', 'social_media' => 'Social Media',
    ];

    public const FUNCTIONS = [
        'view' => 'Listen und Details anzeigen',
        'create' => 'Erfassen', 'update' => 'Bearbeiten', 'copy' => 'Kopieren',
        'deactivate' => 'Deaktivieren / Archivieren', 'restore' => 'Wiederherstellen',
        'download' => 'PDF / Anhänge öffnen und herunterladen', 'upload' => 'Anhänge hochladen',
        'export' => 'Exportieren', 'approve' => 'Prüfen / Freigeben', 'send' => 'Versenden / Veröffentlichen',
        'cancel' => 'Stornieren / Zurückziehen', 'sync' => 'Abgleich / Import ausführen',
        'transfer' => 'Saisonübernahme',
        'poll' => 'Umfragen erstellen', 'announce' => 'Vereinsnachrichten veröffentlichen',
        'moderate' => 'Gruppenmeldungen moderieren', 'groups' => 'Alle Mannschafts- und Staffchats anzeigen',
        'schedule' => 'Automatische Beiträge planen',
    ];

    public static function functions(string $module): array
    {
        if ($module === 'chat') {
            return array_intersect_key(self::FUNCTIONS, array_flip(['view', 'send', 'upload', 'download', 'poll', 'announce', 'moderate', 'groups']));
        }
        if ($module === 'field_availability') {
            return array_intersect_key(self::FUNCTIONS, array_flip(['view', 'update']));
        }
        $extra = match ($module) {
            'invoices', 'expenses', 'credits' => ['download', 'upload', 'export', 'approve', 'send', 'cancel', 'sync'],
            'registrations', 'member_changes' => ['download', 'upload', 'approve', 'send'],
            'documents' => ['download', 'upload', 'send', 'cancel', 'sync'],
            'seasons', 'teams' => ['transfer', 'export'],
            'planning', 'training', 'sponsor_runs' => ['download', 'export', 'approve', 'send', 'cancel', 'sync'],
            'competitions', 'bexio' => ['sync', 'download', 'export'],
            'reports' => ['export', 'download'],
            'announcements', 'templates', 'signage' => ['send'],
            'social_media' => ['upload', 'download', 'approve', 'send', 'schedule'],
            'bus' => ['approve', 'cancel', 'export'],
            default => ['export'],
        };
        $keys = $module === 'reports' ? ['view', ...$extra] : ['view', 'create', 'update', 'copy', 'deactivate', 'restore', ...$extra];

        return array_intersect_key(self::FUNCTIONS, array_flip($keys));
    }

    public static function keys(): array
    {
        $keys = [];
        foreach (self::MODULES as $module => $label) {
            foreach (self::functions($module) as $function => $caption) {
                $keys[] = $module.'.'.$function;
            }
        }

        return $keys;
    }

    public static function readOnly(): array
    {
        return array_map(fn ($key) => $key.'.view', array_keys(self::MODULES));
    }
}

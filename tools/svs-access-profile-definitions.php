<?php

// Explicit snapshot: future catalog functions must never grant rights implicitly.
$edit = ['view', 'create', 'update', 'copy', 'deactivate', 'restore'];
$read = ['members' => ['view'], 'teams' => ['view'], 'seasons' => ['view']];
$finance = $read + [
    'invoices' => [...$edit, 'download', 'upload', 'export'],
    'expenses' => [...$edit, 'download', 'upload', 'export'],
    'contributions' => [...$edit, 'export'],
    'credits' => [...$edit, 'download', 'upload', 'export'],
];
$responsible = $finance;
foreach (['invoices', 'expenses', 'credits'] as $module) {
    $responsible[$module] = [...$responsible[$module], 'approve', 'send', 'cancel', 'sync'];
}
$responsible['bexio'] = ['view', 'sync'];
$profiles = [
    'Geschäftsstelle' => [
        'description' => 'Vereinsweite Verwaltung und Mitgliederentscheide; Finanzbearbeitung ohne Finanzfreigabe, Versand, Stornierung oder Bexio-Abgleich. Keine Benutzer- oder Systemverwaltung.',
        'modules' => [
            'members' => [...$edit, 'export'], 'families' => [...$edit, 'export'],
            'registrations' => [...$edit, 'download', 'upload', 'approve', 'send'],
            'member_changes' => [...$edit, 'download', 'upload', 'approve', 'send'],
            'documents' => [...$edit, 'download', 'upload'],
            'teams' => [...$edit, 'export'], 'seasons' => ['view'],
            'invoices' => $finance['invoices'], 'expenses' => $finance['expenses'],
            'announcements' => [...$edit, 'send'], 'events' => [...$edit, 'export'],
            'templates' => ['view', 'update'],
        ],
    ],
    'Geschäftsstelle nur lesen' => [
        'description' => 'Alle derzeitigen Fachmodule ansehen, keine Änderungen, Downloads, Exporte oder Fachaktionen. Keine Benutzer- oder Systemverwaltung.',
        'modules' => array_fill_keys(['members', 'registrations', 'member_changes', 'documents', 'families', 'seasons', 'teams', 'planning', 'training', 'training_slots', 'competitions', 'invoices', 'expenses', 'contributions', 'credits', 'bexio', 'bus', 'facilities', 'signage', 'announcements', 'events', 'reports', 'catalogs', 'templates'], ['view']),
    ],
    'Finanzen bearbeiten' => [
        'description' => 'Finanzbelege, Beiträge und Gutschriften bearbeiten; Finanzdateien und Exporte. Keine Freigabe, Stornierung, Zustellung oder Synchronisation.',
        'modules' => $finance,
    ],
    'Finanzen verantworten' => [
        'description' => 'Finanzbearbeitung inklusive Freigabe, Versand, Stornierung und Abgleich. Bestehende Sandbox- und Zahlungssperren bleiben bestehen; keine Verbindungsgeheimnisse oder Benutzerverwaltung.',
        'modules' => $responsible,
    ],
    'Sportadministration' => [
        'description' => 'Vereinsweite Sportverwaltung, nicht auf einzelne Mannschaften begrenzt. Kein Ersatz für die bestehende TL-/Trainerrolle. Mitglieder nur lesen, keine Finanzen.',
        'modules' => [
            'members' => ['view'], 'seasons' => [...$edit, 'transfer', 'export'],
            'teams' => [...$edit, 'transfer', 'export'], 'training_slots' => [...$edit, 'export'],
            'planning' => [...$edit, 'download', 'export', 'approve', 'send', 'cancel', 'sync'],
        ],
    ],
    'Anlagen & Signage' => [
        'description' => 'Wettkämpfe/Garderoben, Plätze, Busentscheide und Signage verwalten. Mannschaften/Saisons lesen; keine Mitglieder-, Finanz- oder Rundnachrichtenverwaltung.',
        'modules' => [
            'teams' => ['view'], 'seasons' => ['view'],
            'competitions' => [...$edit, 'sync'], 'facilities' => $edit,
            'bus' => [...$edit, 'approve', 'cancel'], 'signage' => [...$edit, 'send'],
        ],
    ],
    'Platzwart' => [
        'description' => 'Nur Verfügbarkeit und Bemerkungen bestehender Plätze ändern. Keine Status-Wertetabelle, Platzumordnung, Aktivierung/Deaktivierung, Anlage, Exporte oder anderen Fachrechte.',
        'modules' => ['field_availability' => ['view', 'update']],
    ],
];
foreach ($profiles as &$profile) {
    $profile['permissions'] = [];
    foreach ($profile['modules'] as $module => $functions) {
        foreach ($functions as $function) {
            $profile['permissions'][] = $module.'.'.$function;
        }
    }
    unset($profile['modules']);
}
unset($profile);
return $profiles;

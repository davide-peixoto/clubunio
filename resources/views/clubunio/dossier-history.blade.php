<div class="cu-dossier-history">
    @php
        $labels = ['active' => __('Aktiv'), 'revision' => __('Version'), 'name' => __('Name'), 'number' => __('Nummer'),
            'color' => __('Farbe'), 'body' => __('Inhalt'), 'notes' => __('Bemerkungen'), 'amount' => __('Betrag'),
            'expires_on' => __('Gültig bis'), 'description' => __('Bezeichnung'), 'gross_amount' => __('Bruttobetrag'),
            'discount' => __('Rabatt'), 'net_amount' => __('Endbetrag'), 'status_id' => __('Status-ID'),
            'workflow_status_id' => __('Bearbeitungsstatus-ID'), 'member_id' => __('Mitglied-ID'),
            'family_id' => __('Familie-ID'), 'team_id' => __('Mannschaft-ID'), 'season_id' => __('Saison-ID'),
            'team_season_id' => __('Mannschaft/Saison-ID'), 'staff_function_id' => __('Stafffunktion-ID'),
            'club_function_id' => __('Vereinsfunktion-ID'), 'club_event_id' => __('Veranstaltung-ID'),
            'qualification_ids' => __('Qualifikationen'), 'bank_id' => __('Bank-ID'), 'iban_last4' => __('IBAN-Endziffern'),
            'portal_visible' => __('Im Portal sichtbar'), 'document_type_id' => __('Dokumenttyp-ID'),
            'expense_type_id' => __('Spesentyp-ID'), 'game_type_id' => __('Spieltyp-ID'), 'opponent' => __('Gegner'),
            'suggested_amount' => __('Vorschlagsbetrag'), 'suggestion_accepted' => __('Vorschlag übernommen')];
    @endphp
    <style>
        .cu-dossier-history { display:grid; gap:16px; }
        .cu-dossier-history section { padding-bottom:12px; border-bottom:1px solid #d1d5db; }
        .cu-dossier-history h3, .cu-dossier-history dt { font-weight:600; }
        .cu-dossier-history dl { display:grid; gap:8px; margin-top:8px; font-size:14px; }
        .cu-dossier-history dd { overflow-wrap:anywhere; }
    </style>
    <p class="text-sm text-gray-500">{{ __('Letzte 100 protokollierte Änderungen. Importierte Altbestände können ohne Verlauf sein.') }}</p>
    @forelse ($entries as $entry)
        <section class="border-b border-gray-200 pb-3">
            <h3 class="font-semibold">{{ $entry['at'] }} · {{ $entry['actor'] }}</h3>
            <dl class="mt-2 space-y-2 text-sm">
            @forelse ($entry['changes'] as $change)
                <div class="break-words">
                    <dt class="font-medium">{{ $labels[$change['field']] ?? str($change['field'])->replace('_', ' ')->ucfirst() }}</dt>
                    <dd>{{ is_bool($change['before']) ? ($change['before'] ? __('Ja') : __('Nein')) : (is_array($change['before']) ? implode(', ', $change['before']) : ($change['before'] ?? '–')) }} → {{ is_bool($change['after']) ? ($change['after'] ? __('Ja') : __('Nein')) : (is_array($change['after']) ? implode(', ', $change['after']) : ($change['after'] ?? '–')) }}</dd>
                </div>
            @empty
                <dd>{{ __('Keine freigegebenen Felddetails für dieses Ereignis.') }}</dd>
            @endforelse
            </dl>
        </section>
    @empty
        <p>{{ __('Keine protokollierten Änderungen vorhanden.') }}</p>
    @endforelse
</div>

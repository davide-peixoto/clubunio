<div class="cu-dossier-history">
    @php
        $labels = ['active' => 'Aktiv', 'revision' => 'Version', 'name' => 'Name', 'number' => 'Nummer',
            'color' => 'Farbe', 'body' => 'Inhalt', 'notes' => 'Bemerkungen', 'amount' => 'Betrag',
            'expires_on' => 'Gültig bis', 'description' => 'Bezeichnung', 'gross_amount' => 'Bruttobetrag',
            'discount' => 'Rabatt', 'net_amount' => 'Endbetrag', 'status_id' => 'Status-ID',
            'workflow_status_id' => 'Bearbeitungsstatus-ID', 'member_id' => 'Mitglied-ID',
            'family_id' => 'Familie-ID', 'team_id' => 'Mannschaft-ID', 'season_id' => 'Saison-ID',
            'team_season_id' => 'Mannschaft/Saison-ID', 'staff_function_id' => 'Stafffunktion-ID',
            'club_function_id' => 'Vereinsfunktion-ID', 'club_event_id' => 'Veranstaltung-ID',
            'qualification_ids' => 'Qualifikationen', 'bank_id' => 'Bank-ID', 'iban_last4' => 'IBAN-Endziffern',
            'portal_visible' => 'Im Portal sichtbar', 'document_type_id' => 'Dokumenttyp-ID',
            'expense_type_id' => 'Spesentyp-ID', 'game_type_id' => 'Spieltyp-ID', 'opponent' => 'Gegner',
            'suggested_amount' => 'Vorschlagsbetrag', 'suggestion_accepted' => 'Vorschlag übernommen'];
    @endphp
    <style>
        .cu-dossier-history { display:grid; gap:16px; }
        .cu-dossier-history section { padding-bottom:12px; border-bottom:1px solid #d1d5db; }
        .cu-dossier-history h3, .cu-dossier-history dt { font-weight:600; }
        .cu-dossier-history dl { display:grid; gap:8px; margin-top:8px; font-size:14px; }
        .cu-dossier-history dd { overflow-wrap:anywhere; }
    </style>
    <p class="text-sm text-gray-500">Letzte 100 protokollierte Änderungen. Importierte Altbestände können ohne Verlauf sein.</p>
    @forelse ($entries as $entry)
        <section class="border-b border-gray-200 pb-3">
            <h3 class="font-semibold">{{ $entry['at'] }} · {{ $entry['actor'] }}</h3>
            <dl class="mt-2 space-y-2 text-sm">
            @forelse ($entry['changes'] as $change)
                <div class="break-words">
                    <dt class="font-medium">{{ $labels[$change['field']] ?? str($change['field'])->replace('_', ' ')->ucfirst() }}</dt>
                    <dd>{{ is_bool($change['before']) ? ($change['before'] ? 'Ja' : 'Nein') : (is_array($change['before']) ? implode(', ', $change['before']) : ($change['before'] ?? '–')) }} → {{ is_bool($change['after']) ? ($change['after'] ? 'Ja' : 'Nein') : (is_array($change['after']) ? implode(', ', $change['after']) : ($change['after'] ?? '–')) }}</dd>
                </div>
            @empty
                <dd>Keine freigegebenen Felddetails für dieses Ereignis.</dd>
            @endforelse
            </dl>
        </section>
    @empty
        <p>Keine protokollierten Änderungen vorhanden.</p>
    @endforelse
</div>

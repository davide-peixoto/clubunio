<x-filament-panels::page>
    <style>
        .cu-sync-filters { display:flex; flex-wrap:wrap; align-items:end; gap:12px; }
        .cu-sync-filters > label { display:block; width:240px; max-width:100%; }
        .cu-sync-scroll { width:100%; overflow-x:auto; margin-top:20px; }
        .cu-sync-table { width:100%; min-width:700px; border-collapse:collapse; font-size:14px; }
        .cu-sync-table th,.cu-sync-table td { padding:12px; border-bottom:1px solid #d1d5db; text-align:left; vertical-align:top; overflow-wrap:break-word; }
        .cu-sync-table th:nth-child(1) { width:30%; }
        .cu-sync-table th:nth-child(2) { width:30%; }
        .cu-sync-table th:nth-child(3) { width:18%; }
        .cu-sync-table th:nth-child(4) { width:22%; }
        .cu-sync-table small { font-size:12px; }
        .cu-sync-table a { text-decoration:underline; }
        .cu-sync-table details { margin-top:8px; }
        .cu-sync-table details p { margin-top:8px; }
        .cu-sync-table details label { display:block; margin:8px 0; }
        @media(max-width:600px) { .cu-sync-filters > label { width:100%; } }
    </style>
    <div class="cu-sync-filters">
        <label>Status
            <x-filament::input.wrapper><x-filament::input.select wire:model.live="state">
                <option value="open">Offen ({{ ($counts['pending'] ?? 0) + ($counts['attention'] ?? 0) + ($counts['running'] ?? 0) }})</option>
                <option value="succeeded">Erfolgreich ({{ $counts['succeeded'] ?? 0 }})</option>
                <option value="closed">Manuell abgeschlossen ({{ $counts['closed'] ?? 0 }})</option>
                <option value="all">Alle</option>
            </x-filament::input.select></x-filament::input.wrapper>
        </label>
        <label>Verbindung
            <x-filament::input.wrapper><x-filament::input.select wire:model.live="provider">
                <option value="all">Alle</option><option value="bexio">Bexio</option><option value="sfv">SFV</option><option value="ai">KI-Training</option>
            </x-filament::input.select></x-filament::input.wrapper>
        </label>
        <x-filament::button wire:click="collectOutstanding" wire:loading.attr="disabled" icon="heroicon-o-arrow-down-tray" color="gray">Offene Protokolle übernehmen</x-filament::button>
        <x-filament::button tag="a" href="{{ \App\ClubUnio\Pages\BexioReconciliation::getUrl() }}" icon="heroicon-o-arrow-path" color="gray">Bexio-Abgleich</x-filament::button>
    </div>
    <div class="cu-sync-scroll">
        <table class="cu-sync-table">
            <thead><tr class="border-b text-left"><th class="p-3">Auftrag</th><th class="p-3">Status</th><th class="p-3">Letzter Versuch</th><th class="p-3">Aktion</th></tr></thead>
            <tbody>@forelse($rows as $row)
                <tr class="border-b align-top">
                    <td class="p-3">{{ $labels[$row->operation] ?? $row->operation }}<br><small>Objekt {{ $row->object_id }} · Auftrag {{ $row->id }}</small>
                        @if($row->context)<br>{{ $row->context }}@endif
                        @if(in_array($row->operation, ['invoice_transfer','invoice_action','invoice_status','invoice_sync','invoice_result']))
                            <br><a class="underline" href="{{ \App\ClubUnio\Resources\InvoiceResource::getUrl('view',['record'=>$row->object_id]) }}">Rechnung öffnen</a>
                        @elseif(in_array($row->operation, ['expense_status','expense_sync']))
                            <br><a class="underline" href="{{ \App\ClubUnio\Resources\ExpenseResource::getUrl('view',['record'=>$row->object_id]) }}">Spesen öffnen</a>
                        @endif
                    </td>
                    <td class="p-3">{{ ['pending'=>'Bereit','running'=>'In Bearbeitung','attention'=>'Prüfung erforderlich','succeeded'=>'Erfolgreich','closed'=>'Manuell abgeschlossen'][$row->state] }}
                        @if($row->error_code)<br><small>{{ \App\ClubUnio\Services\SyncWorkService::ERRORS[$row->error_code] ?? 'Ausführung prüfen' }}</small>@endif
                        <details><summary>Verlauf ({{ $row->attempts }} Versuche)</summary>
                            @foreach($events[$row->id] ?? [] as $event)
                                <p>{{ \Carbon\CarbonImmutable::parse($event->created_at)->tz('Europe/Zurich')->format('d.m.Y H:i:s') }} ·
                                    {{ ['recorded'=>'Auftrag gespeichert','started'=>'Versuch gestartet','succeeded'=>'Erfolgreich','attention'=>'Prüfung erforderlich','closed_manually'=>'Manuell abgeschlossen'][$event->event] ?? $event->event }}
                                    · Benutzer {{ $event->actor_id }}
                                    @if($event->code) · {{ $event->code }} @endif
                                    @if($event->note)<br>{{ $event->note }}@endif
                                </p>
                            @endforeach
                        </details>
                    </td>
                    <td class="p-3">{{ \Carbon\CarbonImmutable::parse($row->updated_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</td>
                    <td class="p-3">
                        @if($row->operation !== 'training_review' && (in_array($row->state, ['pending','attention']) || ($row->state === 'running' && (!$row->lease_until || now()->isAfter($row->lease_until)))))
                            <x-filament::button wire:click="retry({{ $row->id }}, {{ $row->revision }})" wire:confirm="Diesen Auftrag mit den bestehenden Zuordnungen erneut prüfen beziehungsweise ausführen? Unklare Bexio-Anlagen werden nur abgeglichen." wire:loading.attr="disabled" icon="heroicon-o-arrow-path">Erneut prüfen</x-filament::button>
                        @endif
                        @if(in_array($row->state,['pending','attention']))
                            <details class="mt-2"><summary>Manuell abschliessen</summary>
                                <label>Begründung<x-filament::input.wrapper><x-filament::input wire:model="note" maxlength="1000" /></x-filament::input.wrapper></label>
                                <x-filament::button wire:click="closeItem({{ $row->id }}, {{ $row->revision }})" wire:confirm="Nur den Prüfauftrag abschliessen? Dies bestätigt keine Synchronisation und ändert keine Quelldaten." color="gray" icon="heroicon-o-check">Abschliessen</x-filament::button>
                            </details>
                        @endif
                    </td>
                </tr>
            @empty<tr><td class="p-3" colspan="4">Keine Aufträge in dieser Auswahl.</td></tr>@endforelse</tbody>
        </table>
    </div>
    {{ $rows->links() }}
</x-filament-panels::page>

<x-filament-panels::page>
    <style>
        .dispatch-scroll { overflow-x:auto; }
        .dispatch-table { width:100%; min-width:720px; border-collapse:collapse; text-align:left; font-size:14px; }
        .dispatch-table th,.dispatch-table td { padding:14px 12px; text-align:left; vertical-align:top; border-bottom:1px solid #d1d5db; }
        .dispatch-table small { display:inline-block; margin-top:4px; }
        .dispatch-table td:last-child { min-width:200px; }
        .dispatch-filters { display:flex; flex-wrap:wrap; gap:16px; }
        .dispatch-filters label { flex:1 1 240px; min-width:0; }
    </style>
    <p role="status"><strong>{{ $sandboxAdmin ? 'Sandbox-Testbetrieb · Keine Produktivübertragung' : 'Simulation · Echtversand gesperrt' }}</strong></p>
    @if($documentTypes)
    <div class="dispatch-filters">
        <label>Dokument<x-filament::input.wrapper><x-filament::input.select wire:model.live="type">@foreach($documentTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
        <label>Mitglied<x-filament::input.wrapper><x-filament::input wire:model.live.debounce.400ms="search" maxlength="100" /></x-filament::input.wrapper></label>
    </div>
    @endif
    <div class="dispatch-scroll">
        <table class="dispatch-table"><thead><tr><th class="p-3">Dokument</th><th class="p-3">Mitglied</th><th class="p-3">Prüfung</th></tr></thead><tbody>
            @forelse($documents as $document)
            <tr class="border-b"><td class="p-3">{{ $document->legacy_id ?? $document->id }}</td><td class="p-3">{{ $document->member?->display_name }}</td><td class="p-3">@if(\App\ClubUnio\Pages\BexioDispatchTrials::canDocumentOperation($type, 'send'))<x-filament::button wire:click="check({{ $document->id }})" wire:loading.attr="disabled" icon="heroicon-o-magnifying-glass">Prüfen</x-filament::button>@endif</td></tr>
            @empty<tr><td colspan="3" class="p-3">Keine freigegebenen, noch nicht verknüpften Dokumente.</td></tr>@endforelse
        </tbody></table>
    </div>
    <x-filament::pagination :paginator="$documents" />
    <h2>Meine Versandprüfungen</h2>
    @if($trials->contains(fn ($trial) => !isset($sandboxTrials[$trial->id]) && in_array($trial->state, ['uncertain', 'rejected', 'running'], true) && \App\ClubUnio\Pages\BexioDispatchTrials::canDocumentOperation($trial->object_type, 'sync')))
    <label>Simuliertes Abgleichsergebnis<x-filament::input.wrapper><x-filament::input.select wire:model="resolution"><option value="not_created">Nicht angelegt; Testwiederholung erlauben</option><option value="found">Dokument gefunden; Test abschliessen</option></x-filament::input.select></x-filament::input.wrapper></label>
    @endif
    @if($trials->contains(fn ($trial) => !isset($sandboxTrials[$trial->id]) && $trial->state === 'ready' && \App\ClubUnio\Pages\BexioDispatchTrials::canDocumentOperation($trial->object_type, 'send')))
    <label>Simulierte Antwort<x-filament::input.wrapper><x-filament::input.select wire:model="outcome"><option value="simulated">Annahme</option><option value="rejected">Ablehnung</option><option value="uncertain">Unklarer Ausgang / Timeout</option></x-filament::input.select></x-filament::input.wrapper></label>
    @endif
    <div class="dispatch-scroll"><table class="dispatch-table"><thead><tr><th class="p-3">Dokument</th><th class="p-3">Positionen</th><th class="p-3">Betrag CHF</th><th class="p-3">Anhänge geprüft</th><th class="p-3">Ergebnis</th><th class="p-3">Übertragung / Testlauf</th></tr></thead><tbody>
        @forelse($trials as $trial)
        <tr class="border-b"><td class="p-3">{{ $trial->object_type === 'invoice' ? 'Rechnung' : 'Spesen' }} #{{ $trial->object_id }}<br><small>{{ \Carbon\CarbonImmutable::parse($trial->created_at)->setTimezone('Europe/Zurich')->format('d.m.Y H:i') }}</small></td><td class="p-3">{{ $trial->line_count }}</td><td class="p-3">{{ number_format($trial->total_cents / 100, 2, '.', "'") }}</td><td class="p-3">@if($trial->object_type !== 'expense') – @elseif($trial->attachment_count === null) Nicht geprüft @else {{ $trial->attachment_count }}<br><small>{{ number_format($trial->attachment_bytes / 1024, 1, '.', "'") }} KiB</small> @endif</td><td class="p-3">{{ ['ready'=>'Geprüft','running'=>'Testlauf läuft','simulated'=>'Annahme simuliert','rejected'=>'Ablehnung simuliert','uncertain'=>'Unklar, Wiederholung gesperrt'][$trial->state] }}
        @if(($steps[$trial->id] ?? collect())->isNotEmpty())<details><summary>Ablauf</summary><ul>@foreach($steps[$trial->id] as $step)<li>{{ ['upload'=>'Anhang','create'=>'Dokumentanlage','resolve'=>'Testklärung'][$step->operation] }}: {{ ['started'=>'Gestartet','accepted'=>'Angenommen','rejected'=>'Abgelehnt','uncertain'=>'Unklar','reset'=>'Wiederholung freigegeben'][$step->state] }}@if($step->http_status) ({{ $step->http_status }})@endif</li>@endforeach</ul></details>@endif
        </td><td class="p-3">
        @if($sandboxAdmin && $trial->state === 'ready' && \App\ClubUnio\Pages\BexioDispatchTrials::canDocumentOperation($trial->object_type, 'send'))
            @if(isset($sandboxTrials[$trial->id]))
                <a href="{{ \App\ClubUnio\Pages\BexioSandbox::getUrl() }}">Sandbox: {{ ['ready'=>'Bereit','running'=>'Gestartet','accepted'=>'Übertragen und geprüft','rejected'=>'Abgelehnt','uncertain'=>'Unklar'][$sandboxTrials[$trial->id]] }}</a>
            @else
                <x-filament::button wire:click="sendSandbox({{ $trial->id }})" wire:confirm="Diesen synthetischen Testbeleg wirklich in SVS Api Test anlegen? Keine Zahlung und kein E-Mail-Versand." wire:loading.attr="disabled" icon="heroicon-o-paper-airplane">In Sandbox übertragen</x-filament::button>
            @endif
        @endif
        @unless(isset($sandboxTrials[$trial->id]))
        @if($trial->state === 'ready' && \App\ClubUnio\Pages\BexioDispatchTrials::canDocumentOperation($trial->object_type, 'send'))<x-filament::button wire:click="simulate({{ $trial->id }})" wire:confirm="Testlauf ohne Bexio-Versand bestätigen?" wire:loading.attr="disabled" icon="heroicon-o-beaker">Simulieren</x-filament::button>@elseif(in_array($trial->state, ['uncertain','rejected','running']) && \App\ClubUnio\Pages\BexioDispatchTrials::canDocumentOperation($trial->object_type, 'sync'))<x-filament::button wire:click="resolve({{ $trial->id }})" wire:confirm="Nur den simulierten Testlauf mit dem gewählten Ergebnis klären?" wire:loading.attr="disabled" icon="heroicon-o-arrow-path">Testlauf klären</x-filament::button>@else – @endif
        @endunless
        </td></tr>
        @empty<tr><td colspan="6" class="p-3">Noch keine Versandprüfungen.</td></tr>@endforelse
    </tbody></table></div>
</x-filament-panels::page>

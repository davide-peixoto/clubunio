<x-filament-panels::page>
    <style>
        .dispatch-scroll { overflow-x:auto; }
        .dispatch-table { min-width:720px; }
        .dispatch-table small { display:inline-block; margin-top:4px; }
        .dispatch-table td:last-child { min-width:200px; }
        .dispatch-filters { display:flex; flex-wrap:wrap; gap:16px; }
        .dispatch-filters label { flex:1 1 240px; min-width:0; }
    </style>
    <p role="status"><strong>{{ $sandboxAdmin ? 'Sandbox-Testbetrieb · Keine Produktivübertragung' : 'Simulation · Echtversand gesperrt' }}</strong></p>
    @if($documentTypes)
    <div class="dispatch-filters">
        <label>Dokument<x-filament::input.wrapper><x-filament::input.select wire:model.live="type">@foreach($documentTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
    </div>
    @endif
    {{ $this->table }}
    <x-filament::section heading="Meine Versandprüfungen (max. 30)">
    @if($trials->contains(fn ($trial) => !isset($sandboxTrials[$trial->id]) && in_array($trial->state, ['uncertain', 'rejected', 'running'], true) && \App\ClubUnio\Pages\BexioDispatchTrials::canDocumentOperation($trial->object_type, 'sync')))
    <label>Simuliertes Abgleichsergebnis<x-filament::input.wrapper><x-filament::input.select wire:model="resolution"><option value="not_created">Nicht angelegt; Testwiederholung erlauben</option><option value="found">Dokument gefunden; Test abschliessen</option></x-filament::input.select></x-filament::input.wrapper></label>
    @endif
    @if($trials->contains(fn ($trial) => !isset($sandboxTrials[$trial->id]) && $trial->state === 'ready' && \App\ClubUnio\Pages\BexioDispatchTrials::canDocumentOperation($trial->object_type, 'send')))
    <label>Simulierte Antwort<x-filament::input.wrapper><x-filament::input.select wire:model="outcome"><option value="simulated">Annahme</option><option value="rejected">Ablehnung</option><option value="uncertain">Unklarer Ausgang / Timeout</option></x-filament::input.select></x-filament::input.wrapper></label>
    @endif
    <div class="dispatch-scroll"><table class="cu-admin-journal dispatch-table"><thead><tr><th>Dokument</th><th>Positionen</th><th>Betrag CHF</th><th>Anhänge geprüft</th><th>Ergebnis</th><th>Übertragung / Testlauf</th></tr></thead><tbody>
        @forelse($trials as $trial)
        <tr><td>{{ $trial->object_type === 'invoice' ? 'Rechnung' : 'Spesen' }} #{{ $trial->object_id }}<br><small>{{ \Carbon\CarbonImmutable::parse($trial->created_at)->setTimezone('Europe/Zurich')->format('d.m.Y H:i') }}</small></td><td>{{ $trial->line_count }}</td><td>{{ number_format($trial->total_cents / 100, 2, '.', "'") }}</td><td>@if($trial->object_type !== 'expense') – @elseif($trial->attachment_count === null) Nicht geprüft @else {{ $trial->attachment_count }}<br><small>{{ number_format($trial->attachment_bytes / 1024, 1, '.', "'") }} KiB</small> @endif</td><td>{{ ['ready'=>'Geprüft','running'=>'Testlauf läuft','simulated'=>'Annahme simuliert','rejected'=>'Ablehnung simuliert','uncertain'=>'Unklar, Wiederholung gesperrt'][$trial->state] }}
        @if(($steps[$trial->id] ?? collect())->isNotEmpty())<details><summary>Ablauf</summary><ul>@foreach($steps[$trial->id] as $step)<li>{{ ['upload'=>'Anhang','create'=>'Dokumentanlage','resolve'=>'Testklärung'][$step->operation] }}: {{ ['started'=>'Gestartet','accepted'=>'Angenommen','rejected'=>'Abgelehnt','uncertain'=>'Unklar','reset'=>'Wiederholung freigegeben'][$step->state] }}@if($step->http_status) ({{ $step->http_status }})@endif</li>@endforeach</ul></details>@endif
        </td><td>
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
        @empty<tr><td colspan="6">Noch keine Versandprüfungen.</td></tr>@endforelse
    </tbody></table></div>
    </x-filament::section>
</x-filament-panels::page>

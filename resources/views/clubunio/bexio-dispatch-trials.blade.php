<x-filament-panels::page>
    <style>
        .dispatch-scroll { overflow-x:auto; }
        .dispatch-table { min-width:720px; }
        .dispatch-table small { display:inline-block; margin-top:4px; }
        .dispatch-table td:last-child { min-width:200px; }
        .dispatch-filters { display:flex; flex-wrap:wrap; gap:16px; }
        .dispatch-filters label { flex:1 1 240px; min-width:0; }
    </style>
    <p role="status"><strong>{{ $sandboxAdmin ? __('Sandbox-Testbetrieb · Keine Produktivübertragung') : __('Simulation · Echtversand gesperrt') }}</strong></p>
    @if($documentTypes)
    <div class="dispatch-filters">
        <label>{{ __('Dokument') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="type">@foreach($documentTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
    </div>
    @endif
    {{ $this->table }}
    <x-filament::section heading="{{ __('Meine Versandprüfungen (max. 30)') }}">
    @if($trials->contains(fn ($trial) => !isset($sandboxTrials[$trial->id]) && in_array($trial->state, ['uncertain', 'rejected', 'running'], true) && \App\ClubUnio\Pages\BexioDispatchTrials::canDocumentOperation($trial->object_type, 'sync')))
    <label>{{ __('Simuliertes Abgleichsergebnis') }}<x-filament::input.wrapper><x-filament::input.select wire:model="resolution"><option value="not_created">{{ __('Nicht angelegt; Testwiederholung erlauben') }}</option><option value="found">{{ __('Dokument gefunden; Test abschliessen') }}</option></x-filament::input.select></x-filament::input.wrapper></label>
    @endif
    @if($trials->contains(fn ($trial) => !isset($sandboxTrials[$trial->id]) && $trial->state === 'ready' && \App\ClubUnio\Pages\BexioDispatchTrials::canDocumentOperation($trial->object_type, 'send')))
    <label>{{ __('Simulierte Antwort') }}<x-filament::input.wrapper><x-filament::input.select wire:model="outcome"><option value="simulated">{{ __('Annahme') }}</option><option value="rejected">{{ __('Ablehnung') }}</option><option value="uncertain">{{ __('Unklarer Ausgang / Timeout') }}</option></x-filament::input.select></x-filament::input.wrapper></label>
    @endif
    <div class="dispatch-scroll"><table class="cu-admin-journal dispatch-table"><thead><tr><th>{{ __('Dokument') }}</th><th>{{ __('Positionen') }}</th><th>{{ __('Betrag CHF') }}</th><th>{{ __('Anhänge geprüft') }}</th><th>{{ __('Ergebnis') }}</th><th>{{ __('Übertragung / Testlauf') }}</th></tr></thead><tbody>
        @forelse($trials as $trial)
        <tr><td>{{ $trial->object_type === 'invoice' ? __('Rechnung') : __('Spesen') }} #{{ $trial->object_id }}<br><small>{{ \Carbon\CarbonImmutable::parse($trial->created_at)->setTimezone('Europe/Zurich')->format('d.m.Y H:i') }}</small></td><td>{{ $trial->line_count }}</td><td>{{ number_format($trial->total_cents / 100, 2, '.', "'") }}</td><td>@if($trial->object_type !== 'expense') – @elseif($trial->attachment_count === null) {{ __('Nicht geprüft') }} @else {{ $trial->attachment_count }}<br><small>{{ number_format($trial->attachment_bytes / 1024, 1, '.', "'") }} {{ __('KiB') }}</small> @endif</td><td>{{ ['ready'=>__('Geprüft'),'running'=>__('Testlauf läuft'),'simulated'=>__('Annahme simuliert'),'rejected'=>__('Ablehnung simuliert'),'uncertain'=>__('Unklar, Wiederholung gesperrt')][$trial->state] }}
        @if(($steps[$trial->id] ?? collect())->isNotEmpty())<details><summary>{{ __('Ablauf') }}</summary><ul>@foreach($steps[$trial->id] as $step)<li>{{ ['upload'=>__('Anhang'),'create'=>__('Dokumentanlage'),'resolve'=>__('Testklärung')][$step->operation] }}: {{ ['started'=>__('Gestartet'),'accepted'=>__('Angenommen'),'rejected'=>__('Abgelehnt'),'uncertain'=>__('Unklar'),'reset'=>__('Wiederholung freigegeben')][$step->state] }}@if($step->http_status) ({{ $step->http_status }})@endif</li>@endforeach</ul></details>@endif
        </td><td>
        @if($sandboxAdmin && $trial->state === 'ready' && \App\ClubUnio\Pages\BexioDispatchTrials::canDocumentOperation($trial->object_type, 'send'))
            @if(isset($sandboxTrials[$trial->id]))
                <a href="{{ \App\ClubUnio\Pages\BexioSandbox::getUrl() }}">{{ __('Sandbox:') }} {{ ['ready'=>__('Bereit'),'running'=>__('Gestartet'),'accepted'=>__('Übertragen und geprüft'),'rejected'=>__('Abgelehnt'),'uncertain'=>__('Unklar')][$sandboxTrials[$trial->id]] }}</a>
            @else
                <x-filament::button wire:click="sendSandbox({{ $trial->id }})" wire:confirm="{{ __('Diesen synthetischen Testbeleg wirklich in SVS Api Test anlegen? Keine Zahlung und kein E-Mail-Versand.') }}" wire:loading.attr="disabled" icon="heroicon-o-paper-airplane">{{ __('In Sandbox übertragen') }}</x-filament::button>
            @endif
        @endif
        @unless(isset($sandboxTrials[$trial->id]))
        @if($trial->state === 'ready' && \App\ClubUnio\Pages\BexioDispatchTrials::canDocumentOperation($trial->object_type, 'send'))<x-filament::button wire:click="simulate({{ $trial->id }})" wire:confirm="{{ __('Testlauf ohne Bexio-Versand bestätigen?') }}" wire:loading.attr="disabled" icon="heroicon-o-beaker">{{ __('Simulieren') }}</x-filament::button>@elseif(in_array($trial->state, ['uncertain','rejected','running']) && \App\ClubUnio\Pages\BexioDispatchTrials::canDocumentOperation($trial->object_type, 'sync'))<x-filament::button wire:click="resolve({{ $trial->id }})" wire:confirm="{{ __('Nur den simulierten Testlauf mit dem gewählten Ergebnis klären?') }}" wire:loading.attr="disabled" icon="heroicon-o-arrow-path">{{ __('Testlauf klären') }}</x-filament::button>@else – @endif
        @endunless
        </td></tr>
        @empty<tr><td colspan="6">{{ __('Noch keine Versandprüfungen.') }}</td></tr>@endforelse
    </tbody></table></div>
    </x-filament::section>
</x-filament-panels::page>

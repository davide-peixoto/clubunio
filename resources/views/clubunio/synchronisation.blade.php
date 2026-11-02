<x-filament-panels::page>
    <div class="flex flex-wrap gap-3">
        <x-filament::button wire:click="collectOutstanding" wire:loading.attr="disabled" icon="heroicon-o-arrow-down-tray" color="gray">Offene Protokolle übernehmen</x-filament::button>
        <x-filament::button tag="a" href="{{ \App\ClubUnio\Pages\BexioReconciliation::getUrl() }}" icon="heroicon-o-arrow-path" color="gray">Bexio-Abgleich</x-filament::button>
    </div>

    {{ $this->table }}

    @if($historyItemId !== null && $history)
        @php($historyRow = $rows->firstWhere('id', $historyItemId))
        <x-filament::section :heading="'Verlauf · Auftrag #'.$historyItemId">
            <x-slot name="afterHeader">
                <x-filament::button wire:click="closeHistory" wire:loading.attr="disabled" icon="heroicon-o-chevron-up" color="gray" size="sm" aria-expanded="true">Verlauf schliessen</x-filament::button>
            </x-slot>
            <div class="min-w-0 space-y-4 break-words">
                <p>{{ $labels[$historyRow->operation] ?? $historyRow->operation }} · {{ $historyRow->attempts }} Versuche</p>
                @foreach($history as $event)
                    <p>{{ \Carbon\CarbonImmutable::parse($event->created_at)->tz('Europe/Zurich')->format('d.m.Y H:i:s') }} ·
                        {{ ['recorded'=>'Auftrag gespeichert','started'=>'Versuch gestartet','succeeded'=>'Erfolgreich','attention'=>'Prüfung erforderlich','closed_manually'=>'Manuell abgeschlossen'][$event->event] ?? $event->event }}
                        · {{ $event->actor_id ? 'Benutzer '.$event->actor_id : 'Öffentliche Anmeldung' }}
                        @if($event->code) · {{ $event->code }} @endif
                        @if($event->note)<br>{{ $event->note }}@endif
                    </p>
                @endforeach
                <x-filament::pagination :paginator="$history" />
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>

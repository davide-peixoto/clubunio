<x-filament-panels::page>
    <p role="status"><strong>{{ __('SVS Api Test · Testbuchungen · Keine Bankaufträge, kein E-Mail-Versand') }}</strong></p>
    <a href="{{ route('clubunio.bexio.show') }}">{{ __('Bexio-Verbindung') }}</a>
    <a href="{{ \App\ClubUnio\Pages\BexioDispatchTrials::getUrl() }}">{{ __('Allgemeine Versandprüfung') }}</a>
    <a href="{{ \App\ClubUnio\Pages\BexioReconciliation::getUrl() }}">{{ __('Statusabgleich') }}</a>
    <div class="flex flex-wrap gap-3">
        <x-filament::button wire:click="prepare('invoice')" wire:loading.attr="disabled" icon="heroicon-o-document-plus">{{ __('Testrechnung · CHF 25') }}</x-filament::button>
        <x-filament::button wire:click="prepare('expense')" wire:loading.attr="disabled" icon="heroicon-o-receipt-percent">{{ __('Testspesen · CHF 15') }}</x-filament::button>
        <x-filament::button wire:click="prepareParent" wire:confirm="{{ __('Synthetische Elternrechnung mit CHF 7 Rabatt vorbereiten und lokal freigeben?') }}" wire:loading.attr="disabled" icon="heroicon-o-user-group">{{ __('Eltern-Testrechnung · CHF 33') }}</x-filament::button>
        <x-filament::button wire:click="verifyReconnection" wire:confirm="{{ __('Die erneut angemeldete Sandbox anhand der bestehenden Testbelege prüfen und zuordnen?') }}" wire:loading.attr="disabled" color="gray" icon="heroicon-o-shield-check">{{ __('Neue Verbindung prüfen') }}</x-filament::button>
    </div>
    {{ $this->table }}
</x-filament-panels::page>

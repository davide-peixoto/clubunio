<x-filament-panels::page>
    @php($canCreateDraft = \App\ClubUnio\Resources\ProfileUi::allows('contributions', 'create') && \App\ClubUnio\Resources\ProfileUi::allows('invoices', 'create'))
    @error('contribution')
        <p role="alert">{{ $message }}</p>
    @enderror
    @if ($calculation)
        <dl class="clubunio-contribution-summary">
            <div><dt>{{ __('Mitglied') }}</dt><dd>{{ $calculation['member'] }}</dd></div>
            <div><dt>{{ __('Saison') }}</dt><dd>{{ $calculation['season'] }}</dd></div>
            <div><dt>{{ __('Beitragssatz') }}</dt><dd>{{ $calculation['price'] }}</dd></div>
            <div><dt>{{ __('Bruttobetrag') }}</dt><dd>{{ __('CHF') }} {{ number_format($calculation['gross_cents'] / 100, 2, '.', "'") }}</dd></div>
            <div><dt>{{ $calculation['discount_reason'] }}</dt><dd>{{ __('CHF') }} {{ number_format($calculation['discount_cents'] / 100, 2, '.', "'") }}</dd></div>
            <div><dt>{{ __('Nettobetrag') }}</dt><dd>{{ __('CHF') }} {{ number_format($calculation['net_cents'] / 100, 2, '.', "'") }}</dd></div>
        </dl>
        @if ($calculation['existing_invoice_id'])
            <p>{{ __('Für dieses Mitglied besteht bereits ein Saisonbeitrag. Es wird keine neue Rechnung erstellt.') }}</p>
            @if(\App\ClubUnio\Resources\ProfileUi::allows('invoices'))
            <div><x-filament::button tag="a" :href="\App\ClubUnio\Resources\InvoiceResource::getUrl('view', ['record' => $calculation['existing_invoice_id']])" icon="heroicon-o-document-text">{{ __('Bestehende Rechnung öffnen') }}</x-filament::button></div>
            @endif
        @elseif($canCreateDraft)
            <div><x-filament::button wire:click="createDraft" wire:confirm="{{ __('Beitragsrechnung als pendenten Entwurf erstellen? Es erfolgt kein Versand.') }}" wire:loading.attr="disabled" icon="heroicon-o-document-plus">{{ __('Rechnungsentwurf erstellen') }}</x-filament::button></div>
        @endif
    @endif
    @if($batch)
    <x-filament::section heading="{{ __('Saisonlauf') }}">
        <div style="overflow-x:auto"><table class="cu-admin-journal clubunio-batch-table"><thead><tr><th>{{ __('Mitglied') }}</th><th>{{ __('Netto CHF') }}</th><th>{{ __('Prüfung') }}</th></tr></thead><tbody>
        @foreach($batch['rows'] as $row)
        <tr><td>{{ $row['member'] }}</td><td>{{ isset($row['net_cents']) ? number_format($row['net_cents']/100,2,'.',"'") : '–' }}</td><td>{{ $row['error'] ?? (!empty($row['existing_invoice_id']) ? __('Bereits vorhanden') : __('Entwurf möglich')) }}</td></tr>
        @endforeach
        </tbody></table></div>
        @if($canCreateDraft && !$batchResults && count($batch['rows']))
        <x-filament::button wire:click="createBatch" wire:loading.attr="disabled" wire:confirm="{{ __('Geprüfte Beiträge dieses Pakets als Entwürfe erstellen? Keine Freigabe und kein Versand.') }}">{{ __('Entwürfe erstellen') }}</x-filament::button>
        @endif
        @foreach($batchResults as $result)<p>{{ $result['member'] }}: {{ $result['result'] }}</p>@endforeach
        @if($batch['next'])<x-filament::button color="gray" wire:click="nextBatch" wire:loading.attr="disabled">{{ __('Nächste 25 prüfen') }}</x-filament::button>@endif
    </x-filament::section>
    @endif
</x-filament-panels::page>

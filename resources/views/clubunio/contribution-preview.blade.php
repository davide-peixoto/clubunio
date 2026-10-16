<x-filament-panels::page>
    @php($canCreateDraft = \App\ClubUnio\Resources\ProfileUi::allows('contributions', 'create') && \App\ClubUnio\Resources\ProfileUi::allows('invoices', 'create'))
    @error('contribution')
        <p role="alert">{{ $message }}</p>
    @enderror
    @if ($calculation)
        <dl class="clubunio-contribution-summary">
            <div><dt>Mitglied</dt><dd>{{ $calculation['member'] }}</dd></div>
            <div><dt>Saison</dt><dd>{{ $calculation['season'] }}</dd></div>
            <div><dt>Beitragssatz</dt><dd>{{ $calculation['price'] }}</dd></div>
            <div><dt>Bruttobetrag</dt><dd>CHF {{ number_format($calculation['gross_cents'] / 100, 2, '.', "'") }}</dd></div>
            <div><dt>{{ $calculation['discount_reason'] }}</dt><dd>CHF {{ number_format($calculation['discount_cents'] / 100, 2, '.', "'") }}</dd></div>
            <div><dt>Nettobetrag</dt><dd>CHF {{ number_format($calculation['net_cents'] / 100, 2, '.', "'") }}</dd></div>
        </dl>
        @if ($calculation['existing_invoice_id'])
            <p>Für dieses Mitglied besteht bereits ein Saisonbeitrag. Es wird keine neue Rechnung erstellt.</p>
            @if(\App\ClubUnio\Resources\ProfileUi::allows('invoices'))
            <div><x-filament::button tag="a" :href="\App\ClubUnio\Resources\InvoiceResource::getUrl('view', ['record' => $calculation['existing_invoice_id']])" icon="heroicon-o-document-text">Bestehende Rechnung öffnen</x-filament::button></div>
            @endif
        @elseif($canCreateDraft)
            <div><x-filament::button wire:click="createDraft" wire:confirm="Beitragsrechnung als pendenten Entwurf erstellen? Es erfolgt kein Versand." wire:loading.attr="disabled" icon="heroicon-o-document-plus">Rechnungsentwurf erstellen</x-filament::button></div>
        @endif
    @endif
    @if($batch)
    <section><h2>Saisonlauf</h2>
        <div style="overflow-x:auto"><table class="clubunio-batch-table"><thead><tr><th>Mitglied</th><th>Netto CHF</th><th>Prüfung</th></tr></thead><tbody>
        @foreach($batch['rows'] as $row)
        <tr><td>{{ $row['member'] }}</td><td>{{ isset($row['net_cents']) ? number_format($row['net_cents']/100,2,'.',"'") : '–' }}</td><td>{{ $row['error'] ?? (!empty($row['existing_invoice_id']) ? 'Bereits vorhanden' : 'Entwurf möglich') }}</td></tr>
        @endforeach
        </tbody></table></div>
        @if($canCreateDraft && !$batchResults && count($batch['rows']))
        <x-filament::button wire:click="createBatch" wire:loading.attr="disabled" wire:confirm="Geprüfte Beiträge dieses Pakets als Entwürfe erstellen? Keine Freigabe und kein Versand.">Entwürfe erstellen</x-filament::button>
        @endif
        @foreach($batchResults as $result)<p>{{ $result['member'] }}: {{ $result['result'] }}</p>@endforeach
        @if($batch['next'])<x-filament::button color="gray" wire:click="nextBatch" wire:loading.attr="disabled">Nächste 25 prüfen</x-filament::button>@endif
    </section>
    @endif
</x-filament-panels::page>

<x-filament-panels::page>
    <style>
        .cu-srb-toolbar{display:flex;gap:12px;flex-wrap:wrap;align-items:end}.cu-srb-toolbar label{min-width:180px;max-width:100%}
        .cu-srb-scroll{width:100%;overflow-x:auto}.cu-srb-table{width:100%;border-collapse:collapse;font-size:14px}
        .cu-srb-table th,.cu-srb-table td{padding:12px;border-bottom:1px solid #d1d5db;text-align:left;vertical-align:top}
        .cu-srb-actions{display:flex;flex-wrap:wrap;gap:8px;min-width:140px}.cu-srb-table td{overflow-wrap:anywhere}
    </style>
    <div class="cu-srb-toolbar">
        <label>Anlass<x-filament::input.wrapper><x-filament::input.select wire:model.live="event"><option value="">Alle Anlässe</option>@foreach($events as $id=>$title)<option value="{{ $id }}">{{ $title }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
        <label>Status<x-filament::input.wrapper><x-filament::input.select wire:model.live="state"><option value="">Alle</option>@foreach($states as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
        {{ $this->prepareAction }}
        @if(\App\ClubUnio\Services\BexioConnectionService::canManage(\Filament\Facades\Filament::auth()->user(),\Filament\Facades\Filament::getTenant()))
            <x-filament::button wire:click="loadCatalogs" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-path">Bexio-Kataloge laden</x-filament::button>
            {{ $this->configureAction }}
        @endif
        <x-filament::button tag="a" href="/sponsor-runs" color="gray" icon="heroicon-o-arrow-left">Sponsorenlauf</x-filament::button>
    </div>
    <div class="cu-srb-scroll"><table class="cu-srb-table"><thead><tr><th>Zusage</th><th>Betrag</th><th>Status</th><th>Bexio</th><th>Aktionen</th></tr></thead><tbody>
        @forelse($rows as $row)<tr wire:key="sponsor-bill-{{ $row->id }}"><td>{{ $sponsors[$row->pledge_id]??'Zusage #'.$row->pledge_id }}<br>{{ $events[$row->event_id]??'' }}</td><td>CHF {{ number_format($row->total_cents/100,2,'.',"'") }}</td>
            <td><x-filament::badge :color="\App\ClubUnio\Services\StatusAppearance::palette($states[$row->state]??$row->state)">{{ $states[$row->state]??$row->state }}</x-filament::badge></td>
            <td>{{ $row->remote_id ? '#'.$row->remote_id : '–' }}@if($row->paid_cents!==null)<br>Bezahlt CHF {{ number_format($row->paid_cents/100,2,'.',"'") }}@endif</td>
            <td><div class="cu-srb-actions">
                @if($row->remote_id && \App\ClubUnio\Resources\ProfileUi::allows('sponsor_runs','download'))<x-filament::icon-button tag="a" :href="route('clubunio.sponsor.billing.pdf',['invoice'=>$row->id])" target="_blank" icon="heroicon-o-document-text" label="Bexio-PDF öffnen" />@endif
                @if($canOperate)
                    @php($actions=match($row->state){'prepared'=>['link-contact'=>['Kontakt zuordnen','user-plus'],'create-contact'=>['Externen Kontakt erstellen','user-circle']], 'contact_ready'=>['create-draft'=>['Bexio-Entwurf erstellen','document-plus']], 'draft'=>['issue'=>['Ausstellen','check-circle']], 'issued'=>['send'=>['Testrechnung versenden','paper-airplane'],'cancel'=>['Stornieren','x-circle']], 'sent'=>['cancel'=>['Stornieren','x-circle']], 'send_uncertain'=>['confirm-sent'=>['Versand manuell nachweisen','clipboard-document-check']],default=>[]})
                    @foreach($actions as $action=>[$label,$icon])<x-filament::icon-button wire:click="mountAction('operation', {id: {{ $row->id }}, revision: {{ $row->revision }}, action: '{{ $action }}'})" :icon="'heroicon-o-'.$icon" :label="$label" />@endforeach
                    @if(in_array($row->state,['prepared','contact_ready'],true))<x-filament::icon-button wire:click="mountAction('operation', {id: {{ $row->id }}, revision: {{ $row->revision }}, action: 'refresh-preview'})" icon="heroicon-o-arrow-path-rounded-square" label="Empfängersnapshot und Buchhaltung neu prüfen" />@endif
                    <x-filament::icon-button wire:click="mountAction('operation', {id: {{ $row->id }}, revision: {{ $row->revision }}, action: 'sync'})" icon="heroicon-o-arrow-path" label="Ergebnis und Zahlungsstatus abgleichen" />
                @endif
            </div></td></tr>
        @empty<tr><td colspan="5">Keine vorbereiteten Sponsorenrechnungen.</td></tr>@endforelse
    </tbody></table></div>{{ $rows->links() }}
</x-filament-panels::page>

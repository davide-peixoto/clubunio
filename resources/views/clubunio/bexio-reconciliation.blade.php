<x-filament-panels::page>
<style>
.clubunio-bexio { min-width:0; }
.clubunio-bexio-filters { display:flex; flex-wrap:wrap; gap:16px; margin-bottom:20px; }
.clubunio-bexio-filters label { display:grid; gap:6px; font-size:14px; }
.clubunio-bexio .overflow-x-auto { overflow-x:auto; margin-bottom:16px; }
.clubunio-bexio td.break-all { overflow-wrap:anywhere; min-width:160px; max-width:260px; }
.clubunio-bexio td small { display:block; color:#6b7280; margin-top:4px; }
.clubunio-bexio h2 { font-size:18px; font-weight:600; margin:24px 0 12px; }
.clubunio-bexio .cu-admin-journal a { text-decoration:underline; }
@media(max-width:640px) { .clubunio-bexio-filters label { width:100%; } .clubunio-bexio .cu-admin-journal { min-width:700px; } }
</style>
<div class="clubunio-bexio">
@if($sandbox)<p role="status">Bexio-Verbindung: SVS Api Test</p>@endif
@unless($enabled)<p role="status">Bexio kein Update - Deaktiviert</p>@endunless
@if($documentTypes)
<div class="clubunio-bexio-filters">
<label class="grid gap-1">Dokument<x-filament::input.wrapper><x-filament::input.select wire:model.live="type">@foreach($documentTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
</div>
@endif
{{ $this->table }}
<x-filament::section heading="Letzte Prüfungen (max. 30)">
<div class="overflow-x-auto"><table class="cu-admin-journal w-full text-left text-sm"><thead><tr><th>Zeit</th><th>Dokument</th><th>Bexio</th><th>Ergebnis</th><th>Übernahme</th></tr></thead><tbody>
@forelse($checks as $check)<tr><td class="whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($check->created_at)->setTimezone('Europe/Zurich')->format('d.m.Y H:i') }}</td><td>{{ $check->object_type === 'invoice' ? 'Rechnung' : 'Spesen' }} #{{ $check->object_id }}</td><td>{{ $check->remote_status ?? '–' }}</td><td>{{ $check->message }}<small class="block">{{ ['ready'=>'Bereit','applied'=>'Übernommen','unchanged'=>'Unverändert','failed'=>'Fehler'][$check->state] ?? $check->state }}</small></td><td>
@if(\App\ClubUnio\Pages\BexioReconciliation::canSync($check->object_type) && $check->state === 'ready' && $check->actor_id === \Filament\Facades\Filament::auth()->id() && \Carbon\CarbonImmutable::parse($check->created_at)->gt(now()->subMinutes(10)))
<x-filament::button wire:click="apply({{ $check->id }})" wire:confirm="Den geprüften Status in ClubUnio übernehmen? Bexio wird nicht verändert." wire:loading.attr="disabled" icon="heroicon-o-check" size="sm">Übernehmen</x-filament::button>
@else <span>–</span> @endif
</td></tr>@empty<tr><td colspan="5">Noch keine Prüfungen.</td></tr>@endforelse
</tbody></table></div>
</x-filament::section>
</div>
</x-filament-panels::page>

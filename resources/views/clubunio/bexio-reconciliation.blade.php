<x-filament-panels::page>
<style>
.clubunio-bexio { min-width:0; }
.clubunio-bexio-filters { display:flex; flex-wrap:wrap; gap:16px; margin-bottom:20px; }
.clubunio-bexio-filters label { display:grid; gap:6px; font-size:14px; }
.clubunio-bexio-filters input,.clubunio-bexio-filters select { border:1px solid #9ca3af; border-radius:6px; padding:8px 12px; min-height:40px; background:transparent; max-width:100%; }
.clubunio-bexio .overflow-x-auto { overflow-x:auto; margin-bottom:16px; }
.clubunio-bexio table { width:100%; border-collapse:collapse; text-align:left; font-size:14px; }
.clubunio-bexio th,.clubunio-bexio td { padding:12px; border-bottom:1px solid #d1d5db; vertical-align:top; }
.clubunio-bexio th { font-weight:600; }
.clubunio-bexio td.break-all { overflow-wrap:anywhere; min-width:160px; max-width:260px; }
.clubunio-bexio td small { display:block; color:#6b7280; margin-top:4px; }
.clubunio-bexio h2 { font-size:18px; font-weight:600; margin:24px 0 12px; }
.clubunio-bexio a { text-decoration:underline; }
@media(max-width:640px) { .clubunio-bexio-filters label { width:100%; } .clubunio-bexio table { min-width:700px; } }
</style>
<div class="clubunio-bexio">
@if($sandbox)<p role="status"><strong>Sandbox · Nur registrierte Testbelege</strong></p>@endif
@unless($enabled)<p role="status">Bexio kein Update - Deaktiviert</p>@endunless
<div class="clubunio-bexio-filters">
<label class="grid gap-1">Dokument<select wire:model.live="type" class="rounded border p-2"><option value="invoice">Rechnungen</option><option value="expense">Spesen</option></select></label>
<label class="grid gap-1">Mitglied / Bexio-Referenz<input wire:model.live.debounce.400ms="search" maxlength="100" class="rounded border p-2"></label>
</div>
<div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b"><th class="p-3">Dokument</th><th class="p-3">Mitglied</th><th class="p-3">ClubUnio-Status</th><th class="p-3">Bexio-Referenz</th><th class="p-3">Prüfung</th></tr></thead><tbody>
@forelse($documents as $document)<tr class="border-b"><td class="p-3"><a href="{{ $type === 'invoice' ? \App\ClubUnio\Resources\InvoiceResource::getUrl('view',['record'=>$document->id]) : \App\ClubUnio\Resources\ExpenseResource::getUrl('view',['record'=>$document->id]) }}">{{ $document->legacy_id ?? $document->id }}</a></td><td class="p-3">{{ $document->member?->display_name }}</td><td class="p-3">{{ $document->status?->name }}</td><td class="p-3 break-all">{{ $document->{$field} }}</td><td class="p-3"><x-filament::button wire:click="check({{ $document->id }})" wire:loading.attr="disabled" icon="heroicon-o-arrow-path" size="sm">Status prüfen</x-filament::button></td></tr>
@empty<tr><td colspan="5" class="p-3">Keine verknüpften Dokumente gefunden.</td></tr>@endforelse
</tbody></table></div>
<x-filament::pagination :paginator="$documents" />
<h2 class="text-lg font-semibold">Letzte Prüfungen</h2>
<div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead><tr class="border-b"><th class="p-3">Zeit</th><th class="p-3">Dokument</th><th class="p-3">Bexio</th><th class="p-3">Ergebnis</th><th class="p-3">Übernahme</th></tr></thead><tbody>
@forelse($checks as $check)<tr class="border-b"><td class="p-3 whitespace-nowrap">{{ \Carbon\CarbonImmutable::parse($check->created_at)->setTimezone('Europe/Zurich')->format('d.m.Y H:i') }}</td><td class="p-3">{{ $check->object_type === 'invoice' ? 'Rechnung' : 'Spesen' }} #{{ $check->object_id }}</td><td class="p-3">{{ $check->remote_status ?? '–' }}</td><td class="p-3">{{ $check->message }}<small class="block">{{ ['ready'=>'Bereit','applied'=>'Übernommen','unchanged'=>'Unverändert','failed'=>'Fehler'][$check->state] ?? $check->state }}</small></td><td class="p-3">
@if($check->state === 'ready' && $check->actor_id === \Filament\Facades\Filament::auth()->id() && \Carbon\CarbonImmutable::parse($check->created_at)->gt(now()->subMinutes(10)))
<x-filament::button wire:click="apply({{ $check->id }})" wire:confirm="Den geprüften Status in ClubUnio übernehmen? Bexio wird nicht verändert." wire:loading.attr="disabled" icon="heroicon-o-check" size="sm">Übernehmen</x-filament::button>
@else <span>–</span> @endif
</td></tr>@empty<tr><td colspan="5" class="p-3">Noch keine Prüfungen.</td></tr>@endforelse
</tbody></table></div>
</div>
</x-filament-panels::page>

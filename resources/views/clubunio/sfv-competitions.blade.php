<x-filament-panels::page>
@php($canSync = \App\ClubUnio\Resources\ProfileUi::allows('competitions', 'sync'))
<div class="min-w-0 space-y-6">
<x-filament::section heading="Zeitraum">
<div class="flex flex-wrap items-end gap-4">
<div class="min-w-0 space-y-2"><label for="sfv-from">Von</label><x-filament::input.wrapper :valid="! $errors->has('from')"><x-filament::input id="sfv-from" type="date" wire:model.live="from" /></x-filament::input.wrapper></div>
<div class="min-w-0 space-y-2"><label for="sfv-to">Bis</label><x-filament::input.wrapper :valid="! $errors->has('to')"><x-filament::input id="sfv-to" type="date" wire:model.live="to" /></x-filament::input.wrapper></div>
@if($canSync)<x-filament::button wire:click="refreshSchedule" wire:loading.attr="disabled" icon="heroicon-o-arrow-path">SFV abrufen</x-filament::button>@endif
<x-filament::button wire:click="inspectBatch" wire:loading.attr="disabled" icon="heroicon-o-queue-list">Zeitraum prüfen</x-filament::button>
<x-filament::button tag="a" :href="\App\ClubUnio\Resources\CompetitionResource::getUrl()" color="gray" icon="heroicon-o-list-bullet">Wettkämpfe</x-filament::button>
</div>
</x-filament::section>
@if($errors->any())<div class="text-danger-600 dark:text-danger-400" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($batchPreview)
<x-filament::section heading="Sammelübernahme">
<div class="space-y-4">
<p>{{ count($batchPreview['entries']) }} {{ count($batchPreview['entries']) === 1 ? 'Änderung' : 'Änderungen' }} · {{ $batchPreview['unchanged'] }} unverändert · {{ count($batchPreview['issues']) }} nicht übernommen</p>
@foreach($batchPreview['entries'] as $entry)
@if(($entry['kind'] ?? '')==='withdrawal')<p><strong>Auf Signage ausblenden:</strong> {{ $entry['after']['host_name'] }} – {{ $entry['after']['guest_name'] }} · {{ $entry['reason'] }}</p>@endif
<details class="space-y-2"><summary class="cursor-pointer break-words">{{ $entry['after']['plays_on'] }} {{ $entry['after']['starts_at'] }} · {{ $entry['after']['host_name'] }} – {{ $entry['after']['guest_name'] }} · {{ $entry['field'] }} · {{ $entry['target_id'] ? 'Aktualisieren #'.$entry['target_id'] : 'Neu' }}</summary>
<div class="overflow-x-auto"><table class="cu-admin-journal w-full table-fixed text-left text-sm"><thead><tr><th class="p-2">Feld</th><th class="p-2">Bisher</th><th class="p-2">Neu</th></tr></thead><tbody>
@foreach(['plays_on'=>'Datum','starts_at'=>'Zeit','host_name'=>'Gastgeber','guest_name'=>'Gast','field_id'=>'Spielfeld'] as $key=>$label)
<tr><th class="break-words p-2">{{ $label }}</th><td class="break-words p-2">{{ ($key==='field_id' ? $entry['previous_field'] : ($entry['before'][$key] ?? null)) ?? '–' }}</td><td class="break-words p-2">{{ $key==='field_id' ? $entry['field'] : $entry['after'][$key] }}</td></tr>
@endforeach</tbody></table></div></details>
@endforeach
@if(count($batchPreview['issues']))<details><summary>Nicht übernommene Spiele ({{ count($batchPreview['issues']) }})</summary>@foreach($batchPreview['issues'] as $issue)<p>{{ $issue['title'] }}: {{ $issue['message'] }}</p>@endforeach</details>@endif
@if($canSync && count($batchPreview['entries']))<x-filament::button wire:click="applyBatch" wire:confirm="Alle geprüften Änderungen und aufgeführten Signage-Ausblendungen gemeinsam übernehmen? Manuelle Garderoben bleiben erhalten." wire:loading.attr="disabled" icon="heroicon-o-check">Geprüfte Änderungen übernehmen</x-filament::button>@endif
</div>
</x-filament::section>
@endif
{{ $this->table }}
<x-filament::section heading="Einzelübernahme">
<div class="min-w-0 space-y-4">
<p class="break-words" role="status">{{ $selectedMatch ? $selectedMatch->starts_at->tz('Europe/Zurich')->format('d.m.Y H:i').' · '.$selectedMatch->title.' · '.$selectedMatch->location.' · '.$selectedMatch->state : 'Kein SFV-Spiel ausgewählt' }}</p>
@if($targets->count() > 500)<p role="status">Mehr als 500 Wettkämpfe. Bitte den Zeitraum eingrenzen.</p>@endif
<div class="min-w-0 space-y-2"><label for="sfv-target">Bestehender Wettkampf</label><x-filament::input.wrapper><x-filament::input.select id="sfv-target" wire:model.live="target"><option value="">SFV-Verknüpfung verwenden / neu erstellen</option>@foreach($targets->take(500) as $game)<option value="{{ $game->id }}">#{{ $game->id }} · {{ $game->plays_on->format('d.m.Y') }} {{ $game->starts_at }} · {{ $game->host_name }} – {{ $game->guest_name }} · Garderoben {{ $game->hostRoom?->name ?? '–' }} / {{ $game->guestRoom?->name ?? '–' }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></div>
<label class="flex items-start gap-2"><x-filament::input.checkbox wire:model.live="separate" /><span>Andere manuelle Spiele am gleichen Tag geprüft: dieses Spiel ist ein eigenständiger neuer Wettkampf.</span></label>
<x-filament::button wire:click="inspect" wire:loading.attr="disabled" icon="heroicon-o-magnifying-glass">Übernahme prüfen</x-filament::button>
@if($preview)
@if(($preview['kind'] ?? '')==='withdrawal')<p role="status"><strong>Auf Signage ausblenden.</strong> {{ $preview['reason'] }}. Der Wettkampf und seine Garderoben bleiben gespeichert.</p>@endif
<div class="overflow-x-auto"><table class="cu-admin-journal w-full table-fixed text-left text-sm"><thead><tr><th class="p-2">Feld</th><th class="p-2">Bisher</th><th class="p-2">Nach Übernahme</th></tr></thead><tbody>
@foreach(['plays_on'=>'Datum','starts_at'=>'Zeit','host_name'=>'Gastgeber','guest_name'=>'Gast','field_id'=>'Spielfeld','association_match_id'=>'SFV-Spiel'] as $key=>$label)
<tr><th class="break-words p-2">{{ $label }}</th><td class="break-words p-2">{{ ($key === 'field_id' ? $preview['previous_field'] : ($preview['before'][$key] ?? null)) ?? '–' }}</td><td class="break-words p-2">{{ $key === 'field_id' ? $preview['field'] : $preview['after'][$key] }}</td></tr>
@endforeach</tbody></table></div>
<p>{{ $preview['target_id'] ? 'Wettkampf #'.$preview['target_id'].' wird aktualisiert.' : 'Neuer Wettkampf wird erstellt.' }} Garderoben, Seiten, Kategorien, Vereinsnummern und Bemerkungen bleiben unverändert. {{ ($preview['kind'] ?? '')==='withdrawal' ? 'Signage-Sichtbarkeit wird ausgeschaltet.' : 'Bestehende Sichtbarkeit bleibt unverändert.' }}</p>
@if($canSync)<x-filament::button wire:click="apply" wire:confirm="Geprüfte SFV-Daten in diesen Wettkampf übernehmen?" wire:loading.attr="disabled" icon="heroicon-o-check">Übernehmen</x-filament::button>@endif
@endif
</div>
</x-filament::section>
</div>
</x-filament-panels::page>

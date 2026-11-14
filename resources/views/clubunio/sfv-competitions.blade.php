<x-filament-panels::page>
@php($canSync = \App\ClubUnio\Resources\ProfileUi::allows('competitions', 'sync'))
<div class="cu-admin-native">
<x-filament::section heading="{{ __('Zeitraum') }}">
<div class="cu-admin-toolbar">
<div class="cu-admin-field"><label for="sfv-from">{{ __('Von') }}</label><x-filament::input.wrapper :valid="! $errors->has('from')"><x-filament::input id="sfv-from" type="date" wire:model.live="from" /></x-filament::input.wrapper></div>
<div class="cu-admin-field"><label for="sfv-to">{{ __('Bis') }}</label><x-filament::input.wrapper :valid="! $errors->has('to')"><x-filament::input id="sfv-to" type="date" wire:model.live="to" /></x-filament::input.wrapper></div>
@if($canSync)<x-filament::button wire:click="refreshSchedule" wire:loading.attr="disabled" icon="heroicon-o-arrow-path">{{ __('SFV abrufen') }}</x-filament::button>@endif
<x-filament::button wire:click="inspectBatch" wire:loading.attr="disabled" icon="heroicon-o-queue-list">{{ __('Zeitraum prüfen') }}</x-filament::button>
<x-filament::button tag="a" :href="\App\ClubUnio\Resources\CompetitionResource::getUrl()" color="gray" icon="heroicon-o-list-bullet">{{ __('Wettkämpfe') }}</x-filament::button>
</div>
</x-filament::section>
@if($errors->any())<div class="text-danger-600 dark:text-danger-400" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($batchPreview)
<x-filament::section heading="{{ __('Sammelübernahme') }}">
<div class="space-y-4">
<p>{{ count($batchPreview['entries']) }} {{ count($batchPreview['entries']) === 1 ? __('Änderung') : __('Änderungen') }} · {{ $batchPreview['unchanged'] }} {{ __('unverändert ·') }} {{ count($batchPreview['issues']) }} {{ __('nicht übernommen') }}</p>
@foreach($batchPreview['entries'] as $entry)
@if(($entry['kind'] ?? '')==='withdrawal')<p><strong>{{ __('Auf Signage ausblenden:') }}</strong> {{ $entry['after']['host_name'] }} – {{ $entry['after']['guest_name'] }} · {{ $entry['reason'] }}</p>@endif
<details class="space-y-2"><summary class="cursor-pointer break-words">{{ $entry['after']['plays_on'] }} {{ $entry['after']['starts_at'] }} · {{ $entry['after']['host_name'] }} – {{ $entry['after']['guest_name'] }} · {{ $entry['field'] }} · {{ $entry['target_id'] ? __('Aktualisieren #').$entry['target_id'] : __('Neu') }}</summary>
<div class="overflow-x-auto"><table class="cu-admin-journal w-full table-fixed text-left text-sm"><thead><tr><th class="p-2">{{ __('Feld') }}</th><th class="p-2">{{ __('Bisher') }}</th><th class="p-2">{{ __('Neu') }}</th></tr></thead><tbody>
@foreach(['plays_on'=>__('Datum'),'starts_at'=>__('Zeit'),'host_name'=>__('Heimmannschaft'),'guest_name'=>__('Gastmannschaft'),'field_id'=>__('Spielfeld')] as $key=>$label)
<tr><th class="break-words p-2">{{ $label }}</th><td class="break-words p-2">{{ ($key==='field_id' ? $entry['previous_field'] : ($entry['before'][$key] ?? null)) ?? '–' }}</td><td class="break-words p-2">{{ $key==='field_id' ? $entry['field'] : $entry['after'][$key] }}</td></tr>
@endforeach</tbody></table></div></details>
@endforeach
@if(count($batchPreview['issues']))<details><summary>{{ __('Nicht übernommene Spiele (') }}{{ count($batchPreview['issues']) }})</summary>@foreach($batchPreview['issues'] as $issue)<p>{{ $issue['title'] }}: {{ $issue['message'] }}</p>@endforeach</details>@endif
@if($canSync && count($batchPreview['entries']))<x-filament::button wire:click="applyBatch" wire:confirm="{{ __('Alle geprüften Änderungen und aufgeführten Signage-Ausblendungen gemeinsam übernehmen? Manuelle Garderoben bleiben erhalten.') }}" wire:loading.attr="disabled" icon="heroicon-o-check">{{ __('Geprüfte Änderungen übernehmen') }}</x-filament::button>@endif
</div>
</x-filament::section>
@endif
{{ $this->table }}
<x-filament::section heading="{{ __('Einzelübernahme') }}">
<div class="min-w-0 space-y-4">
<p class="break-words" role="status">{{ $selectedMatch ? $selectedMatch->starts_at->tz('Europe/Zurich')->format('d.m.Y H:i').' · '.$selectedMatch->title.' · '.$selectedMatch->location.' · '.$selectedMatch->state : __('Kein SFV-Spiel ausgewählt') }}</p>
@if($targets->count() > 500)<p role="status">{{ __('Mehr als 500 Wettkämpfe. Bitte den Zeitraum eingrenzen.') }}</p>@endif
<div class="min-w-0 space-y-2"><label for="sfv-target">{{ __('Bestehender Wettkampf') }}</label><x-filament::input.wrapper><x-filament::input.select id="sfv-target" wire:model.live="target"><option value="">{{ __('SFV-Verknüpfung verwenden / neu erstellen') }}</option>@foreach($targets->take(500) as $game)<option value="{{ $game->id }}">#{{ $game->id }} · {{ $game->plays_on->format('d.m.Y') }} {{ $game->starts_at }} · {{ $game->host_name }} – {{ $game->guest_name }} {{ __('· Garderoben') }} {{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('training_options', ($game->hostRoom ?? ['name' => '–']), tenant: $tenant->id ?? null) }} / {{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('training_options', ($game->guestRoom ?? ['name' => '–']), tenant: $tenant->id ?? null) }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></div>
<label class="flex items-start gap-2"><x-filament::input.checkbox wire:model.live="separate" /><span>{{ __('Andere manuelle Spiele am gleichen Tag geprüft: dieses Spiel ist ein eigenständiger neuer Wettkampf.') }}</span></label>
<x-filament::button wire:click="inspect" wire:loading.attr="disabled" icon="heroicon-o-magnifying-glass">{{ __('Übernahme prüfen') }}</x-filament::button>
@if($preview)
@if(($preview['kind'] ?? '')==='withdrawal')<p role="status"><strong>{{ __('Auf Signage ausblenden.') }}</strong> {{ $preview['reason'] }}{{ __('. Der Wettkampf und seine Garderoben bleiben gespeichert.') }}</p>@endif
<div class="overflow-x-auto"><table class="cu-admin-journal w-full table-fixed text-left text-sm"><thead><tr><th class="p-2">{{ __('Feld') }}</th><th class="p-2">{{ __('Bisher') }}</th><th class="p-2">{{ __('Nach Übernahme') }}</th></tr></thead><tbody>
@foreach(['plays_on'=>__('Datum'),'starts_at'=>__('Zeit'),'host_name'=>__('Heimmannschaft'),'guest_name'=>__('Gastmannschaft'),'field_id'=>__('Spielfeld'),'association_match_id'=>__('SFV-Spiel')] as $key=>$label)
<tr><th class="break-words p-2">{{ $label }}</th><td class="break-words p-2">{{ ($key === 'field_id' ? $preview['previous_field'] : ($preview['before'][$key] ?? null)) ?? '–' }}</td><td class="break-words p-2">{{ $key === 'field_id' ? $preview['field'] : $preview['after'][$key] }}</td></tr>
@endforeach</tbody></table></div>
<p>{{ $preview['target_id'] ? __('Wettkampf #').$preview['target_id'].__(' wird aktualisiert.') : __('Neuer Wettkampf wird erstellt.') }} {{ __('Garderoben, Seiten, Kategorien, Vereinsnummern und Bemerkungen bleiben unverändert.') }} {{ ($preview['kind'] ?? '')==='withdrawal' ? __('Signage-Sichtbarkeit wird ausgeschaltet.') : __('Bestehende Sichtbarkeit bleibt unverändert.') }}</p>
@if($impact = ($preview['impact'] ?? null))
    <div class="space-y-2">
        <h3 class="font-semibold">{{ trans('sfv_reschedule.heading') }}</h3>
        @if($impact['conflicts'])
            <ul class="list-disc ps-5">
                @foreach($impact['conflicts'] as $conflict)
                    <li>{{ trans('sfv_reschedule.conflicts.'.($conflict['source'] ?? 'unavailable')) }}
                        {{ $conflict['date'] }} {{ $conflict['starts_at'] }} @if($conflict['ends_at'])– {{ $conflict['ends_at'] }}@endif</li>
                @endforeach
            </ul>
        @else
            <p>{{ trans('sfv_reschedule.no_conflicts') }}</p>
        @endif
        @if($impact['events'])
            <ul class="list-disc ps-5">
                @foreach($impact['events'] as $event)
                    <li>{{ $event['input']['title'] }} · {{ $event['previous_start'] }} · {{ $event['previous_location'] }} → {{ str_replace('T', ' ', $event['input']['starts_at']) }} · {{ $event['input']['location'] }}</li>
                @endforeach
            </ul>
        @endif
        @if($impact['restricted'])
            <p>{{ trans('sfv_reschedule.restricted', ['count' => $impact['restricted']]) }}</p>
        @endif
        @if($impact['conflicts'] || $impact['restricted'])
            <p>{{ trans('sfv_reschedule.blocked') }}</p>
        @endif
    </div>
@endif
@if($canSync && (! $impact || (! $impact['conflicts'] && ! $impact['restricted'])))<x-filament::button wire:click="apply" wire:confirm="{{ $impact ? trans('sfv_reschedule.confirm') : __('Geprüfte SFV-Daten in diesen Wettkampf übernehmen?') }}" wire:loading.attr="disabled" icon="heroicon-o-check">{{ $impact ? trans('sfv_reschedule.apply') : __('Übernehmen') }}</x-filament::button>@endif
@endif
</div>
</x-filament::section>
</div>
</x-filament-panels::page>

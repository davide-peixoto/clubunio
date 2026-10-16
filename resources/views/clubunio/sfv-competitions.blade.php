<x-filament-panels::page>
@php($canSync = \App\ClubUnio\Resources\ProfileUi::allows('competitions', 'sync'))
<style>
.sfv-import { min-width:0; } .sfv-import .fields { display:flex; flex-wrap:wrap; gap:16px; align-items:end; margin-bottom:20px; }
.sfv-import label { display:grid; gap:6px; margin-bottom:12px; min-width:0; }
.sfv-import select,.sfv-import input:not([type=checkbox]) { width:100%; min-width:0; max-width:100%; border:1px solid #9ca3af; border-radius:6px; padding:8px; background:transparent; }
.sfv-import table { width:100%; border-collapse:collapse; margin:20px 0; table-layout:fixed; }
.sfv-import th,.sfv-import td { padding:10px; text-align:left; border-bottom:1px solid #d1d5db; overflow-wrap:anywhere; }
.sfv-import .error { color:#b91c1c; margin:12px 0; } .sfv-import .check { display:flex; align-items:center; gap:8px; }
.sfv-import h2 { font-size:18px; font-weight:600; margin:20px 0 10px; } .sfv-import details { padding:10px 0; border-bottom:1px solid #d1d5db; overflow-wrap:anywhere; } .sfv-import summary { cursor:pointer; } .sfv-import p { margin:12px 0; }
.sfv-import .batch { margin-bottom:24px; }
</style>
<div class="sfv-import">
<div class="fields">
<label>Von<input type="date" wire:model.live="from"></label><label>Bis<input type="date" wire:model.live="to"></label>
@if($canSync)<x-filament::button wire:click="refreshSchedule" wire:loading.attr="disabled" icon="heroicon-o-arrow-path">SFV abrufen</x-filament::button>@endif
<x-filament::button wire:click="inspectBatch" wire:loading.attr="disabled" icon="heroicon-o-queue-list">Zeitraum prüfen</x-filament::button>
<x-filament::button tag="a" :href="\App\ClubUnio\Resources\CompetitionResource::getUrl()" color="gray" icon="heroicon-o-list-bullet">Wettkämpfe</x-filament::button>
</div>
@if($errors->any())<div class="error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
@if($batchPreview)
<section class="batch">
<h2>Sammelübernahme</h2>
<p>{{ count($batchPreview['entries']) }} {{ count($batchPreview['entries']) === 1 ? 'Änderung' : 'Änderungen' }} · {{ $batchPreview['unchanged'] }} unverändert · {{ count($batchPreview['issues']) }} nicht übernommen</p>
@foreach($batchPreview['entries'] as $entry)
@if(($entry['kind'] ?? '')==='withdrawal')<p><strong>Auf Signage ausblenden:</strong> {{ $entry['after']['host_name'] }} – {{ $entry['after']['guest_name'] }} · {{ $entry['reason'] }}</p>@endif
<details><summary>{{ $entry['after']['plays_on'] }} {{ $entry['after']['starts_at'] }} · {{ $entry['after']['host_name'] }} – {{ $entry['after']['guest_name'] }} · {{ $entry['field'] }} · {{ $entry['target_id'] ? 'Aktualisieren #'.$entry['target_id'] : 'Neu' }}</summary>
<table><thead><tr><th>Feld</th><th>Bisher</th><th>Neu</th></tr></thead><tbody>
@foreach(['plays_on'=>'Datum','starts_at'=>'Zeit','host_name'=>'Gastgeber','guest_name'=>'Gast','field_id'=>'Spielfeld'] as $key=>$label)
<tr><th>{{ $label }}</th><td>{{ ($key==='field_id' ? $entry['previous_field'] : ($entry['before'][$key] ?? null)) ?? '–' }}</td><td>{{ $key==='field_id' ? $entry['field'] : $entry['after'][$key] }}</td></tr>
@endforeach</tbody></table></details>
@endforeach
@if(count($batchPreview['issues']))<details><summary>Nicht übernommene Spiele ({{ count($batchPreview['issues']) }})</summary>@foreach($batchPreview['issues'] as $issue)<p>{{ $issue['title'] }}: {{ $issue['message'] }}</p>@endforeach</details>@endif
@if($canSync && count($batchPreview['entries']))<x-filament::button wire:click="applyBatch" wire:confirm="Alle geprüften Änderungen und aufgeführten Signage-Ausblendungen gemeinsam übernehmen? Manuelle Garderoben bleiben erhalten." wire:loading.attr="disabled" icon="heroicon-o-check">Geprüfte Änderungen übernehmen</x-filament::button>@endif
</section>
@endif
@if($matches->count() > 500 || $targets->count() > 500)<p role="status">Mehr als 500 Einträge. Bitte den Zeitraum eingrenzen.</p>@endif
<label>SFV-Spiel<select wire:model.live="match"><option value="">Spiel auswählen</option>@foreach($matches->take(500) as $game)<option value="{{ $game->id }}">{{ \Carbon\CarbonImmutable::parse($game->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }} · {{ $game->title }} · {{ $game->location }} · {{ $game->state }}</option>@endforeach</select></label>
<label>Bestehender Wettkampf<select wire:model.live="target"><option value="">SFV-Verknüpfung verwenden / neu erstellen</option>@foreach($targets->take(500) as $game)<option value="{{ $game->id }}">#{{ $game->id }} · {{ $game->plays_on->format('d.m.Y') }} {{ $game->starts_at }} · {{ $game->host_name }} – {{ $game->guest_name }} · Garderoben {{ $game->hostRoom?->name ?? '–' }} / {{ $game->guestRoom?->name ?? '–' }}</option>@endforeach</select></label>
<label class="check"><input type="checkbox" wire:model.live="separate">Andere manuelle Spiele am gleichen Tag geprüft: dieses Spiel ist ein eigenständiger neuer Wettkampf.</label>
<x-filament::button wire:click="inspect" wire:loading.attr="disabled" icon="heroicon-o-magnifying-glass">Übernahme prüfen</x-filament::button>
@if($preview)
@if(($preview['kind'] ?? '')==='withdrawal')<p role="status"><strong>Auf Signage ausblenden.</strong> {{ $preview['reason'] }}. Der Wettkampf und seine Garderoben bleiben gespeichert.</p>@endif
<table><thead><tr><th>Feld</th><th>Bisher</th><th>Nach Übernahme</th></tr></thead><tbody>
@foreach(['plays_on'=>'Datum','starts_at'=>'Zeit','host_name'=>'Gastgeber','guest_name'=>'Gast','field_id'=>'Spielfeld','association_match_id'=>'SFV-Spiel'] as $key=>$label)
<tr><th>{{ $label }}</th><td>{{ ($key === 'field_id' ? $preview['previous_field'] : ($preview['before'][$key] ?? null)) ?? '–' }}</td><td>{{ $key === 'field_id' ? $preview['field'] : $preview['after'][$key] }}</td></tr>
@endforeach</tbody></table>
<p>{{ $preview['target_id'] ? 'Wettkampf #'.$preview['target_id'].' wird aktualisiert.' : 'Neuer Wettkampf wird erstellt.' }} Garderoben, Seiten, Kategorien, Vereinsnummern und Bemerkungen bleiben unverändert. {{ ($preview['kind'] ?? '')==='withdrawal' ? 'Signage-Sichtbarkeit wird ausgeschaltet.' : 'Bestehende Sichtbarkeit bleibt unverändert.' }}</p>
@if($canSync)<x-filament::button wire:click="apply" wire:confirm="Geprüfte SFV-Daten in diesen Wettkampf übernehmen?" wire:loading.attr="disabled" icon="heroicon-o-check">Übernehmen</x-filament::button>@endif
@endif
</div>
</x-filament-panels::page>

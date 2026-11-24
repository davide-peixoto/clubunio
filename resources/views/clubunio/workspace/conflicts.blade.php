<x-filament-panels::page>
<div class="cu-workflow">
    <label class="cu-workflow-field">{{ trans('workspace.date') }}<x-filament::input.wrapper><x-filament::input type="date" wire:model.live="date" min="2000-01-01" max="2100-12-20" /></x-filament::input.wrapper></label>
    <p class="cu-module-note">{{ trans('workspace.conflict_scope',['sources'=>implode(', ',$report['sources']),'from'=>$report['from']->format('d.m.Y'),'to'=>$report['until']->format('d.m.Y')]) }}</p>
    <h2>{{ trans('workspace.conflict_count',['count'=>$report['count']]) }}</h2>
    <div class="cu-workflow-cards">@forelse($report['rows'] as $row)<article class="cu-workflow-section cu-conflict-card"><time>{{ $row['date'] }}</time><h2>{{ $row['title'] }}</h2><p>{{ $row['reason'] }}</p><a href="{{ $row['url'] }}">{{ trans('workspace.open') }} ↗</a></article>@empty<p class="cu-workflow-section">{{ trans('workspace.no_conflicts') }}</p>@endforelse</div>
    @if($report['count']>24)<div class="cu-workflow-pagination"><x-filament::button color="gray" :disabled="$conflictPage<=1" wire:click="$set('conflictPage',{{ max(1,$conflictPage-1) }})" wire:loading.attr="disabled">{{ trans('workspace.previous') }}</x-filament::button><span>{{ trans('workspace.page',['page'=>$conflictPage]) }}</span><x-filament::button color="gray" :disabled="$conflictPage*24 >= $report['count']" wire:click="$set('conflictPage',{{ $conflictPage+1 }})" wire:loading.attr="disabled">{{ trans('workspace.next') }}</x-filament::button></div>@endif
</div>
</x-filament-panels::page>

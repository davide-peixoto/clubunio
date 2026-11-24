<x-filament-panels::page>
<div class="cu-workflow">
    <p class="cu-module-note">{{ trans('workspace.approval_note') }}</p>
    <label class="cu-workflow-field">{{ trans('workspace.queue') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="kind">@foreach($kinds as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
    <div class="cu-workflow-columns cu-approval-layout">
        <section class="cu-workflow-section"><h2>{{ trans('workspace.queue_count',['count'=>$list['count']]) }}</h2><ul class="cu-workflow-list">
            @forelse($list['rows'] as $row)<li wire:key="queue-{{ $kind }}-{{ $row['id'] }}"><span>{{ $row['label'] }}</span><x-filament::button color="gray" size="sm" wire:click="select({{ $row['id'] }})" wire:loading.attr="disabled">{{ trans('workspace.select') }} #{{ $row['id'] }}</x-filament::button></li>@empty<li>{{ trans('workspace.empty') }}</li>@endforelse
        </ul><div class="cu-workflow-pagination">
            <x-filament::button color="gray" :disabled="$queuePage<=1" wire:click="$set('queuePage',{{ max(1,$queuePage-1) }})" wire:loading.attr="disabled">{{ trans('workspace.previous') }}</x-filament::button>
            <span>{{ trans('workspace.page',['page'=>$queuePage]) }}</span>
            <x-filament::button color="gray" :disabled="$queuePage*10 >= $list['count']" wire:click="$set('queuePage',{{ $queuePage+1 }})" wire:loading.attr="disabled">{{ trans('workspace.next') }}</x-filament::button>
        </div></section>
        <section class="cu-workflow-section cu-approval-preview" aria-live="polite" x-data @workspace-preview-selected.window="$nextTick(() => { if (window.innerWidth < 768) { $el.scrollIntoView({behavior: 'auto', block: 'start'}); $el.querySelector('h2').focus({preventScroll: true}); } })"><h2 tabindex="-1">{{ trans('workspace.preview') }}</h2>
            @if($preview)
                <p>{{ $kinds[$kind] }} #{{ $preview['id'] }}</p>
                <dl class="cu-workflow-facts">@foreach($preview['facts'] as $fact)<div><dt>{{ $fact['label'] }}</dt><dd>{{ $fact['value'] }}</dd></div>@endforeach</dl>
                @if($preview['comparison'])@include('clubunio.members.change-comparison',['rows'=>$preview['comparison'],'note'=>null])@endif
                @if($preview['lines'])<ul class="cu-workflow-list">@foreach($preview['lines'] as $line)<li><span>{{ $line['label'] }}</span><strong>{{ $line['value'] }}</strong></li>@endforeach</ul><p class="cu-module-note">{{ trans('workspace.lines_note') }}</p>@endif
                <a class="cu-workflow-original" href="{{ $preview['url'] }}">{{ trans('workspace.open') }} ↗</a>
                @if($preview['revision'] !== $selectedRevision)<p role="alert">{{ trans('workspace.preview_stale') }}</p>
                @else
                    <label class="cu-workflow-field">{{ trans('workspace.decision') }}<x-filament::input.wrapper><x-filament::input.select wire:model="decision">@foreach($preview['decisions'] as $choice)<option value="{{ $choice }}">{{ trans('workspace.'.$choice) }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    <label class="cu-workflow-field">{{ trans('workspace.note') }}<textarea class="cu-workflow-textarea" wire:model="note" minlength="10" maxlength="500" rows="3" required></textarea></label>
                    {{ $this->decideAction }}
                @endif
                <x-filament::button color="gray" wire:click="closePreview">{{ trans('workspace.close') }}</x-filament::button>
            @elseif($selectedId)<p role="alert">{{ trans('workspace.preview_stale') }}</p>@else<p>{{ trans('workspace.preview_empty') }}</p>@endif
            @foreach($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
        </section>
    </div>
</div>
</x-filament-panels::page>

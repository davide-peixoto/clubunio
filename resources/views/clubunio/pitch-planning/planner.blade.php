    <link rel="stylesheet" href="{{ asset('css/pitch-planning.css') }}?v=4">
    @php
        $statuses = ['approved' => __('Genehmigt'), 'pending' => __('Angefragt'), 'rejected' => __('Abgelehnt'), 'cancelled' => __('Abgesagt')];
        $sources = ['training' => __('Training'), 'competition' => __('Wettkampf'), 'request' => __('Trainingsspiel'), 'block' => __('Sperre'), 'manual' => __('Manuelle Belegung')];
        $fields = collect($options['fields'])->keyBy('id');
    @endphp
    <div class="pitch-planner" x-data="{ dragKey: null, busy: false }" x-on:dragend="dragKey = null" wire:loading.class="pitch-loading">
        <div class="pitch-toolbar">
            <div class="pitch-controls">
                <x-filament::icon-button wire:click="browse(-1)" wire:loading.attr="disabled" icon="heroicon-o-chevron-left" :label="__('Vorheriger Zeitraum')" :tooltip="__('Vorheriger Zeitraum')" />
                <x-filament::button wire:click="today" wire:loading.attr="disabled" color="gray">{{ __('Heute') }}</x-filament::button>
                <x-filament::icon-button wire:click="browse(1)" wire:loading.attr="disabled" icon="heroicon-o-chevron-right" :label="__('Nächster Zeitraum')" :tooltip="__('Nächster Zeitraum')" />
                <label><span class="pitch-sr-only">{{ __('Datum') }}</span><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="date" required /></x-filament::input.wrapper></label>
                <x-filament::icon-button wire:click="refreshCalendar" wire:loading.attr="disabled" icon="heroicon-o-arrow-path" :label="__('Neu laden')" :tooltip="__('Neu laden')" />
            </div>
            <x-filament::tabs class="pitch-modes" :label="__('Kalenderansicht')">
                @foreach(['day' => __('Tag'), 'week' => __('Woche'), 'month' => __('Monat')] as $value => $label)
                    <x-filament::tabs.item :active="$mode === $value" wire:click="$set('mode', '{{ $value }}')" wire:loading.attr="disabled" :aria-pressed="$mode === $value ? 'true' : 'false'">{{ $label }}</x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>
            <label class="pitch-team"><span class="pitch-sr-only">{{ __('Mannschaft') }}</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="teamId"><option value="">{{ __('Alle Mannschaften') }}</option>@foreach($options['teams'] as $team)<option value="{{ $team['id'] }}">{{ $team['name'] }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <div class="pitch-controls">
                @if($this->capability('request'))<x-filament::button wire:click="createEntry('request')" wire:loading.attr="disabled" icon="heroicon-o-plus">{{ __('Trainingsspiel anfragen') }}</x-filament::button>@endif
                @if($this->capability('manage'))
                    <x-filament::button wire:click="createEntry('manual')" wire:loading.attr="disabled" icon="heroicon-o-plus" color="gray">{{ __('Manuelle Belegung') }}</x-filament::button>
                    <x-filament::button wire:click="createEntry('block')" wire:loading.attr="disabled" icon="heroicon-o-no-symbol" color="gray">{{ __('Sperre erfassen') }}</x-filament::button>
                    <x-filament::icon-button wire:click="editArea" wire:loading.attr="disabled" icon="heroicon-o-square-3-stack-3d" :label="__('Teilflächen verwalten')" :tooltip="__('Teilflächen verwalten')" />
                @endif
            </div>
        </div>
        <div class="pitch-summary">
            <strong>{{ $days[0]['date']->locale(app()->getLocale())->isoFormat($mode === 'day' ? 'dddd, LL' : 'LL') }}@if($mode !== 'day') - {{ end($days)['date']->locale(app()->getLocale())->isoFormat('LL') }}@endif</strong>
            <span>{{ __('Zeitzone: Europe/Zurich') }}</span>
            <span role="status" wire:loading>{{ __('Wird geladen ...') }}</span>
        </div>
        @if($loadError)<p class="pitch-alert" role="alert">{{ $loadError }}</p>@endif
        @if(!$editor && $errors->any())<div class="pitch-alert" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @if(!$loadError && !$options['fields'])<p class="pitch-empty">{{ __('Keine aktiven Spielfelder vorhanden.') }}</p>@endif
        @if($options['fields'] && $mode === 'day')
            <div class="pitch-scroll" tabindex="0" role="region" aria-label="{{ __('Tagesplan nach Spielfeld') }}" x-init="$nextTick(() => $el.scrollTop = 720)">
                <div class="pitch-board" style="grid-template-columns: 60px repeat({{ count($columns) }}, minmax(240px, 1fr))">
                    <div class="pitch-heading pitch-time-heading">{{ __('Zeit') }}</div>
                    @foreach($columns as $column)
                        <div class="pitch-heading"><strong>{{ $column['field']['name'] }}</strong>@if(!$column['field']['available'])<span class="pitch-status pitch-status-cancelled">{{ __('Gesperrt') }}</span>@endif</div>
                    @endforeach
                    <div class="pitch-hours">@for($hour = 0; $hour < 24; $hour++)<span style="top: {{ $hour * 90 }}px">{{ sprintf('%02d:00', $hour) }}</span>@endfor</div>
                    @foreach($columns as $column)
                        <div class="pitch-column" wire:key="pitch-field-{{ $column['field']['id'] }}-{{ $date }}">
                            @for($minute = 0; $minute < 1440; $minute += 30)
                                <div class="pitch-drop" style="top: {{ $minute * 1.5 }}px" x-on:dragover.prevent x-on:drop.prevent="if (dragKey && !busy) { busy = true; $wire.proposeMove(dragKey, {{ $column['field']['id'] }}, @js($date), @js(sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60))).finally(() => { busy = false; dragKey = null }) }"></div>
                            @endfor
                            @foreach($column['events'] as $entry)
                                @include('clubunio.pitch-planning.entry', ['timeline' => true, 'lanes' => $column['lanes']])
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif($options['fields'])
            <div class="pitch-calendar-scroll" tabindex="0" role="region" aria-label="{{ __('Kalenderansicht') }}">
                <div class="pitch-calendar pitch-calendar-{{ $mode }}">
                    @foreach($days as $day)
                        <section class="pitch-day" wire:key="pitch-day-{{ $day['date']->toDateString() }}">
                            <button type="button" class="pitch-date" wire:click="openDay('{{ $day['date']->toDateString() }}')" wire:loading.attr="disabled"><span>{{ $day['date']->locale(app()->getLocale())->isoFormat('ddd') }}</span><strong>{{ $day['date']->format('d.m.') }}</strong></button>
                            @forelse($day['events'] as $entry)
                                @include('clubunio.pitch-planning.entry', ['timeline' => false, 'lanes' => 1])
                            @empty<span class="pitch-day-empty">{{ __('Keine Belegungen') }}</span>@endforelse
                        </section>
                    @endforeach
                </div>
            </div>
        @endif
        <div class="pitch-legend" aria-label="{{ __('Belegungsstatus') }}">@foreach($statuses as $status => $label)<span class="pitch-status pitch-status-{{ $status }}">{{ $label }}</span>@endforeach<span class="pitch-conflict">{{ __('Konflikt') }}</span></div>
        @include('clubunio.pitch-planning.editor')
    </div>

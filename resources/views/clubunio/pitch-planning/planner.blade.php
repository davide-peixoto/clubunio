    <link rel="stylesheet" href="{{ asset('css/pitch-planning.css') }}?v=6">
    <script src="{{ asset('js/pitch-planning.js') }}?v=5"></script>
    @if($this->modernSelected())
        <link rel="stylesheet" href="{{ asset('css/clubunio-facility-planner.css') }}?v={{ filemtime(public_path('css/clubunio-facility-planner.css')) }}">
        @include('clubunio.react-script', ['entry' => 'facility', 'legacy' => asset('js/clubunio-facility-planner.js').'?v='.filemtime(public_path('js/clubunio-facility-planner.js'))])
    @endif
    @php
        $statuses = ['approved' => __('Genehmigt'), 'pending' => __('Angefragt'), 'rejected' => __('Abgelehnt'), 'cancelled' => __('Abgesagt')];
        $sources = ['training' => __('Training'), 'competition' => __('Wettkampf'), 'request' => __('Trainingsspiel'), 'block' => __('Sperre'), 'manual' => __('Manuelle Belegung')];
        $fields = collect($options['fields'])->keyBy('id');
    @endphp
    <div class="pitch-planner {{ $this->modernSelected() ? 'pitch-modern' : '' }}" x-data="clubunioPitchPlanner()" x-on:dragend="endMove()" x-on:keydown.escape.window="cancelRange()" x-on:blur.window="cancelRange()" x-on:visibilitychange.document="if (document.hidden) cancelRange()" wire:loading.class="pitch-loading">
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
        @if($mode === 'day' && $this->capability('request'))<p class="pitch-status-hint">{{ __('Drag across time slots to select the start and duration.') }}</p>@endif
        <div class="pitch-summary">
            <strong>{{ $days[0]['date']->locale(app()->getLocale())->isoFormat($mode === 'day' ? 'dddd, LL' : 'LL') }}@if($mode !== 'day') - {{ end($days)['date']->locale(app()->getLocale())->isoFormat('LL') }}@endif</strong>
            <span>{{ __('Zeitzone: Europe/Zurich') }}</span>
            <span role="status" wire:loading>{{ __('Wird geladen ...') }}</span>
        </div>
        @if($loadError)<p class="pitch-alert" role="alert">{{ $loadError }}</p>@endif
        @if(!$editor && $errors->any())<div class="pitch-alert" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @if(!$loadError && !$options['fields'])<p class="pitch-empty">{{ __('Keine aktiven Spielfelder vorhanden.') }}</p>@endif
        @if($this->modernSelected() && $options['fields'])
            @include('clubunio.pitch-planning.modern')
        @else
            @include('clubunio.pitch-planning.calendar')
        @endif
        <div class="pitch-legend" aria-label="{{ __('Belegungsstatus') }}">@foreach($statuses as $status => $label)<span class="pitch-status pitch-status-{{ $status }}">{{ $label }}</span>@endforeach<span class="pitch-conflict">{{ __('Konflikt') }}</span></div>
        @include('clubunio.pitch-planning.editor')
    </div>

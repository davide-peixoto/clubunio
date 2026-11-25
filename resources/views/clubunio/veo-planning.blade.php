<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/pitch-planning.css') }}?v=6">
    <link rel="stylesheet" href="{{ asset('css/clubunio-facility-planner.css') }}?v={{ filemtime(public_path('css/clubunio-facility-planner.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/clubunio-veo-planning.css') }}?v=1">
    @include('clubunio.react-script', ['entry' => 'facility', 'legacy' => asset('js/clubunio-facility-planner.js').'?v='.filemtime(public_path('js/clubunio-facility-planner.js'))])
    <div class="pitch-planner pitch-modern cu-veo-planner" wire:loading.class="pitch-loading">
        <div class="pitch-toolbar">
            <div class="pitch-controls">
                <x-filament::icon-button wire:click="browse(-1)" wire:loading.attr="disabled" icon="heroicon-o-chevron-left" :label="__('Vorheriger Zeitraum')" :tooltip="__('Vorheriger Zeitraum')" />
                <x-filament::button wire:click="today" color="gray">{{ __('Heute') }}</x-filament::button>
                <x-filament::icon-button wire:click="browse(1)" wire:loading.attr="disabled" icon="heroicon-o-chevron-right" :label="__('Nächster Zeitraum')" :tooltip="__('Nächster Zeitraum')" />
                <label><span class="pitch-sr-only">{{ __('Datum') }}</span><x-filament::input.wrapper><x-filament::input type="date" wire:model.live="date" required /></x-filament::input.wrapper></label>
            </div>
            <x-filament::tabs :label="__('Kalenderansicht')">
                @foreach(['day' => __('Tag'), 'week' => __('Woche'), 'month' => __('Monat')] as $value => $label)
                    <x-filament::tabs.item :active="$mode === $value" wire:click="$set('mode', '{{ $value }}')" wire:loading.attr="disabled">{{ $label }}</x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>
        </div>
        <div class="pitch-summary"><strong>{{ $period }}</strong><span>{{ __('Zeitzone: Europe/Zurich') }}</span></div>
        <div data-cu-facility-planner data-props="{{ json_encode($payload, JSON_THROW_ON_ERROR) }}">
            <div wire:ignore data-cu-facility-canvas></div>
            <div data-cu-facility-fallback class="cu-veo-fallback">
                @forelse($entries as $entry)
                    <button type="button" wire:key="recording-{{ $entry['match_id'] }}" wire:click="editEntry('recording:{{ $entry['match_id'] }}')">
                        <time>{{ $entry['starts_at']->format('d.m.Y H:i') }} - {{ $entry['ends_at']->format('H:i') }}</time>
                        <strong>{{ $entry['title'] }}</strong><span>{{ $entry['camera'] ?? __('veo.no_camera') }}</span>
                        <span>{{ $payload['statuses'][$entry['status']] }}</span>
                    </button>
                @empty
                    <p class="pitch-empty">{{ __('veo.empty') }}</p>
                @endforelse
            </div>
        </div>
        <x-filament::modal id="veo-recording-editor" width="xl" :close-by-clicking-away="false">
            <x-slot name="heading">{{ $selected['title'] ?? trans('veo_calendar.recording') }}</x-slot>
            @if($selected)
                <p>{{ trans('veo_calendar.fixture', ['time' => $selected['fixture']]) }}</p>
                <p class="pitch-status-hint">{{ trans('veo_calendar.manual_notice') }}</p>
                @if($errors->any())<div class="pitch-alert" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
                <form wire:submit="saveRecording">
                    <div class="pitch-form">
                        <label>{{ trans('veo_calendar.date') }}<input type="date" required wire:model="form.date"></label>
                        <label>{{ trans('veo_calendar.time') }}<input type="time" required wire:model="form.time"></label>
                        <label>{{ trans('veo_calendar.duration') }}<input type="number" min="15" max="360" required wire:model="form.duration"></label>
                        <label>{{ trans('veo_calendar.camera') }}<select required wire:model="form.field_id"><option value="">{{ __('Auswählen') }}</option>@foreach($fields as $field)<option value="{{ $field['id'] }}">{{ $field['name'] }}{{ empty($cameraNames[$field['id']]) ? '' : ' / '.$cameraNames[$field['id']] }}</option>@endforeach</select></label>
                    </div>
                    <div class="pitch-modal-actions">
                        <x-filament::button color="gray" wire:click="closeEditor" type="button">{{ __('Abbrechen') }}</x-filament::button>
                        @if($selected['adjusted'])<x-filament::button color="gray" type="button" wire:click="saveRecording(true)" wire:loading.attr="disabled" icon="heroicon-o-arrow-uturn-left">{{ trans('veo_calendar.reset') }}</x-filament::button>@endif
                        <x-filament::button type="submit" wire:loading.attr="disabled" icon="heroicon-o-check">{{ trans('veo_calendar.confirm') }}</x-filament::button>
                    </div>
                </form>
            @endif
        </x-filament::modal>
    </div>
    <x-filament::section collapsible collapsed>
        <x-slot name="heading">{{ trans('veo_calendar.settings') }}</x-slot>
        <div class="cu-veo-settings">
            <section>
                <h2>{{ __('veo.cameras') }}</h2>
                @foreach($fields as $field)
                    <form wire:submit="saveCamera({{ $field['id'] }})" wire:key="veo-field-{{ $field['id'] }}">
                        <label for="veo-field-{{ $field['id'] }}">{{ $field['name'] }}</label>
                        <x-filament::input.wrapper><x-filament::input id="veo-field-{{ $field['id'] }}" type="text" maxlength="120" wire:model="cameraNames.{{ $field['id'] }}" /></x-filament::input.wrapper>
                        <x-filament::icon-button type="submit" icon="heroicon-o-check" :label="__('veo.save')" :tooltip="__('veo.save')" />
                    </form>
                @endforeach
            </section>
            <section>
                <h2>{{ __('veo.teams') }}</h2>
                @foreach($teams as $team)
                    <label class="cu-veo-team" wire:key="veo-team-{{ $team['id'] }}"><input type="checkbox" @checked(in_array((int) $team['id'], $enabledTeams, true)) wire:change="setTeam({{ $team['id'] }}, $event.target.checked)" wire:loading.attr="disabled">{{ $team['name'] }}</label>
                @endforeach
            </section>
        </div>
    </x-filament::section>
</x-filament-panels::page>

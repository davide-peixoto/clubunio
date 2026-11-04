<x-filament::modal id="pitch-entry" width="2xl" :close-by-clicking-away="false">
    <x-slot name="heading">{{ $editor === 'teams' ? __('Mannschaften zuordnen') : ($selected['title'] ?? match($editor) { 'request' => __('Trainingsspiel anfragen'), 'block' => __('Sperre erfassen'), 'manual' => __('Manuelle Belegung'), 'area' => __('Teilflächen verwalten'), default => __('Belegung bearbeiten') }) }}</x-slot>
    @if($editor)
        @php($editable = $editor !== 'edit' || ($selected['editable'] ?? false))
        @if($errors->any())<div class="pitch-alert" role="alert"><strong>{{ __('Nicht gespeichert') }}</strong>@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        @if($selected)
            <div class="pitch-original">
                <span class="pitch-status pitch-status-{{ $selected['status'] }}">{{ $statuses[$selected['status']] ?? $selected['status'] }}</span>
                <span>{{ __('Bisher: :date, :time, :field', ['date' => \Carbon\CarbonImmutable::parse($selected['date'])->format('d.m.Y'), 'time' => \Carbon\CarbonImmutable::parse($selected['starts_at'], 'Europe/Zurich')->setTimezone('Europe/Zurich')->format('H:i'), 'field' => $fields->get($selected['field_id'])['name'] ?? '']) }}</span>
                @if($selected['source'] === 'training')<span>{{ __('Einzeltermin vom :date', ['date' => \Carbon\CarbonImmutable::parse($selected['original_date'])->format('d.m.Y')]) }}</span>@endif
            </div>
            @if($selected['conflicts'])
                <div class="pitch-alert" role="status"><strong>{{ __('Konflikte') }}</strong><ul>@foreach($selected['conflicts'] as $conflict)<li>{{ match($conflict['type']) { 'field_unavailable' => __('Spielfeld gesperrt'), 'block' => __('Zeitliche Sperre'), default => __('Überlappende Belegung') } }}</li>@endforeach</ul></div>
            @endif
        @endif
        <form wire:submit="saveEntry">
            <fieldset class="pitch-form" @disabled(!$editable)>
                @if($editor === 'area')
                    @include('clubunio.pitch-planning.area-editor')
                @elseif($editor === 'teams')
                    <fieldset class="pitch-wide pitch-team-choices"><legend>{{ __('Mannschaften') }}</legend>
                        @foreach($options['teams'] as $team)
                            <label><input type="checkbox" value="{{ $team['id'] }}" wire:model="form.team_season_ids"> {{ $team['name'] }}</label>
                        @endforeach
                    </fieldset>
                @else
                <label>{{ __('Spielfeld') }}<select wire:model.live="form.field_id" required><option value="">{{ __('Auswählen') }}</option>@foreach($options['fields'] as $field)<option value="{{ $field['id'] }}">{{ $field['name'] }}{{ !$field['available'] ? ' / '.__('Gesperrt') : '' }}</option>@endforeach</select></label>
                <label>{{ __('Teilfläche') }}<select wire:model="form.area_id"><option value="">{{ __('Ganzer Platz') }}</option>@foreach($areas as $area)<option value="{{ $area['id'] }}">{{ $area['name'] }}</option>@endforeach</select></label>
                <label>{{ __('Datum') }}<input type="date" required wire:model="form.date"></label>
                <label>{{ __('Beginn') }}<input type="time" required wire:model="form.starts_at"></label>
                <label>{{ __('Dauer in Minuten') }}<input type="number" min="1" max="{{ $editor === 'block' || ($selected['source'] ?? '') === 'block' ? 44640 : 1440 }}" step="1" required wire:model="form.duration_minutes"></label>
                @if($editor === 'request' || $editor === 'manual')
                    <label>{{ __('Mannschaft') }}<select wire:model="form.team_season_id" required>@foreach($options['teams'] as $team)<option value="{{ $team['id'] }}">{{ $team['name'] }}</option>@endforeach</select></label>
                    <label class="pitch-wide">{{ __('Gegner') }}<input type="text" maxlength="255" required wire:model="form.opponent"></label>
                    <label class="pitch-wide">{{ __('Bemerkung') }}<textarea rows="3" maxlength="2000" wire:model="form.notes"></textarea></label>
                @elseif($editor === 'block' || ($selected['source'] ?? '') === 'block')
                    <label class="pitch-wide">{{ __('Grund der Sperre') }}<textarea rows="3" maxlength="255" required wire:model="form.reason"></textarea></label>
                @endif
                @endif
            </fieldset>
            <div class="pitch-modal-actions">
                <x-filament::button type="button" wire:click="closeEditor" color="gray">{{ __('Abbrechen') }}</x-filament::button>
                @if($editor === 'edit' && ($selected['source'] ?? '') === 'competition' && $this->capability('manage'))
                    <x-filament::button type="button" wire:click="editCompetitionTeams(@js($selected['key']))" wire:loading.attr="disabled" color="gray" icon="heroicon-o-user-group">{{ __('Mannschaften zuordnen') }}</x-filament::button>
                @endif
                @if($selected && $selected['editable'] && in_array($selected['source'], ['training', 'request', 'block'], true) && $this->capability('manage'))
                    <x-filament::button type="button" wire:click="cancelEntry" wire:confirm="{{ __('Diese Belegung verbindlich absagen?') }}" wire:loading.attr="disabled" color="danger" icon="heroicon-o-no-symbol">{{ __('Absagen') }}</x-filament::button>
                @endif
                @if($selected && $selected['source'] === 'request' && $selected['status'] === 'pending' && $this->capability('approve'))
                    <x-filament::button type="button" wire:click="decideEntry('rejected')" wire:confirm="{{ __('Anfrage verbindlich ablehnen?') }}" wire:loading.attr="disabled" color="danger" icon="heroicon-o-x-mark">{{ __('Ablehnen') }}</x-filament::button>
                    <x-filament::button type="button" wire:click="decideEntry('approved')" wire:confirm="{{ __('Anfrage verbindlich genehmigen?') }}" wire:loading.attr="disabled" color="success" icon="heroicon-o-check">{{ __('Genehmigen') }}</x-filament::button>
                @endif
                @if($editable)<x-filament::button type="submit" wire:loading.attr="disabled" icon="heroicon-o-check">{{ $editor === 'edit' ? __('Verschiebung bestätigen') : ($editor === 'request' ? __('Anfrage senden') : __('Speichern')) }}</x-filament::button>@endif
            </div>
        </form>
    @endif
</x-filament::modal>

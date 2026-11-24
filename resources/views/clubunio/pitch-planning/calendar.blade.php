        @if($options['fields'] && $mode === 'day')
            <div class="pitch-scroll" tabindex="0" role="region" aria-label="{{ __('Tagesplan nach Spielfeld') }}" x-init="$nextTick(() => $el.scrollTop = 720)" x-on:dragover.prevent="updateMove($event)">
                <div class="pitch-board" style="grid-template-columns: 60px repeat({{ count($columns) }}, minmax(240px, 1fr))">
                    <div class="pitch-heading pitch-time-heading">{{ __('Zeit') }}</div>
                    @foreach($columns as $column)
                        <div class="pitch-heading"><strong>{{ $column['field']['name'] }}</strong>@if(!$column['field']['available'])<span class="pitch-status pitch-status-cancelled">{{ __('Gesperrt') }}</span>@endif</div>
                    @endforeach
                    <div class="pitch-hours">@for($hour = 0; $hour < 24; $hour++)<span style="top: {{ $hour * 90 }}px">{{ sprintf('%02d:00', $hour) }}</span>@endfor</div>
                    @foreach($columns as $column)
                        <div class="pitch-column" data-pitch-field="{{ $column['field']['id'] }}" wire:key="pitch-field-{{ $column['field']['id'] }}-{{ $date }}"
                            x-on:pointerdown="startRange($event, {{ $column['field']['id'] }}, @js($date))"
                            x-on:pointermove="extendRange($event)" x-on:pointerup="finishRange($event)" x-on:pointercancel="cancelPointerRange($event)" x-on:lostpointercapture="cancelPointerRange($event)" x-on:drop.prevent="dropMove($event, @js($date))">
                            @for($minute = 0; $minute < 1440; $minute += 30)
                                @php($slotTime = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60))
                                <div class="pitch-drop" style="top: {{ $minute * 1.5 }}px">
                                    @if($this->capability('request'))
                                        <button type="button" class="pitch-slot" data-pitch-minute="{{ $minute }}" x-on:click="activateSlot($event, {{ $column['field']['id'] }}, @js($date), {{ $minute }})" wire:loading.attr="disabled" aria-label="{{ __('Request :field on :date at :time', ['field' => $column['field']['name'], 'date' => $days[0]['date']->format('d.m.Y'), 'time' => $slotTime]) }}"></button>
                                    @endif
                                </div>
                            @endfor
                            <div class="pitch-range" x-cloak x-show="selection && selection.field === {{ $column['field']['id'] }}" :style="rangeStyle({{ $column['field']['id'] }})" x-text="rangeText(@js(__(':start–:end · :minutes min')))" role="status"></div>
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

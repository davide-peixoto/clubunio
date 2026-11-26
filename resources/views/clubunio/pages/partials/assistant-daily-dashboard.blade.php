<section class="cu-assistant-overview" aria-label="{{ __('Daily overview') }}">
    <header class="cu-assistant-overview-heading">
        <h3>{{ __('Daily overview') }}</h3>
        @if ($dashboard['date']) <time>{{ $dashboard['date'] }}</time> @endif
    </header>
    <p class="cu-assistant-overview-scope">{{ trans('assistant_chat.current_work') }}</p>
    <div class="cu-assistant-kpis" role="group" aria-label="{{ trans('assistant_chat.key_figures') }}">
        @foreach ($dashboard['tiles'] as $tile)
            <section class="cu-assistant-kpi" data-module="{{ $tile['module'] }}">
                <div>@svg($tile['icon']) <h4>{{ $tile['heading'] }}</h4></div>
                <strong>{{ $tile['count'] }}</strong>
                <p>{{ $tile['caption'] }}</p>
            </section>
        @endforeach
    </div>
    <div class="cu-assistant-day-reservations">
        <h4>@svg('heroicon-o-calendar-days') {{ trans('assistant_chat.selected_day') }}</h4>
        @foreach ($dashboard['tiles'] as $tile)
            @if (in_array($tile['module'], ['bus', 'pitch'], true))
                <span>{{ $tile['title'] }} <strong>{{ $tile['day_count'] }}</strong></span>
            @endif
        @endforeach
    </div>
    <h4 class="cu-assistant-overview-list-title">{{ trans('assistant_chat.work_lists') }}</h4>
    <div class="cu-assistant-work-lists">
        @foreach ($dashboard['tiles'] as $tile)
            <details class="cu-assistant-work-list" data-overview-list="{{ $tile['module'] }}">
                <summary><span>@svg($tile['icon']) {{ $tile['title'] }}</span><span class="cu-assistant-list-count">{{ $tile['matching'] }}</span>@svg('heroicon-o-chevron-down')</summary>
                <p class="cu-assistant-work-coverage">{{ trans(in_array($tile['module'], ['bus', 'pitch'], true) ? 'assistant_chat.day_sample' : 'assistant_chat.work_sample', ['shown' => count($tile['rows']), 'total' => $tile['matching']]) }}</p>
                @forelse ($tile['rows'] as $row)
                    <div class="cu-assistant-work-row">
                        <div>
                            @php($link = $this->resultUrl($row['url'] ?? null))
                            @if ($link)
                                <a href="{{ $link }}">{{ $this->displayValue($row['label'] ?? __('Open result')) }} @svg('heroicon-o-arrow-up-right')</a>
                            @else
                                <span>{{ $this->displayValue($row['label'] ?? __('Open result')) }}</span>
                            @endif
                            @if (isset($row['status_label'])) <small>{{ $this->displayValue($row['status_label']) }}</small> @endif
                        </div>
                        <div class="cu-assistant-work-value">
                            @if (isset($row['open_amount']) || isset($row['amount']))
                                {{ $this->overviewAmount($row['open_amount'] ?? $row['amount'], $row['currency'] ?? null) }}
                            @elseif (isset($row['submitted_on']))
                                {{ $this->displayValue($row['submitted_on']) }}
                            @elseif (isset($row['starts_at']))
                                {{ $this->displayValue($row['starts_at']) }}
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="cu-assistant-work-empty">{{ trans('assistant_chat.no_sample') }}</p>
                @endforelse
            </details>
        @endforeach
    </div>
    <details class="cu-assistant-overview-source">
        <summary>@svg('heroicon-o-information-circle') {{ trans('assistant_chat.data_basis') }}</summary>
        <p>{{ $result['summary'] ?? '' }}</p>
        <p>{{ trans('assistant_chat.coverage_note') }}</p>
        <div class="cu-assistant-counts" role="group" aria-label="{{ __('Module counts') }}">
            @foreach ($dashboard['tiles'] as $group)
                <section data-assistant-count-module="{{ $group['module'] }}" aria-label="{{ $group['title'] }}">
                    <h4>{{ $group['title'] }}</h4>
                    <dl>
                        @foreach ($group['metrics'] as $metric)
                            <div data-assistant-count="{{ $metric['key'] }}"><dt>{{ $metric['label'] }}</dt><dd>{{ $metric['value'] }}</dd></div>
                        @endforeach
                    </dl>
                </section>
            @endforeach
        </div>
    </details>
</section>

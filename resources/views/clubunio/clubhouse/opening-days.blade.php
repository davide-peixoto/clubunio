<ol class="cu-ch-days">@foreach($days as $day)
    <li><time datetime="{{ $day['date'] }}">{{ \Carbon\CarbonImmutable::parse($day['date'])->locale(app()->getLocale())->isoFormat('ddd, DD.MM.') }}</time>
        <div>@forelse($day['intervals'] as $slot)<span>{{ \Carbon\CarbonImmutable::parse($slot['start'])->format('H:i') }} – {{ substr($slot['end'], 0, 10) !== $day['date'] ? '24:00' : \Carbon\CarbonImmutable::parse($slot['end'])->format('H:i') }}</span>@empty<span class="cu-ch-muted">{{ trans('clubhouse.closed') }}</span>@endforelse</div>
        @if($day['exception'])<small>{{ trans('clubhouse.special_hours') }}</small>@endif
    </li>
@endforeach</ol>

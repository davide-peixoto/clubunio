@extends('clubunio.bus.layout')
@if(request()->attributes->get('clubunioWorkspace'))
@section('native-content')
    @livewire('club-unio.bus-reservations-table', ['tenantId' => (int) $tenant->id, 'filters' => $filters])
@endsection
@else
@section('content')
<div class="page-heading"><h1>{{ __('Busreservierungen') }}</h1>@if($teams->isNotEmpty() && \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'bus', 'create'))<a class="primary" href="{{ route('clubunio.bus.create',$tenant->slug) }}">@svg('heroicon-o-plus') {{ __('Reservation erfassen') }}</a>@endif</div>
<form method="get" class="toolbar">
<label>{{ __('Bus') }}<select name="bus"><option value="">{{ __('Alle Busse') }}</option>@foreach($buses as $bus)<option value="{{ $bus->id }}" @selected(($filters['bus']??'')==$bus->id)>{{ $bus->name }}</option>@endforeach</select></label>
<label>{{ __('Mannschaft') }}<select name="team"><option value="">{{ __('Alle Mannschaften') }}</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(($filters['team']??'')==$team->id)>{{ $team->label }}</option>@endforeach</select></label>
<label>{{ __('Suche') }}<input type="search" name="q" value="{{ $filters['q']??'' }}"></label>
<label>{{ __('Von') }}<input type="date" name="from" value="{{ $filters['from']??'' }}"></label><label>{{ __('Bis') }}<input type="date" name="to" value="{{ $filters['to']??'' }}"></label>
<input type="hidden" name="status" value="{{ $filters['status']??'' }}"><button class="icon-button" title="{{ __('Filtern') }}" aria-label="{{ __('Filtern') }}">@svg('heroicon-o-funnel')</button><a href="{{ route('clubunio.bus.index',$tenant->slug) }}">{{ __('Zurücksetzen') }}</a>
</form>
<nav class="planning-tabs" aria-label="{{ __('Reservierungsstatus') }}"><a href="{{ request()->fullUrlWithQuery(['status'=>null,'page'=>null]) }}" @if(empty($filters['status'])) aria-current="page" @endif>{{ __('Alle (') }}{{ $counts->sum() }})</a>@foreach($statuses as $code=>$status)<a href="{{ request()->fullUrlWithQuery(['status'=>$code,'page'=>null]) }}" @if(($filters['status']??'')===$code) aria-current="page" @endif>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('bus_statuses', $status, tenant: $tenant->id ?? null) }} ({{ $counts[$code]??0 }})</a>@endforeach</nav>
<div class="table-scroll"><table class="cu-admin-journal"><thead><tr><th>{{ __('Von') }}</th><th>{{ __('Bis') }}</th><th>{{ __('Bus') }}</th><th>{{ __('Mannschaft') }}</th><th>{{ __('Fahrtziel') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>
@forelse($rows as $row)<tr><td><a class="reservation-link" href="{{ route('clubunio.bus.show',[$tenant->slug,$row->id]) }}">{{ \Carbon\Carbon::parse($row->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</a></td><td>{{ \Carbon\Carbon::parse($row->ends_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</td><td>{{ $busNames[$row->bus_id] ?? '' }}</td><td>{{ $teamLabels[$row->team_season_id] ?? __('Nicht zugeordnet') }}</td><td>{{ $row->location }}</td><td>@include('clubunio.bus.badge',['code'=>$row->status])</td></tr>@empty<tr><td colspan="6">{{ __('Keine Reservationen gefunden.') }}</td></tr>@endforelse
</tbody></table></div>{{ $rows->links('pagination::simple-default') }}
@endsection
@endif

@extends('clubunio.planning.layout')
@section(request()->attributes->get('clubunioWorkspace') ? 'native-content' : 'content')
@if(request()->attributes->get('clubunioWorkspace'))
@livewire(\App\Livewire\ClubUnio\TeamPlanningTable::class, ['tenantId' => (int) $tenant->id, 'initialFilters' => $filters])
@else
@php($profileAllows = fn (string $function) => \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'planning', $function))
<div class="page-heading"><h1>{{ __('Teamplanung') }}</h1>@if(count($managed) && $profileAllows('create'))<a class="primary" href="{{ route('clubunio.planning.create',$tenant->slug) }}">@svg('heroicon-o-plus') {{ __('Termin erstellen') }}</a>@endif</div>
<form method="get" class="toolbar">
<label>{{ __('Mannschaft') }}<select name="team"><option value="">{{ __('Alle Mannschaften') }}</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(($filters['team']??'')==$team->id)>{{ $team->label }}</option>@endforeach</select></label>
<label>{{ __('Suche') }}<input name="q" value="{{ $filters['q']??'' }}" type="search"></label>
<label>{{ __('Von') }}<input name="from" type="date" value="{{ $filters['from']??'' }}"></label><label>{{ __('Bis') }}<input name="to" type="date" value="{{ $filters['to']??'' }}"></label>
<input type="hidden" name="status" value="{{ $filters['status']??'' }}"><button class="icon-button" title="{{ __('Filtern') }}" aria-label="{{ __('Filtern') }}">@svg('heroicon-o-funnel')</button><a href="{{ route('clubunio.planning.index',$tenant->slug) }}">{{ __('Zurücksetzen') }}</a>
</form>
<nav class="planning-tabs" aria-label="{{ __('Terminstatus') }}"><a href="{{ request()->fullUrlWithQuery(['status'=>null,'page'=>null]) }}" @if(empty($filters['status'])) aria-current="page" @endif>{{ __('Alle (') }}{{ $counts->sum() }})</a>
@foreach($options['status']??[] as $code=>$option)<a href="{{ request()->fullUrlWithQuery(['status'=>$code,'page'=>null]) }}" @if(($filters['status']??'')===$code) aria-current="page" @endif>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('planning_options', $option, tenant: $tenant->id ?? null) }} ({{ $counts[$code]??0 }})</a>@endforeach</nav>
<div class="table-scroll"><table><thead><tr><th>{{ __('Beginn') }}</th><th>{{ __('Termin') }}</th><th>{{ __('Mannschaft') }}</th><th>{{ __('Ort') }}</th><th>{{ __('Status') }}</th><th>{{ __('Frist') }}</th></tr></thead><tbody>
@forelse($events as $event)<tr><td>{{ \Carbon\Carbon::parse($event->starts_at)->tz('Europe/Zurich')->format('d.m.Y H:i') }}</td><td><a href="{{ route('clubunio.planning.show',[$tenant->slug,$event->id]) }}">{{ $event->title }}</a><small>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('planning_options', ($options['kind'][$event->kind] ?? ['name' => $event->kind]), tenant: $tenant->id ?? null) }}</small></td><td>{{ $teams->firstWhere('id',$event->team_season_id)?->label }}</td><td>{{ $event->location }}</td><td>@include('clubunio.planning.badge',['category'=>'status','code'=>$event->status])</td><td>{{ $event->deadline_at ? \Carbon\Carbon::parse($event->deadline_at)->tz('Europe/Zurich')->format('d.m.Y H:i') : __('Keine Frist') }}</td></tr>
@empty<tr><td colspan="6">{{ __('Keine Termine gefunden.') }}</td></tr>@endforelse
</tbody></table></div>{{ $events->links('pagination::simple-default') }}
@endif
@endsection

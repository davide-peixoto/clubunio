@extends('clubunio.planning.layout')
@section(request()->attributes->get('clubunioWorkspace') ? 'native-content' : 'content')
@if(request()->attributes->get('clubunioWorkspace'))
@livewire(\App\Livewire\ClubUnio\TrainingPlansTable::class, ['tenantId' => (int) $tenant->id, 'initialFilters' => $filters])
@else
@php($teamLabels = $teams->pluck('label', 'id'))
<div class="page-heading"><h1>{{ __('Trainingspläne') }}</h1>@if(\App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'training', 'create'))<a class="primary" href="{{ route('clubunio.training.create',$tenant->slug) }}">@svg('heroicon-o-plus') {{ __('Neuer Trainingsplan') }}</a>@endif</div>
@if($actor->canAccessTenant($tenant) && \App\ClubUnio\Resources\ProfileUi::profileAllows($actor, $tenant, 'training', 'view'))<a href="{{ \App\ClubUnio\Resources\LegacyTrainingPlanResource::getUrl() }}">{{ __('Altpläne prüfen und zuordnen') }}</a>@endif
<form class="toolbar" method="get"><label>{{ __('Mannschaft') }}<select name="team"><option value="">{{ __('Alle Mannschaften') }}</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected(request('team') == $team->id)>{{ $team->label }}</option>@endforeach</select></label><label>{{ __('Bestand') }}<select name="archived"><option value="0">{{ __('Aktuell') }}</option><option value="1" @selected(request('archived') === '1')>{{ __('Archiv') }}</option></select></label><label>{{ __('Suche') }}<input name="q" value="{{ request('q') }}" maxlength="100"></label><button class="icon-button" title="{{ __('Suchen') }}" aria-label="{{ __('Suchen') }}">@svg('heroicon-o-magnifying-glass')</button></form>
<div class="table-scroll"><table><thead><tr><th>{{ __('Training') }}</th><th>{{ __('Mannschaft') }}</th><th>{{ __('Freigabe') }}</th><th>{{ __('Geändert') }}</th></tr></thead><tbody>
@forelse($plans as $row)<tr><td><a href="{{ route('clubunio.training.show',[$tenant->slug,$row->id]) }}">{{ $row->title }}</a></td><td>{{ $teamLabels[$row->team_season_id] ?? null }}</td><td>{{ $row->shared ? __('Teamverantwortliche') : __('Privat') }}</td><td>{{ \Carbon\CarbonImmutable::parse($row->updated_at)->setTimezone('Europe/Zurich')->format('d.m.Y H:i') }}</td></tr>@empty<tr><td colspan="4">{{ __('Keine Trainingspläne vorhanden.') }}</td></tr>@endforelse
</tbody></table></div>{{ $plans->links('clubunio.planning.pagination') }}
@endif
@endsection

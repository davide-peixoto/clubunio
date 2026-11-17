@extends('clubunio.portal.layout')
@section('content')
<div class="page-heading"><h1>{{ __('Sponsorenlauf') }}</h1></div>
<div class="cu-portal-list">
<form method="get" class="toolbar"><label>{{ __('Saison') }}<select name="season_id"><option value="">{{ __('Alle') }}</option>@foreach($seasons as $id=>$name)<option value="{{ $id }}" @selected(($filters['season_id']??null)==$id)>{{ $name }}</option>@endforeach</select></label><label>{{ __('Ansicht') }}<select name="scope">@foreach(['mine'=>__('Meine Familie / Ich'),'teams'=>__('Meine Teams'),'helper'=>__('Helfereinsatz'),'manage'=>__('Verwaltung'),'all'=>__('Alle Berechtigungen')] as $key=>$label)<option value="{{ $key }}" @selected($filters['scope']===$key)>{{ $label }}</option>@endforeach</select></label><label>{{ __('Suche') }}<input type="search" name="search" value="{{ $filters['search']??'' }}" maxlength="100"></label><button class="icon-button" type="submit" title="{{ __('Filtern') }}" aria-label="{{ __('Filtern') }}">@svg('heroicon-o-funnel')</button><a class="cu-portal-reset" href="{{ route('clubunio.portal.sponsor-runs', $tenant->slug) }}">{{ __('Zurücksetzen') }}</a></form>
<div class="table-scroll"><table>
    <thead><tr><th scope="col">{{ __('Anlass') }}</th><th scope="col">{{ __('Datum') }}</th><th scope="col">{{ __('Ort') }}</th><th scope="col">{{ trans('portal-shell.pledge_deadline') }}</th></tr></thead>
    <tbody>
        @forelse($events as $event)
            <tr><td><a href="{{ route('clubunio.portal.sponsor-run', [$tenant->slug, $event->id, 'scope' => $filters['scope']]) }}">{{ $event->title }}</a></td><td>{{ $event->event_date->format('d.m.Y') }}</td><td>{{ $event->place }}</td><td>{{ $event->registration_deadline->format('d.m.Y') }}</td></tr>
        @empty
            @include('clubunio.portal.empty-row', ['columns' => 4, 'icon' => 'heroicon-o-flag', 'message' => __('Keine Anlässe.')])
        @endforelse
    </tbody>
</table></div>
{{ $events->links('pagination::simple-default') }}
</div>
@endsection

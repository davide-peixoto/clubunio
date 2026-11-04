@extends('clubunio.portal.layout')
@section('content')
<h1>{{ __('Sponsorenlauf') }}</h1>
<form method="get"><label>{{ __('Saison') }}<select name="season_id"><option value="">{{ __('Alle') }}</option>@foreach($seasons as $id=>$name)<option value="{{ $id }}" @selected(($filters['season_id']??null)==$id)>{{ $name }}</option>@endforeach</select></label><label>{{ __('Ansicht') }}<select name="scope">@foreach(['mine'=>__('Meine Familie / Ich'),'teams'=>__('Meine Teams'),'helper'=>__('Helfereinsatz'),'manage'=>__('Verwaltung'),'all'=>__('Alle Berechtigungen')] as $key=>$label)<option value="{{ $key }}" @selected($filters['scope']===$key)>{{ $label }}</option>@endforeach</select></label><label>{{ __('Suche') }}<input name="search" value="{{ $filters['search']??'' }}" maxlength="100"></label><button class="icon-button" type="submit" title="{{ __('Suchen') }}" aria-label="{{ __('Suchen') }}">@svg('heroicon-o-magnifying-glass')</button></form>
@forelse($events as $event)<article class="notification-row"><h2><a href="{{ route('clubunio.portal.sponsor-run',[$tenant->slug,$event->id,'scope'=>$filters['scope']]) }}">{{ $event->title }}</a></h2><p>{{ $event->event_date->format('d.m.Y') }} · {{ $event->place }}</p></article>@empty<p>{{ __('Keine Anlässe.') }}</p>@endforelse
{{ $events->links('pagination::simple-default') }}
@endsection

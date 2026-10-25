@extends('clubunio.portal.layout')
@section('content')
<h1>Sponsorenlauf</h1>
<form method="get"><label>Saison<select name="season_id"><option value="">Alle</option>@foreach($seasons as $id=>$name)<option value="{{ $id }}" @selected(($filters['season_id']??null)==$id)>{{ $name }}</option>@endforeach</select></label><label>Ansicht<select name="scope">@foreach(['mine'=>'Meine Familie / Ich','teams'=>'Meine Teams','helper'=>'Helfereinsatz','manage'=>'Verwaltung','all'=>'Alle Berechtigungen'] as $key=>$label)<option value="{{ $key }}" @selected($filters['scope']===$key)>{{ $label }}</option>@endforeach</select></label><label>Suche<input name="search" value="{{ $filters['search']??'' }}" maxlength="100"></label><button class="icon-button" type="submit" title="Suchen" aria-label="Suchen">@svg('heroicon-o-magnifying-glass')</button></form>
@forelse($events as $event)<article class="notification-row"><h2><a href="{{ route('clubunio.portal.sponsor-run',[$tenant->slug,$event->id,'scope'=>$filters['scope']]) }}">{{ $event->title }}</a></h2><p>{{ $event->event_date->format('d.m.Y') }} · {{ $event->place }}</p></article>@empty<p>Keine Anlässe.</p>@endforelse
{{ $events->links('pagination::simple-default') }}
@endsection

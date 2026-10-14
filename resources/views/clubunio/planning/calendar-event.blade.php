<article class="calendar-event">
<time>{{ \Carbon\Carbon::parse($event->starts_at)->tz('Europe/Zurich')->format('d.m. H:i') }}–{{ \Carbon\Carbon::parse($event->ends_at)->tz('Europe/Zurich')->format('d.m. H:i') }}</time>
<a href="{{ route('clubunio.planning.show',[$tenant->slug,$event->id]) }}">{{ $event->title }}</a>
<small>{{ $teams->firstWhere('id',$event->team_season_id)?->label }}</small>
@if($event->location)<small>{{ $event->location }}</small>@endif
@include('clubunio.planning.badge',['category'=>'status','code'=>$event->status])
@if(!empty($conflicts[$event->id]))<details class="calendar-conflict"><summary>@svg('heroicon-o-exclamation-triangle') Überschneidung ({{ count($conflicts[$event->id]) }})</summary>@foreach($conflicts[$event->id] as $conflict)<a href="{{ route('clubunio.planning.show',[$tenant->slug,$conflict->id]) }}">{{ $conflict->title }} · {{ \Carbon\Carbon::parse($conflict->starts_at)->tz('Europe/Zurich')->format('d.m. H:i') }}</a>@endforeach</details>@endif
</article>

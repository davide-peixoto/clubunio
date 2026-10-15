@extends('clubunio.portal.layout')
@section('content')
<div class="page-heading"><h1>{{ $announcement->rendered_title }}</h1><a class="icon-button" href="{{ route('clubunio.portal.announcements',$tenant->slug) }}" aria-label="Ankündigungen" title="Ankündigungen">@svg('heroicon-o-arrow-left')</a></div>
<time>{{ $announcement->published_at->timezone('Europe/Zurich')->format('d.m.Y H:i') }}</time>
<p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $announcement->rendered_body }}</p>
@endsection

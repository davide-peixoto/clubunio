@extends('clubunio.portal.layout')
@section('content')
<h1>Ankündigungen</h1>
@forelse($announcements as $announcement)
<article class="notification-row"><h2><a href="{{ route('clubunio.portal.announcement',[$tenant->slug,$announcement->id]) }}">{{ $announcement->rendered_title }}</a></h2>
<time>{{ $announcement->published_at->timezone('Europe/Zurich')->format('d.m.Y H:i') }}</time><p>{{ \Illuminate\Support\Str::limit($announcement->rendered_body,200) }}</p></article>
@empty<p>Keine Ankündigungen vorhanden.</p>@endforelse
<div class="pagination">{{ $announcements->links('pagination::simple-default') }}</div>
@endsection

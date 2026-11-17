@extends('clubunio.portal.layout')
@section('content')
<div class="page-heading"><h1>{{ __('Ankündigungen') }}</h1></div>
<div class="cu-portal-list">
@forelse($announcements as $announcement)
<article class="notification-row"><h2><a href="{{ route('clubunio.portal.announcement',[$tenant->slug,$announcement->id]) }}">{{ $announcement->rendered_title }}</a></h2>
<time>{{ $announcement->published_at->timezone('Europe/Zurich')->format('d.m.Y H:i') }}</time><p>{{ \Illuminate\Support\Str::limit($announcement->rendered_body,200) }}</p></article>
@empty
    @include('clubunio.portal.empty-state', ['icon' => 'heroicon-o-megaphone', 'message' => __('Keine Ankündigungen vorhanden.')])
@endforelse
{{ $announcements->links('pagination::simple-default') }}
</div>
@endsection

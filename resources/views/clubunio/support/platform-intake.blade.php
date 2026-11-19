@extends('clubunio.platform.layout')
@section('title', trans('support.workspace.sign_in_requests').' · ClubUnio')
@push('head')
<link rel="stylesheet" href="{{ asset('css/clubunio-support-platform.css') }}?v=1">
@endpush
@section('content')
<div class="platform-heading sp-heading">
    <div><span class="platform-eyebrow">{{ trans('support.title') }}</span><h1>{{ trans('support.workspace.sign_in_requests') }}</h1></div>
    <a class="sp-button" href="{{ route('clubunio.platform.support') }}">@svg('heroicon-o-arrow-left') {{ trans('support.workspace.back_to_inbox') }}</a>
</div>
<section class="sp-reading" aria-label="{{ trans('support.workspace.sign_in_requests') }}">
    @forelse($rows as $row)
        @php($entry = json_decode(decrypt($row->payload), true, flags: JSON_THROW_ON_ERROR))
        <article class="sp-message">
            <header class="sp-ticket-line"><strong>{{ $entry['email'] }}</strong>
                <time class="sp-meta" datetime="{{ \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($row->created_at)->timezone('Europe/Zurich')->locale(app()->getLocale())->isoFormat('L LT') }}</time>
            </header>
            <p>{{ $entry['body'] }}</p>
        </article>
    @empty
        <p class="sp-empty">{{ trans('support.workspace.no_sign_in_requests') }}</p>
    @endforelse
</section>
@endsection

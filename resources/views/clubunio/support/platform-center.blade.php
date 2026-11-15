@extends('clubunio.platform.layout')
@section('title', trans('support.title').' · ClubUnio')
@push('head')
<link rel="stylesheet" href="{{ asset('css/clubunio-support-platform.css') }}?v=1">
@endpush
@section('content')
@php($inbox = route('clubunio.platform.support'))
<div class="platform-heading sp-heading">
    <div><span class="platform-eyebrow">{{ trans('support.workspace.platform') }}</span><h1>{{ trans('support.title') }}</h1></div>
    <a class="sp-button" href="{{ route('clubunio.support.platform-intake') }}">
        @svg('heroicon-o-inbox-arrow-down') {{ trans('support.workspace.sign_in_requests') }}
    </a>
</div>
<nav class="sp-tabs" aria-label="{{ trans('support.title') }}">
    @foreach(['tickets' => 'inbox-stack', 'knowledge' => 'book-open', 'assistant' => 'sparkles'] as $key => $icon)
        <a href="{{ $key === 'tickets' ? $inbox : route('clubunio.platform.support', ['tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif>
            @svg('heroicon-o-'.$icon) {{ trans('support.workspace.'.$key) }}
        </a>
    @endforeach
</nav>
@if($tab === 'tickets')
    <div class="sp-desk {{ $selected ? 'sp-has-ticket' : '' }}">
        <section class="sp-inbox" aria-labelledby="sp-inbox-title">
            <div class="sp-panel-head"><h2 id="sp-inbox-title">{{ trans('support.workspace.inbox') }}</h2></div>
            <form class="sp-filters" method="get" action="{{ $inbox }}">
                <label>{{ trans('support.workspace.ticket_search') }}<input type="search" name="q" maxlength="120" value="{{ $filters['q'] ?? '' }}"></label>
                <label>{{ trans('support.workspace.status') }}<select name="status">
                    <option value="">{{ trans('support.workspace.all_statuses') }}</option>
                    @foreach(['open', 'waiting', 'closed'] as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ trans('support.'.$status) }}</option>
                    @endforeach
                </select></label>
                <label>{{ trans('support.assignee') }}<select name="assignee">
                    <option value="">{{ trans('support.workspace.all_assignees') }}</option>
                    <option value="unassigned" @selected(($filters['assignee'] ?? '') === 'unassigned')>{{ trans('support.unassigned') }}</option>
                    @foreach($assignees as $person)
                        <option value="{{ $person->id }}" @selected((string)($filters['assignee'] ?? '') === (string)$person->id)>{{ $person->display_name }}</option>
                    @endforeach
                </select></label>
                <button class="icon-button" title="{{ trans('support.workspace.apply_filters') }}" aria-label="{{ trans('support.workspace.apply_filters') }}">@svg('heroicon-o-magnifying-glass')</button>
                @if(array_diff_key($filters, ['page' => true]))<a class="icon-button" href="{{ $inbox }}" title="{{ trans('support.workspace.clear_filters') }}" aria-label="{{ trans('support.workspace.clear_filters') }}">@svg('heroicon-o-x-mark')</a>@endif
            </form>
            <ul class="sp-ticket-list">
                @forelse($rows as $row)
                    <li><a href="{{ route('clubunio.platform.support', $filters + ['ticket' => $row->id]) }}#ticket-detail" @if($selected?->id === $row->id) aria-current="true" @endif>
                        <div class="sp-ticket-line"><strong>{{ $row->subject }}</strong><span class="sp-status sp-status-{{ $row->status }}">{{ trans('support.'.$row->status) }}</span></div>
                        <div class="sp-ticket-line sp-meta"><span>{{ $row->club_name }}</span><time datetime="{{ \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($row->created_at)->timezone('Europe/Zurich')->locale(app()->getLocale())->isoFormat('L LT') }}</time></div>
                        <div class="sp-meta">{{ $row->assignee_name ?? trans('support.unassigned') }}</div>
                    </a></li>
                @empty
                    <li class="sp-empty">@svg('heroicon-o-inbox')<p>{{ trans(isset($filters['q']) || isset($filters['status']) || isset($filters['assignee']) ? 'support.workspace.no_matches' : 'support.workspace.no_tickets') }}</p></li>
                @endforelse
            </ul>
            @if($rows->previousPageUrl() || $rows->nextPageUrl())
                <nav class="sp-pagination" aria-label="{{ trans('support.workspace.inbox') }}">
                    @if($rows->previousPageUrl())<a class="sp-button" href="{{ $rows->previousPageUrl() }}">@svg('heroicon-o-chevron-left') {{ trans('support.workspace.previous') }}</a>@endif
                    @if($rows->nextPageUrl())<a class="sp-button" href="{{ $rows->nextPageUrl() }}">{{ trans('support.workspace.next') }} @svg('heroicon-o-chevron-right')</a>@endif
                </nav>
            @endif
        </section>
        <section class="sp-detail" id="ticket-detail" aria-labelledby="sp-detail-title">
            @if($selected)
                @include('clubunio.support.platform-ticket')
            @else
                <div class="sp-empty">@svg('heroicon-o-chat-bubble-left-right')<h2 id="sp-detail-title">{{ trans('support.workspace.select_ticket') }}</h2></div>
            @endif
        </section>
    </div>
@elseif($tab === 'knowledge')
    @include('clubunio.support.platform-knowledge')
@else
    <section class="sp-reading" aria-labelledby="sp-assistant-title">
        <h2 id="sp-assistant-title">{{ trans('support.workspace.assistant') }}</h2>
        <form method="post" action="{{ $inbox }}" class="sp-composer" data-support-guide data-error="{{ trans('support.failed') }}" data-ai="{{ trans('support.ai') }}" data-source="{{ trans('support.source') }}"
            data-knowledge="{{ route('clubunio.platform.support', ['tab' => 'knowledge']) }}">
            @csrf
            <input type="hidden" name="operation" value="ask">
            <label>{{ trans('support.ask') }}<input name="body" required maxlength="500"></label>
            <button class="primary">@svg('heroicon-o-paper-airplane') {{ trans('support.workspace.send_question') }}</button>
            <output aria-live="polite"></output>
        </form>
    </section>
@endif
<script src="{{ asset('js/clubunio-support.js') }}?v=2" defer></script>
@endsection

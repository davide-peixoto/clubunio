<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ trans('support.title') }} · ClubUnio</title>
<link rel="stylesheet" href="{{ asset('css/clubunio-portal.css') }}">
<link rel="stylesheet" href="{{ asset('css/clubunio-support.css') }}?v=workspace-1">
<link rel="stylesheet" href="{{ asset('css/clubunio-support-workspace.css') }}?v=1">
</head>
<body>
<main class="cu-support cu-support-workspace">
@php
    $embedded = request()->query('embed') === '1';
@endphp
<header class="cs-heading"><h1 @class(['cs-sr-only' => $embedded])>{{ trans('support.title') }}</h1>
<a class="cs-text-link" href="{{ route('clubunio.support.login-help') }}" target="_blank" rel="noopener noreferrer">@svg('heroicon-o-key') {{ trans('support-intake.title') }} @svg('heroicon-o-arrow-top-right-on-square')</a>
</header>
@php
    $base = request()->url();
    $tabs = ($club ? ['tickets' => 'inbox-stack'] : []) + ['knowledge' => 'book-open', 'assistant' => 'chat-bubble-left-right'];
    if ($staff) $tabs['editorial'] = 'pencil-square';
    $context = array_filter(['surface' => $surface, 'embed' => $embedded ? '1' : null]);
    $tabUrl = fn ($value, $extra = []) => $base.'?'.http_build_query($extra + ['tab' => $value] + $context);
@endphp
<nav class="cs-tabs" aria-label="{{ trans('support.title') }}">
    @foreach($tabs as $key => $icon)
    <a href="{{ $tabUrl($key) }}" @if($tab === $key || ($key === 'tickets' && $tab === 'create')) aria-current="page" @endif>@svg('heroicon-o-'.$icon) {{ trans('support.workspace.'.$key) }}</a>
    @endforeach
</nav>
@if($errors->any())<div role="alert" class="cs-error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($tab === 'knowledge')
<section class="cs-knowledge">
    <form class="cs-filters" method="get"><input type="hidden" name="tab" value="knowledge">@if($embedded)<input type="hidden" name="embed" value="1">@endif<label>{{ trans('support-wiki.surface') }}<select name="surface" onchange="this.form.submit()"><option value="web" @selected($surface === 'web')>{{ trans('support-wiki.web') }}</option><option value="app" @selected($surface === 'app')>{{ trans('support-wiki.app') }}</option></select></label><label>{{ trans('support.search') }}<input type="search" name="q" maxlength="120" value="{{ request('q') }}"></label><button class="cs-icon-button" title="{{ trans('support.search') }}" aria-label="{{ trans('support.search') }}">@svg('heroicon-o-magnifying-glass')</button></form>
    @foreach($faq as $key => $item)
        <details id="faq-{{ $key }}"><summary>{{ $item['title'] }}</summary>@include('clubunio.support.guide', ['item' => $item])</details>
    @endforeach
    @foreach($articles as $item)
        <details id="article-{{ $item->id }}"><summary>{{ $item->title }}</summary><p>{{ $item->body }}</p>
            @if($item->video_url)<a href="{{ $item->video_url }}" target="_blank" rel="noreferrer noopener">{{ trans('support.video') }}</a>@endif
        </details>
    @endforeach
    @if(!$faq && $articles->isEmpty())<p class="cs-empty">{{ trans('support.workspace.no_articles') }}</p>@endif
</section>
@elseif($tab === 'assistant')
    @include('clubunio.support.assistant', ['knowledgeUrl' => $tabUrl('knowledge')])
@elseif($tab === 'tickets' && $club)
<section class="cs-tickets">
    <div class="cs-section-heading"><h2>{{ trans('support.workspace.tickets') }}</h2><a class="cs-button cs-primary" href="{{ $tabUrl('create') }}">@svg('heroicon-o-plus') {{ trans('support.create') }}</a></div>
    <form class="cs-filters" method="get">
        <input type="hidden" name="tab" value="tickets"><input type="hidden" name="surface" value="{{ $surface }}">@if($embedded)<input type="hidden" name="embed" value="1">@endif
        <label>{{ trans('support.subject') }}<input type="search" name="q" maxlength="120" value="{{ $filters['q'] ?? '' }}"></label>
        <label>{{ trans('support.workspace.status') }}<select name="status"><option value="">{{ trans('support.workspace.all_statuses') }}</option>@foreach(['open','waiting','closed'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ trans('support.'.$status) }}</option>@endforeach</select></label>
        <button class="cs-icon-button" title="{{ trans('support.workspace.apply_filters') }}" aria-label="{{ trans('support.workspace.apply_filters') }}">@svg('heroicon-o-magnifying-glass')</button>
    </form>
    <ul class="cs-ticket-list">
    @forelse($rows as $row)
        <li><a href="{{ $tabUrl('tickets', $filters + ['ticket' => $row->id]) }}#ticket-detail" @if($selected?->id === $row->id) aria-current="true" @endif>
            <strong>{{ $row->subject }}</strong><span class="cs-status cs-status-{{ $row->status }}">{{ trans('support.'.$row->status) }}</span>
            <time datetime="{{ \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($row->created_at)->timezone('Europe/Zurich')->locale(app()->getLocale())->isoFormat('L LT') }}</time>
        </a></li>
    @empty
        <li class="cs-empty">{{ trans('support.workspace.no_club_tickets') }}</li>
    @endforelse
    </ul>
    <nav class="cs-pagination" aria-label="{{ trans('support.workspace.tickets') }}">
        @if($rows->previousPageUrl())<a class="cs-button" href="{{ $rows->previousPageUrl() }}">@svg('heroicon-o-chevron-left') {{ trans('support.workspace.previous') }}</a>@endif
        @if($rows->nextPageUrl())<a class="cs-button" href="{{ $rows->nextPageUrl() }}">{{ trans('support.workspace.next') }} @svg('heroicon-o-chevron-right')</a>@endif
    </nav>
    @if($selected)
    <div id="ticket-detail" class="cs-ticket-detail">
        <h2>{{ $selected->subject }}</h2>
        <span class="cs-status cs-status-{{ $selected->status }}">{{ trans('support.'.$selected->status) }}</span>
        @if(!empty($triage?->draft))<aside><strong>{{ trans('support.triage') }}</strong><p>{{ $triage->draft }}</p></aside>@endif
        @if(($staff || $platform) && $selected->status !== 'closed')
            <form method="post">@csrf
                <input type="hidden" name="ticket" value="{{ $selected->id }}">
                <label>{{ trans('support.assignee') }}<select name="assignee_id">
                    <option value="">{{ trans('support.unassigned') }}</option>
                    @foreach($assignees as $assignee)<option value="{{ $assignee->id }}" @selected($selected->assignee_id == $assignee->id)>{{ $assignee->display_name }}</option>@endforeach
                </select></label>
                <button name="operation" value="assign">{{ trans('support.assign') }}</button>
            </form>
        @endif
        @foreach($posts as $post)
            <article>@if($post->internal)<strong>{{ trans('support.internal') }}</strong>@endif<p>{{ $post->body }}</p>
                @foreach($post->attachments as $file)<a href="?ticket={{ $selected->id }}&amp;attachment={{ $file->id }}">{{ $file->filename }}</a>@endforeach
            </article>
        @endforeach
        @if($updates->isNotEmpty())
            <h3>{{ trans('support.updates') }}</h3>
            @foreach($updates as $post)<p>{{ $post->body }}</p>@endforeach
        @endif
        @if($selected->status !== 'closed')
            <form method="post" enctype="multipart/form-data">@csrf
                <input type="hidden" name="ticket" value="{{ $selected->id }}">
                <label>{{ trans('support.body') }}<textarea name="body" maxlength="10000" required></textarea></label>
                <label>{{ trans('support.attachment') }}<input type="file" name="attachment" accept="image/png,image/jpeg,application/pdf"></label>
                @if($staff || $platform)<label><input type="checkbox" name="internal" value="1">{{ trans('support.internal') }}</label>@endif
                <button name="operation" value="reply">{{ trans('support.reply') }}</button>
            </form>
            @if($staff && !$platform)
                <details><summary>{{ trans('support.escalate') }}</summary>
                    <form method="post">@csrf
                        <input type="hidden" name="ticket" value="{{ $selected->id }}">
                        <label>{{ trans('support.subject') }}<input name="subject" maxlength="160" required></label>
                        <label>{{ trans('support.summary') }}<textarea name="body" maxlength="10000" required></textarea></label>
                        @foreach($posts->where('internal', false) as $source)
                            @foreach($source->attachments as $attachment)
                                <label><input type="checkbox" name="share[]" value="{{ $attachment->id }}">{{ trans('support.share_attachment', ['name' => $attachment->filename]) }}</label>
                            @endforeach
                        @endforeach
                        <button name="operation" value="escalate">{{ trans('support.escalate') }}</button>
                    </form>
                </details>
            @endif
            @if($staff || $platform)
                <form method="post">@csrf<input type="hidden" name="ticket" value="{{ $selected->id }}">
                    <button name="operation" value="close">{{ trans('support.close') }}</button>
                </form>
            @endif
        @endif
    </div>
    @endif
</section>
@elseif($tab === 'create' && $club)
    <section>
        <div class="cs-section-heading"><h2>{{ trans('support.create') }}</h2><a class="cs-text-link" href="{{ $tabUrl('tickets') }}">@svg('heroicon-o-arrow-left') {{ trans('support.workspace.tickets') }}</a></div>
            <form method="post" enctype="multipart/form-data">@csrf
                <label>{{ trans('support.subject') }}<input name="subject" maxlength="160" value="{{ old('subject') }}" required></label>
                <label>{{ trans('support.body') }}<textarea name="body" maxlength="10000" required>{{ old('body') }}</textarea></label>
                <label>{{ trans('support.attachment') }}<input type="file" name="attachment" accept="image/png,image/jpeg,application/pdf"></label>
                <button name="operation" value="create">@svg('heroicon-o-plus') {{ trans('support.create') }}</button>
            </form>
    </section>
@elseif($tab === 'editorial' && $staff)
<section>
    <div class="cs-section-heading"><h2>{{ trans('support.workspace.editorial') }}</h2>
    <a class="cs-text-link" href="{{ route('clubunio.support.club-intake', $club) }}" target="_blank" rel="noopener noreferrer">@svg('heroicon-o-inbox-arrow-down') {{ trans('support.workspace.sign_in_requests') }} @svg('heroicon-o-arrow-top-right-on-square')</a></div>
        @foreach(collect([null])->concat($editableArticles) as $edit)
        <details><summary>{{ $edit?->title ?? trans('support.article') }}</summary>
            <form method="post">@csrf
                @if($edit)<input type="hidden" name="article_id" value="{{ $edit->id }}">@endif
                <label>{{ trans('support.subject') }}<input name="subject" maxlength="160" value="{{ $edit?->title }}" required></label>
                <label>{{ trans('support.body') }}<textarea name="body" maxlength="10000" required>{{ $edit?->body }}</textarea></label>
                <label>{{ trans('support.video') }}<input type="url" name="video_url" maxlength="2048" value="{{ $edit?->video_url }}"></label>
                <input type="hidden" name="published" value="0">
                <label><input type="checkbox" name="published" value="1" @checked($edit?->published ?? false)>{{ trans('support.published') }}</label>
                <label>{{ trans('support.language') }}<select name="locale"><option value="de">Deutsch</option><option value="en" @selected($edit?->locale === 'en')>English</option></select></label>
                <label>{{ trans('support.audience') }}<select name="audience">
                    @foreach($platform ? ['platform','public'] : ['club_staff','club'] as $audience)
                        <option value="{{ $audience }}" @selected($edit?->audience === $audience)>{{ trans('support.'.$audience) }}</option>
                    @endforeach
                </select></label>
                <button name="operation" value="article">{{ trans('support.article') }}</button>
            </form>
        </details>
        @endforeach
</section>
@endif
</main>
<script src="{{ asset('js/clubunio-support.js') }}?v=workspace-1" defer></script>
</body>
</html>

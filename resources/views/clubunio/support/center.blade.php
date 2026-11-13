<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ trans('support.title') }} · ClubUnio</title>
<link rel="stylesheet" href="{{ asset('css/clubunio-portal.css') }}">
<link rel="stylesheet" href="{{ asset('css/clubunio-support.css') }}">
</head>
<body>
<main class="cu-support">
<h1>{{ trans('support.title') }}</h1>
<section>
    <form method="get"><label>{{ trans('support.search') }}<input name="q" maxlength="120" value="{{ request('q') }}"></label><button>{{ trans('support.search') }}</button></form>
    @foreach($faq as $key => $item)
        <details id="faq-{{ $key }}"><summary>{{ $item['title'] }}</summary><p>{{ $item['body'] }}</p></details>
    @endforeach
    @foreach($articles as $item)
        <details><summary>{{ $item->title }}</summary><p>{{ $item->body }}</p></details>
    @endforeach
    <form method="post" data-support-guide data-error="{{ trans('support.failed') }}" data-ai="{{ trans('support.ai') }}" data-source="{{ trans('support.source') }}">
        @csrf
        <input type="hidden" name="operation" value="ask">
        <label>{{ trans('support.ask') }}<input name="body" required maxlength="500"></label>
        <button>{{ trans('support.ask') }}</button>
        <output aria-live="polite"></output>
    </form>
</section>
@if($club || $platform)
<section>
    @foreach($rows as $row)
        <p><a href="?ticket={{ $row->id }}">{{ $row->subject }}</a> · {{ trans('support.'.$row->status) }}</p>
    @endforeach
    @if($selected)
        <h2>{{ $selected->subject }}</h2>
        @foreach($posts as $post)
            <article>@if($post->internal)<strong>{{ trans('support.internal') }}</strong>@endif<p>{{ $post->body }}</p></article>
        @endforeach
        @if($updates->isNotEmpty())
            <h3>{{ trans('support.updates') }}</h3>
            @foreach($updates as $post)<p>{{ $post->body }}</p>@endforeach
        @endif
        @if($selected->status !== 'closed')
            <form method="post">@csrf
                <input type="hidden" name="ticket" value="{{ $selected->id }}">
                <label>{{ trans('support.body') }}<textarea name="body" maxlength="10000" required></textarea></label>
                @if($staff || $platform)<label><input type="checkbox" name="internal" value="1">{{ trans('support.internal') }}</label>@endif
                <button name="operation" value="reply">{{ trans('support.reply') }}</button>
            </form>
            @if($staff && !$platform)
                <details><summary>{{ trans('support.escalate') }}</summary>
                    <form method="post">@csrf
                        <input type="hidden" name="ticket" value="{{ $selected->id }}">
                        <label>{{ trans('support.subject') }}<input name="subject" maxlength="160" required></label>
                        <label>{{ trans('support.summary') }}<textarea name="body" maxlength="10000" required></textarea></label>
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
    @endif
    @unless($platform)
        <details><summary>{{ trans('support.create') }}</summary>
            <form method="post">@csrf
                <label>{{ trans('support.subject') }}<input name="subject" maxlength="160" required></label>
                <label>{{ trans('support.body') }}<textarea name="body" maxlength="10000" required></textarea></label>
                <button name="operation" value="create">{{ trans('support.create') }}</button>
            </form>
        </details>
    @endunless
    @if($staff || $platform)
        <details><summary>{{ trans('support.article') }}</summary>
            <form method="post">@csrf
                <label>{{ trans('support.subject') }}<input name="subject" maxlength="160" required></label>
                <label>{{ trans('support.body') }}<textarea name="body" maxlength="10000" required></textarea></label>
                <label>{{ trans('support.language') }}<select name="locale"><option value="de">Deutsch</option><option value="en">English</option></select></label>
                <label>{{ trans('support.audience') }}<select name="audience">
                    @foreach($platform ? ['platform','public'] : ['club_staff','club'] as $audience)
                        <option value="{{ $audience }}">{{ trans('support.'.$audience) }}</option>
                    @endforeach
                </select></label>
                <button name="operation" value="article">{{ trans('support.article') }}</button>
            </form>
        </details>
    @endif
</section>
@endif
</main>
<script src="{{ asset('js/clubunio-support.js') }}" defer></script>
</body>
</html>

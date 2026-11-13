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
@if($platform || $staff)
    <a href="{{ $platform ? route('clubunio.support.platform-intake') : route('clubunio.support.club-intake', $club) }}">{{ trans('support-intake.title') }}</a>
@endif
<section>
    <form method="get"><label>{{ trans('support.search') }}<input name="q" maxlength="120" value="{{ request('q') }}"></label><button>{{ trans('support.search') }}</button></form>
    @foreach($faq as $key => $item)
        <details id="faq-{{ $key }}"><summary>{{ $item['title'] }}</summary><p>{{ $item['body'] }}</p></details>
    @endforeach
    @foreach($articles as $item)
        <details><summary>{{ $item->title }}</summary><p>{{ $item->body }}</p>
            @if($item->video_url)<a href="{{ $item->video_url }}" rel="noreferrer noopener">{{ trans('support.video') }}</a>@endif
        </details>
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
    @endif
    @unless($platform)
        <details><summary>{{ trans('support.create') }}</summary>
            <form method="post" enctype="multipart/form-data">@csrf
                <label>{{ trans('support.subject') }}<input name="subject" maxlength="160" required></label>
                <label>{{ trans('support.body') }}<textarea name="body" maxlength="10000" required></textarea></label>
                <label>{{ trans('support.attachment') }}<input type="file" name="attachment" accept="image/png,image/jpeg,application/pdf"></label>
                <button name="operation" value="create">{{ trans('support.create') }}</button>
            </form>
        </details>
    @endunless
    @if($staff || $platform)
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
    @endif
</section>
@endif
</main>
<script src="{{ asset('js/clubunio-support.js') }}" defer></script>
</body>
</html>

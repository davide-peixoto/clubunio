<header class="sp-panel-head sp-detail-head">
    <div>
        <div class="sp-meta">{{ $selected->club_name }}</div>
        <h2 id="sp-detail-title">{{ $selected->subject }}</h2>
    </div>
    <span class="sp-status sp-status-{{ $selected->status }}">{{ trans('support.'.$selected->status) }}</span>
</header>
@if($selected->status !== 'closed')
    <div class="sp-ticket-controls">
        <form method="post" class="sp-inline">@csrf
            <input type="hidden" name="ticket" value="{{ $selected->id }}">
            <label>{{ trans('support.assignee') }}<select name="assignee_id">
                <option value="">{{ trans('support.unassigned') }}</option>
                @foreach($assignees as $person)
                    <option value="{{ $person->id }}" @selected($selected->assignee_id == $person->id)>{{ $person->display_name }}</option>
                @endforeach
            </select></label>
            <button name="operation" value="assign" class="sp-button">{{ trans('support.assign') }}</button>
        </form>
        <form method="post">@csrf
            <input type="hidden" name="ticket" value="{{ $selected->id }}">
            <button name="operation" value="close" class="sp-button">@svg('heroicon-o-check-circle') {{ trans('support.close') }}</button>
        </form>
    </div>
@endif
<div class="sp-thread">
    <h3>{{ trans('support.workspace.history') }}</h3>
    @forelse($posts as $post)
        <article class="sp-message {{ $post->internal ? 'sp-note' : '' }}">
            <header class="sp-ticket-line sp-meta">
                <strong>{{ trans($post->internal ? 'support.internal' : 'support.body') }}</strong>
                <time datetime="{{ \Illuminate\Support\Carbon::parse($post->created_at)->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($post->created_at)->timezone('Europe/Zurich')->locale(app()->getLocale())->isoFormat('L LT') }}</time>
            </header>
            <p>{{ $post->body }}</p>
            @foreach($post->attachments as $file)
                <a class="sp-file" href="{{ route('clubunio.platform.support', ['ticket' => $selected->id, 'attachment' => $file->id]) }}">@svg('heroicon-o-paper-clip') {{ $file->filename }}</a>
            @endforeach
        </article>
    @empty
        <p class="sp-meta">{{ trans('support.workspace.no_messages') }}</p>
    @endforelse
</div>
@if(!empty($triage?->draft))
    <details class="sp-disclosure"><summary>@svg('heroicon-o-sparkles') {{ trans('support.triage') }}</summary><p>{{ $triage->draft }}</p></details>
@endif
@isset($zammad)
    @include('clubunio.support.zammad-handoff')
@endisset
@if($selected->status !== 'closed')
    <form method="post" enctype="multipart/form-data" class="sp-composer">@csrf
        <input type="hidden" name="ticket" value="{{ $selected->id }}">
        <h3>{{ trans('support.workspace.write_message') }}</h3>
        <fieldset class="sp-segment"><legend class="sp-visually-hidden">{{ trans('support.workspace.message_type') }}</legend>
            <label><input type="radio" name="internal" value="0" @checked((string) old('internal', '0') === '0')> {{ trans('support.workspace.reply_to_club') }}</label>
            <label><input type="radio" name="internal" value="1" @checked((string) old('internal', '0') === '1')> {{ trans('support.internal') }}</label>
        </fieldset>
        <label>{{ trans('support.body') }}<textarea name="body" maxlength="10000" required>{{ old('body') }}</textarea></label>
        <label>{{ trans('support.attachment') }}<input type="file" name="attachment" accept="image/png,image/jpeg,application/pdf"></label>
        <button name="operation" value="reply" class="primary">@svg('heroicon-o-paper-airplane') {{ trans('support.workspace.send_message') }}</button>
    </form>
@endif

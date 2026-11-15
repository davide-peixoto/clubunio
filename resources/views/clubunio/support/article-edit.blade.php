<details class="sp-disclosure"><summary>{{ $edit?->title ?? trans('support.article') }}</summary>
    <form method="post" class="sp-composer">@csrf
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
        <button class="primary" name="operation" value="article">{{ trans('support.article') }}</button>
    </form>
</details>

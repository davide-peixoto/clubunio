<details class="sp-disclosure" @if(session()->has('zammad_result')) open @endif>
    <summary>{{ trans('support.zammad.title') }}</summary>
    @if(session()->has('zammad_result'))
        <p role="status">{{ trans(session('zammad_result') === 'linked' ? 'support.zammad.linked' : 'support.zammad.attention') }}</p>
    @endif
    @if(!$zammad['ready'])
        <p>{{ trans('support.zammad.unconfigured') }}</p>
    @elseif($zammad['url'])
        <a href="{{ $zammad['url'] }}" target="_blank" rel="noopener noreferrer">{{ trans('support.zammad.open') }}</a>
    @elseif($zammad['status'])
        <p>{{ trans('support.zammad.attention') }}</p>
        @if($zammad['reconcile'])
            <form method="post" class="sp-composer">@csrf
                <input type="hidden" name="ticket" value="{{ $selected->id }}">
                <label>{{ trans('support.zammad.remote_id') }}<input name="remote_id" type="number" min="1" required></label>
                <button class="sp-button" name="operation" value="zammad_reconcile">{{ trans('support.zammad.reconcile') }}</button>
            </form>
        @endif
    @elseif($zammad['summary'] && $selected->status !== 'closed')
        <p>{{ trans('support.zammad.review') }}</p>
        <strong>{{ $zammad['summary']->subject }}</strong>
        <p>{{ $zammad['summary']->body }}</p>
        <form method="post">@csrf
            <input type="hidden" name="ticket" value="{{ $selected->id }}">
            <label><input type="checkbox" name="confirmed" value="1" required> {{ trans('support.zammad.confirm') }}</label>
            <button name="operation" value="zammad_export">{{ trans('support.zammad.send') }}</button>
        </form>
    @endif
</details>

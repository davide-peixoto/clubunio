<form method="post" class="cs-chat" data-support-chat data-support-guide
    data-error="{{ trans('support.failed') }}" data-ai="{{ trans('support.ai') }}"
    data-source="{{ trans('support.source') }}" data-knowledge="{{ $knowledgeUrl }}"
    data-you="{{ trans('support.workspace.you') }}" data-assistant="{{ trans('support.workspace.assistant') }}"
    data-pending="{{ trans('support.workspace.answering') }}">
    @csrf
    <input type="hidden" name="operation" value="ask">
    <div class="cs-chat-title">@svg('heroicon-o-chat-bubble-left-right')<h2>{{ trans('support.workspace.assistant') }}</h2></div>
    <output class="cs-chat-log" role="log" aria-live="polite" aria-relevant="additions text">
        <div class="cs-chat-message cs-chat-assistant"><strong>{{ trans('support.workspace.assistant') }}</strong><p>{{ trans('support.workspace.welcome') }}</p></div>
    </output>
    <div class="cs-chat-compose">
        <label for="support-question" class="cs-sr-only">{{ trans('support.ask') }}</label>
        <textarea id="support-question" name="body" maxlength="500" rows="2" required placeholder="{{ trans('support.workspace.question_placeholder') }}"></textarea>
        <button class="cs-icon-button" title="{{ trans('support.workspace.send_question') }}" aria-label="{{ trans('support.workspace.send_question') }}">@svg('heroicon-o-paper-airplane')</button>
    </div>
    <noscript><p>{{ trans('support.workspace.chat_requires_js') }}</p></noscript>
</form>

<x-filament-panels::page>
    <style>
        .cu-assistant { --cu-line: #e5e7eb; --cu-muted: #6b7280; --cu-surface: #f9fafb; min-width: 0; font-size: .875rem; letter-spacing: 0; }
        .cu-assistant fieldset { min-width: 0; }
        .dark .cu-assistant { --cu-line: #374151; --cu-muted: #9ca3af; --cu-surface: #18181b; }
        .cu-assistant * { box-sizing: border-box; }
        .cu-assistant button, .cu-assistant a, .cu-assistant p, .cu-assistant dd, .cu-assistant dt, .cu-assistant h3 { overflow-wrap: anywhere; }
        .cu-assistant button:disabled { cursor: wait; opacity: .55; }
        .cu-assistant button:focus-visible, .cu-assistant a:focus-visible, .cu-assistant textarea:focus-visible { outline: 2px solid #48a9e8; outline-offset: 3px; }
        .cu-assistant-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; padding-bottom: .75rem; border-bottom: 1px solid var(--cu-line); }
        .cu-assistant-budget { margin-left: auto; color: var(--cu-muted); font-variant-numeric: tabular-nums; }
        .cu-assistant-layout { display: grid; grid-template-columns: minmax(10rem, 13rem) minmax(0, 1fr); gap: 1.25rem; padding-top: 1rem; }
        .cu-assistant-history { min-width: 0; border-right: 1px solid var(--cu-line); padding-right: 1rem; }
        .cu-assistant-history h2, .cu-assistant-main h2 { font-size: .875rem; font-weight: 600; margin-bottom: .75rem; }
        .cu-assistant-history ul { max-height: 58vh; overflow-y: auto; }
        .cu-assistant-history button { display: block; width: 100%; padding: .6rem .5rem; text-align: left; border-radius: 4px; }
        .cu-assistant-history button[aria-current="true"] { background: var(--cu-surface); box-shadow: inset 3px 0 #48a9e8; font-weight: 600; }
        .cu-assistant-main { min-width: 0; }
        .cu-assistant-notice { padding: .65rem 0; color: var(--cu-muted); }
        .cu-assistant-error { padding: .75rem; margin-top: .75rem; border-left: 3px solid #dc2626; background: var(--cu-surface); overflow-wrap: anywhere; }
        .cu-assistant-log { min-height: 12rem; max-height: 52vh; overflow-y: auto; overscroll-behavior: contain; padding-right: .5rem; }
        .cu-assistant-message { padding: .75rem 0; border-bottom: 1px solid var(--cu-line); }
        .cu-assistant-role { font-size: .75rem; color: var(--cu-muted); margin-bottom: .25rem; font-weight: 600; }
        .cu-assistant-text { white-space: pre-wrap; overflow-wrap: anywhere; }
        .cu-assistant-results { border-left: 2px solid var(--cu-line); padding-left: .75rem; margin-top: .75rem; }
        .cu-assistant-results h3 { font-size: .875rem; font-weight: 600; }
        .cu-assistant-counts { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); gap: .75rem 1.5rem; margin: .75rem 0; }
        .cu-assistant-counts h4 { font-size: .8125rem; font-weight: 600; margin-bottom: .25rem; overflow-wrap: anywhere; }
        .cu-assistant-counts dl > div { display: grid; grid-template-columns: minmax(0, 1fr) minmax(2rem, auto); gap: .75rem; padding: .15rem 0; }
        .cu-assistant-counts dt { color: var(--cu-muted); font-size: .75rem; }
        .cu-assistant-counts dd { margin: 0; text-align: right; font-variant-numeric: tabular-nums; }
        .cu-assistant-results li { padding: .5rem 0; }
        .cu-assistant-results a { color: #1672b8; text-decoration: underline; text-underline-offset: 2px; }
        .dark .cu-assistant-results a { color: #48a9e8; }
        .cu-assistant-fields { display: flex; flex-wrap: wrap; gap: .25rem 1rem; margin-top: .25rem; }
        .cu-assistant-fields > div { min-width: 0; max-width: 100%; }
        .cu-assistant-fields dt { color: var(--cu-muted); font-size: .75rem; }
        .cu-assistant-examples { display: flex; flex-wrap: wrap; gap: .5rem 1rem; margin: .75rem 0; }
        .cu-assistant-examples button { text-decoration: underline; text-underline-offset: 3px; text-align: left; }
        .cu-assistant-composer { border-top: 1px solid var(--cu-line); padding-top: .75rem; }
        .cu-assistant-composer label { display: block; font-weight: 600; margin-bottom: .35rem; }
        .cu-assistant-composer textarea { display: block; width: 100%; min-height: 5rem; max-height: 14rem; resize: vertical; border: 1px solid var(--cu-line); border-radius: 6px; background: transparent; padding: .6rem; font: inherit; }
        .cu-assistant-send { display: flex; justify-content: space-between; align-items: center; gap: .75rem; min-height: 3rem; }
        .cu-assistant-status { color: var(--cu-muted); font-size: .75rem; }
        .cu-assistant-actions { margin-top: 1rem; }
        .cu-assistant-action { border: 1px solid var(--cu-line); border-radius: 6px; padding: .85rem; margin-top: .75rem; }
        .cu-assistant-action header { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .5rem; }
        .cu-assistant-action h3 { font-size: .875rem; font-weight: 600; }
        .cu-assistant-change { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: .5rem 1rem; padding: .65rem 0; border-bottom: 1px solid var(--cu-line); }
        .cu-assistant-change > dt { grid-column: 1 / -1; font-weight: 500; }
        .cu-assistant-change dd { min-width: 0; margin: 0; white-space: pre-wrap; }
        .cu-assistant-change dd span { display: block; color: var(--cu-muted); font-size: .75rem; }
        .cu-assistant-effects { margin: .75rem 0; padding-left: 1.2rem; list-style: disc; overflow-wrap: anywhere; }
        .cu-assistant-action-buttons { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; }
        @media (max-width: 640px) {
            .cu-assistant-layout { grid-template-columns: minmax(0, 1fr); gap: 1rem; }
            .cu-assistant-history { border-right: 0; border-bottom: 1px solid var(--cu-line); padding: 0 0 .75rem; }
            .cu-assistant-history ul { max-height: 8rem; }
            .cu-assistant-budget { width: 100%; margin-left: 0; }
            .cu-assistant-log { max-height: 42vh; }
        }
    </style>
    @php
        $providerEnabled = $stateReady && ($assistantState['provider_enabled'] ?? false);
        $canAsk = $providerEnabled && (! $this->budgetExhausted() || ($requestKey && $requestText === trim($question)));
        $budget = $assistantState['budget'] ?? [];
    @endphp
    <div class="cu-assistant" x-data="{ submitting: false }" x-bind:aria-busy="submitting" aria-label="{{ __('ClubUnio Assistant') }}">
        <fieldset x-bind:disabled="submitting">
        <div class="cu-assistant-toolbar">
            <x-filament::button color="gray" icon="heroicon-o-plus" wire:click="newConversation" wire:loading.attr="disabled">{{ __('New conversation') }}</x-filament::button>
            <x-filament::button color="gray" icon="heroicon-o-calendar-days" wire:click="dailyOverview" wire:loading.attr="disabled">{{ __('Daily overview') }}</x-filament::button>
            <x-filament::icon-button icon="heroicon-o-arrow-path" color="gray" :label="__('Reload conversation')" :tooltip="__('Reload conversation')" wire:click="refreshState" wire:loading.attr="disabled" />
            @if (isset($budget['used_usd'], $budget['limit_usd']))
                <p class="cu-assistant-budget">{{ __('AI cost: :used / :limit USD', ['used' => number_format((float) $budget['used_usd'], 4), 'limit' => number_format((float) $budget['limit_usd'], 2)]) }}</p>
            @endif
        </div>
        @error('assistant') <p class="cu-assistant-error" role="alert">{{ $message }}</p> @enderror
        @if ($stateReady && ! $providerEnabled)
            <p class="cu-assistant-notice">{{ __('AI is disabled. The daily overview remains available locally.') }}</p>
        @elseif ($stateReady && $this->budgetExhausted())
            <p class="cu-assistant-notice" role="status">{{ __('AI cost limit reached. The daily overview remains available locally.') }}</p>
        @endif
        <div class="cu-assistant-layout">
            <aside class="cu-assistant-history" aria-label="{{ __('Conversation history') }}">
                <h2>{{ __('Conversation history') }}</h2>
                <ul>
                    @forelse ($assistantState['conversations'] ?? [] as $conversation)
                        <li wire:key="assistant-history-{{ $conversation['id'] }}">
                            <button type="button" wire:click="selectConversation({{ (int) $conversation['id'] }})" wire:loading.attr="disabled" aria-current="{{ $conversationId === (int) $conversation['id'] ? 'true' : 'false' }}">{{ $conversation['title'] ?: __('Untitled conversation') }}</button>
                        </li>
                    @empty
                        <li class="cu-assistant-status">{{ __('No previous conversations.') }}</li>
                    @endforelse
                </ul>
            </aside>
            <div class="cu-assistant-main">
                <div class="cu-assistant-log" role="log" aria-label="{{ __('Assistant conversation') }}" aria-live="polite" aria-relevant="additions text" tabindex="0">
                    @forelse ($assistantState['messages'] ?? [] as $index => $message)
                        <article class="cu-assistant-message" wire:key="assistant-message-{{ $conversationId ?? 'new' }}-{{ $index }}">
                            <p class="cu-assistant-role">{{ ($message['role'] ?? '') === 'user' ? __('You') : __('Assistant') }}</p>
                            <p class="cu-assistant-text">{{ $message['content'] ?? '' }}</p>
                            @foreach ($this->resultGroups($message) as $result)
                                <section class="cu-assistant-results">
                                    <h3>{{ $result['title'] ?? '' }}</h3>
                                    <p class="cu-assistant-text">{{ $result['summary'] ?? '' }}</p>
                                    @if ($countGroups = $this->resultCountGroups($result))
                                        <div class="cu-assistant-counts" role="group" aria-label="{{ __('Module counts') }}">
                                            @foreach ($countGroups as $group)
                                                <section data-assistant-count-module="{{ $group['module'] }}" aria-label="{{ $group['title'] }}">
                                                    <h4>{{ $group['title'] }}</h4>
                                                    <dl>
                                                        @foreach ($group['metrics'] as $metric)
                                                            <div data-assistant-count="{{ $metric['key'] }}"><dt>{{ $metric['label'] }}</dt><dd>{{ $metric['value'] }}</dd></div>
                                                        @endforeach
                                                    </dl>
                                                </section>
                                            @endforeach
                                        </div>
                                    @endif
                                    <ul>
                                        @foreach ($result['rows'] ?? [] as $row)
                                            <li>
                                                @if (is_array($row))
                                                    @php
                                                        $link = $this->resultUrl($row['url'] ?? null);
                                                        $label = $row['title'] ?? $row['label'] ?? $row['name'] ?? __('Open result');
                                                    @endphp
                                                    @if ($link)
                                                        <a href="{{ $link }}">{{ $this->displayValue($label) }}</a>
                                                    @elseif (isset($row['title']) || isset($row['label']) || isset($row['name']))
                                                        <span>{{ $this->displayValue($label) }}</span>
                                                    @endif
                                                    @if (isset($row['summary'])) <p class="cu-assistant-text">{{ $this->displayValue($row['summary']) }}</p> @endif
                                                    <dl class="cu-assistant-fields">
                                                        @foreach ($this->resultFields($row) as $field)
                                                            <div><dt>{{ $field['label'] }}</dt><dd>{{ $this->displayValue($field['value']) }}</dd></div>
                                                        @endforeach
                                                    </dl>
                                                @else
                                                    {{ $this->displayValue($row) }}
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </section>
                            @endforeach
                        </article>
                    @empty
                        <p class="cu-assistant-notice">{{ __('No messages in this conversation.') }}</p>
                    @endforelse
                </div>
                <div class="cu-assistant-examples" aria-label="{{ __('Example questions') }}">
                    <button type="button" wire:click="useExample('reservations')" wire:loading.attr="disabled" @disabled(! $canAsk)>{{ __('Which reservation requests are pending?') }}</button>
                    <button type="button" wire:click="useExample('invoices')" wire:loading.attr="disabled" @disabled(! $canAsk)>{{ __('Which invoices are still outstanding?') }}</button>
                </div>
                <form class="cu-assistant-composer" x-on:submit.prevent="if (submitting) return; submitting = true; try { if (await $wire.prepareQuestion()) await $wire.send(); } finally { submitting = false; }">
                    <label for="assistant-question">{{ __('Your question') }}</label>
                    <textarea id="assistant-question" wire:model="question" rows="3" maxlength="4000" @disabled(! $canAsk) wire:loading.attr="disabled" aria-invalid="{{ $errors->has('question') ? 'true' : 'false' }}" @if ($errors->has('question')) aria-describedby="assistant-question-error" @endif></textarea>
                    @error('question') <p id="assistant-question-error" class="cu-assistant-error" role="alert">{{ $message }}</p> @enderror
                    <div class="cu-assistant-send">
                        <span class="cu-assistant-status" role="status" wire:loading>{{ __('Assistant is working...') }}</span>
                        <x-filament::button type="submit" icon="heroicon-o-paper-airplane" :disabled="! $canAsk" wire:loading.attr="disabled">{{ $requestKey && $requestText === trim($question) ? __('Retry question') : __('Send question') }}</x-filament::button>
                    </div>
                </form>
                @if ($assistantState['actions'] ?? [])
                    <section class="cu-assistant-actions" aria-label="{{ __('Action previews') }}">
                        <h2>{{ __('Action previews') }}</h2>
                        @foreach ($assistantState['actions'] as $action)
                            <article class="cu-assistant-action" wire:key="assistant-action-{{ $action['id'] }}">
                                <header>
                                    <h3>{{ $action['title'] }}</h3>
                                    <span class="cu-assistant-status">{{ match ($action['status'] ?? '') {
                                        'pending' => __('Awaiting confirmation'),
                                        'confirmed', 'completed', 'executed', 'succeeded' => __('Action completed'),
                                        'running' => __('Action in progress'),
                                        'attention' => __('Action needs review'),
                                        'cancelled', 'canceled', 'rejected' => __('Action cancelled'),
                                        'failed' => __('Action failed'),
                                        'expired' => __('Action expired'),
                                        default => __('Action unavailable'),
                                    } }}</span>
                                </header>
                                <dl>
                                    @foreach ($action['changes'] ?? [] as $change)
                                        <div class="cu-assistant-change">
                                            <dt>{{ $change['label'] }}</dt>
                                            <dd><span>{{ __('Before change') }}</span>{{ $this->displayValue($change['before'] ?? null) }}</dd>
                                            <dd><span>{{ __('After change') }}</span>{{ $this->displayValue($change['after'] ?? null) }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                                @if ($action['effects'] ?? [])
                                    <ul class="cu-assistant-effects" aria-label="{{ __('Action effects') }}">
                                        @foreach ($action['effects'] as $effect) <li>{{ $this->displayValue($effect) }}</li> @endforeach
                                    </ul>
                                @endif
                                @if (($action['status'] ?? '') === 'pending')
                                    <div class="cu-assistant-action-buttons">
                                        <x-filament::button icon="heroicon-o-check" wire:click="confirmAction({{ (int) $action['id'] }})" wire:loading.attr="disabled" :disabled="! $stateReady" :aria-label="__('Confirm action: :title', ['title' => $action['title']])">{{ __('Confirm action') }}</x-filament::button>
                                        <x-filament::button color="gray" icon="heroicon-o-x-mark" wire:click="cancelAction({{ (int) $action['id'] }})" wire:loading.attr="disabled" :disabled="! $stateReady" :aria-label="__('Cancel action: :title', ['title' => $action['title']])">{{ __('Cancel action') }}</x-filament::button>
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </section>
                @endif
            </div>
        </div>
        </fieldset>
    </div>
</x-filament-panels::page>

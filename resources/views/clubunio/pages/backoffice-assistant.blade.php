<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/clubunio-assistant.css') }}?v=3">
    @php
        $providerEnabled = $stateReady && ($assistantState['provider_enabled'] ?? false);
        $canAsk = $stateReady && (! $providerEnabled || ! $this->budgetExhausted() || ($requestKey && $requestText === trim($question)));
        $budget = $assistantState['budget'] ?? [];
    @endphp
    <div class="cu-assistant" x-data="{ submitting: false, pendingText: '', scrollToLatest() { this.$nextTick(() => { const log = this.$refs.log; const last = Array.from(log.querySelectorAll('article[data-message-index]')).at(-1); log.scrollTop = !this.submitting && last?.querySelector('.cu-assistant-overview') ? last.offsetTop - log.offsetTop : log.scrollHeight; }); } }" x-on:assistant-updated.window="scrollToLatest()" x-bind:aria-busy="submitting" aria-label="{{ __('ClubUnio Assistant') }}">
        <fieldset x-bind:disabled="submitting" class="cu-assistant-shell">
        <div class="cu-assistant-toolbar">
            <x-filament::button color="gray" icon="heroicon-o-plus" wire:click="newConversation" wire:loading.attr="disabled">{{ __('New conversation') }}</x-filament::button>
            <x-filament::button color="gray" icon="heroicon-o-calendar-days" wire:click="dailyOverview" wire:loading.attr="disabled">{{ __('Daily overview') }}</x-filament::button>
            <x-filament::icon-button icon="heroicon-o-arrow-path" color="gray" :label="__('Reload conversation')" :tooltip="__('Reload conversation')" wire:click="refreshState" wire:loading.attr="disabled" />
            <span class="cu-assistant-mode" data-mode="{{ $providerEnabled ? 'ai' : 'local' }}">@svg($providerEnabled ? 'heroicon-o-sparkles' : 'heroicon-o-shield-check') {{ trans($providerEnabled ? 'assistant_chat.ai_mode' : 'assistant_chat.local_mode') }}</span>
            @if (isset($budget['used_usd'], $budget['limit_usd']))
                <p class="cu-assistant-budget" title="{{ __('AI cost: :used / :limit USD', ['used' => number_format((float) $budget['used_usd'], 4), 'limit' => number_format((float) $budget['limit_usd'], 2)]) }}">{{ __('AI cost: :used / :limit USD', ['used' => number_format((float) $budget['used_usd'], 4), 'limit' => number_format((float) $budget['limit_usd'], 2)]) }}</p>
            @endif
        </div>
        @error('assistant') <p class="cu-assistant-error" role="alert">{{ $message }}</p> @enderror
        @if ($stateReady && ! $providerEnabled)
            <details class="cu-assistant-connection">
                <summary>{{ __('AI is disabled. The daily overview remains available locally.') }}</summary>
                <p class="cu-assistant-notice">{{ trans('assistant_chat.local_only') }}</p>
            </details>
        @elseif ($stateReady && $this->budgetExhausted())
            <p class="cu-assistant-notice" role="status">{{ __('AI cost limit reached. The daily overview remains available locally.') }}</p>
        @endif
        <div class="cu-assistant-layout">
            <aside class="cu-assistant-history" aria-label="{{ __('Conversation history') }}">
                <details open>
                <summary>@svg('heroicon-o-clock') {{ trans('assistant_chat.history') }}</summary>
                <ul>
                    @forelse ($assistantState['conversations'] ?? [] as $conversation)
                        <li wire:key="assistant-history-{{ $conversation['id'] }}">
                            <button type="button" wire:click="selectConversation({{ (int) $conversation['id'] }})" wire:loading.attr="disabled" aria-current="{{ $conversationId === (int) $conversation['id'] ? 'true' : 'false' }}">{{ $conversation['title'] ?: __('Untitled conversation') }}</button>
                        </li>
                    @empty
                        <li class="cu-assistant-status">{{ __('No previous conversations.') }}</li>
                    @endforelse
                </ul>
                </details>
            </aside>
            <div class="cu-assistant-main">
                <div class="cu-assistant-log" x-ref="log" role="log" aria-label="{{ __('Assistant conversation') }}" aria-live="polite" aria-relevant="additions text" tabindex="0">
                    @forelse ($assistantState['messages'] ?? [] as $index => $message)
                        <article class="cu-assistant-message" data-message-index="{{ $index }}" data-role="{{ ($message['role'] ?? '') === 'user' ? 'user' : 'assistant' }}" wire:key="assistant-message-{{ $conversationId ?? 'new' }}-{{ $index }}">
                            <p class="cu-assistant-role">@svg(($message['role'] ?? '') === 'user' ? 'heroicon-o-user-circle' : 'heroicon-o-sparkles') {{ ($message['role'] ?? '') === 'user' ? __('You') : __('Assistant') }}</p>
                            <p class="cu-assistant-text">{{ $message['content'] ?? '' }}</p>
                            @foreach ($this->resultGroups($message) as $result)
                                @if ($dashboard = $this->dailyDashboard($result))
                                    @include('clubunio.pages.partials.assistant-daily-dashboard', ['dashboard' => $dashboard, 'result' => $result])
                                @else
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
                                    @if($result['rows'] ?? [])
                                    <details class="cu-assistant-records"><summary>{{ trans('assistant_chat.results') }} ({{ count($result['rows']) }})</summary>
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
                                    </details>
                                    @endif
                                </section>
                                @endif
                            @endforeach
                        </article>
                    @empty
                        <div class="cu-assistant-empty">
                            @svg('heroicon-o-chat-bubble-left-right')
                            <h2>{{ trans('assistant_chat.intro') }}</h2>
                            <p>{{ __('No messages in this conversation.') }}</p>
                        </div>
                    @endforelse
                    <article x-cloak x-show="submitting && pendingText" class="cu-assistant-message" data-role="user">
                        <p class="cu-assistant-role">{{ __('You') }}</p><p class="cu-assistant-text" x-text="pendingText"></p>
                    </article>
                    <div x-cloak x-show="submitting" class="cu-assistant-thinking" role="status"><span></span><span></span><span></span> {{ trans('assistant_chat.typing') }}</div>
                </div>
                <div class="cu-assistant-examples" aria-label="{{ __('Example questions') }}">
                    <button type="button" wire:click="localQuery('reservations')" wire:loading.attr="disabled" @disabled(! $stateReady)>@svg('heroicon-o-calendar-days') {{ __('Which reservation requests are pending?') }}</button>
                    <button type="button" wire:click="localQuery('invoices')" wire:loading.attr="disabled" @disabled(! $stateReady)>@svg('heroicon-o-document-text') {{ __('Which invoices are still outstanding?') }}</button>
                </div>
                <form class="cu-assistant-composer" x-on:submit.prevent="if (submitting) return; submitting = true; pendingText = $refs.question.value; scrollToLatest(); try { if (await $wire.prepareQuestion()) await $wire.send(); } finally { submitting = false; pendingText = ''; scrollToLatest(); if (!$refs.question.disabled) $refs.question.focus(); }">
                    <label for="assistant-question">{{ __('Your question') }}</label>
                    <textarea id="assistant-question" x-ref="question" wire:model="question" rows="2" placeholder="{{ trans($providerEnabled ? 'assistant_chat.placeholder' : 'assistant_chat.local_placeholder') }}" x-on:keydown.enter="if (!$event.shiftKey && !$event.isComposing) { $event.preventDefault(); $el.form.requestSubmit(); }" maxlength="4000" @disabled(! $canAsk) wire:loading.attr="disabled" aria-invalid="{{ $errors->has('question') ? 'true' : 'false' }}" @if ($errors->has('question')) aria-describedby="assistant-question-error" @endif></textarea>
                    @error('question') <p id="assistant-question-error" class="cu-assistant-error" role="alert">{{ $message }}</p> @enderror
                    <div class="cu-assistant-send">
                        <span class="cu-assistant-status" role="status" wire:loading>{{ __('Assistant is working...') }}</span>
                        <x-filament::icon-button type="submit" icon="heroicon-o-paper-airplane" :label="$requestKey && $requestText === trim($question) ? __('Retry question') : trans('assistant_chat.send')" :tooltip="$requestKey && $requestText === trim($question) ? __('Retry question') : trans('assistant_chat.send')" :disabled="! $canAsk" wire:loading.attr="disabled" />
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

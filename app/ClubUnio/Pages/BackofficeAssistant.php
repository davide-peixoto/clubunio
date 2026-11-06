<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Assistant\BackofficeAssistantService;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

class BackofficeAssistant extends Page
{
    protected string $view = 'clubunio.pages.backoffice-assistant';

    protected static ?string $slug = 'backoffice-assistant';

    protected static ?int $navigationSort = 1;

    public string $question = '';

    #[Locked]
    public array $assistantState = [];

    #[Locked]
    public ?int $conversationId = null;

    #[Locked]
    public ?string $contextKey = null;

    #[Locked]
    public ?string $requestKey = null;

    #[Locked]
    public ?string $requestText = null;

    #[Locked]
    public ?int $requestConversationId = null;

    #[Locked]
    public bool $stateReady = false;

    // A request from the old shell must never execute in the newly selected club.
    protected bool $contextChanged = false;

    public static function getNavigationLabel(): string
    {
        return __('Assistant');
    }

    public function getTitle(): string
    {
        return __('ClubUnio Assistant');
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-chat-bubble-left-right';
    }

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        $tenant = Filament::getTenant();

        try {
            return $actor instanceof Account && $tenant instanceof Tenant
                && app(BackofficeAssistantService::class)->access($actor, $tenant);
        } catch (Throwable) {
            return false;
        }
    }

    public function mount(): void
    {
        $this->refreshState();
    }

    public function hydrate(): void
    {
        if ($context = $this->context()) {
            if ($this->contextChanged) {
                $this->loadState($context, null);
            }
        }
    }

    public function refreshState(): void
    {
        if ($context = $this->context()) {
            $this->loadState($context, $this->conversationId);
        }
    }

    public function newConversation(): void
    {
        if (! ($context = $this->context()) || $this->contextChanged) {
            return;
        }

        $this->clearConversation();
        $this->loadState($context, null);
    }

    public function selectConversation(int $id): void
    {
        if (! ($context = $this->context()) || $this->contextChanged) {
            return;
        }

        if ($id < 1) {
            $this->addError('assistant', __('Assistant conversation unavailable.'));

            return;
        }

        $this->clearConversation();
        $this->loadState($context, $id);
    }

    public function useExample(string $example): void
    {
        if (! $this->context() || $this->contextChanged) {
            return;
        }

        $this->question = match ($example) {
            'reservations' => __('Which reservation requests are pending?'),
            'invoices' => __('Which invoices are still outstanding?'),
            default => $this->question,
        };
    }

    public function send(): void
    {
        if (! ($context = $this->context()) || $this->contextChanged) {
            return;
        }

        $this->resetErrorBag();
        $this->question = trim($this->question);
        $this->validate(['question' => 'required|string|max:4000'], [
            'question.required' => __('Enter a question for the assistant.'),
            'question.max' => __('Assistant questions may contain at most :max characters.', ['max' => 4000]),
        ]);

        if (! $this->stateReady || ! ($this->assistantState['provider_enabled'] ?? false)) {
            $this->addError('assistant', __('AI is disabled. The daily overview remains available locally.'));

            return;
        }
        if ($this->budgetExhausted() && $this->requestText !== $this->question) {
            $this->addError('assistant', __('AI cost limit reached. The daily overview remains available locally.'));

            return;
        }

        if ($this->requestKey === null || $this->requestText !== $this->question) {
            $this->addError('assistant', __('Please submit the question again.'));

            return;
        }

        if ($this->run(fn () => app(BackofficeAssistantService::class)->ask(
            ...[...$context, $this->requestConversationId, $this->question, $this->requestKey],
        ))) {
            $this->question = '';
            $this->clearRequest();
        }
    }

    public function prepareQuestion(): bool
    {
        if (! $this->context() || $this->contextChanged) {
            return false;
        }

        $this->question = trim($this->question);
        $this->resetErrorBag();
        $this->validate(['question' => 'required|string|max:4000'], [
            'question.required' => __('Enter a question for the assistant.'),
            'question.max' => __('Assistant questions may contain at most :max characters.', ['max' => 4000]),
        ]);
        if (! $this->stateReady || ! ($this->assistantState['provider_enabled'] ?? false)) {
            $this->addError('assistant', __('AI is disabled. The daily overview remains available locally.'));

            return false;
        }
        if ($this->budgetExhausted() && ($this->requestKey === null || $this->requestText !== $this->question)) {
            $this->addError('assistant', __('AI cost limit reached. The daily overview remains available locally.'));

            return false;
        }

        // Persist identity to the browser before ask: a lost ask response can then be retried safely.
        if ($this->requestKey === null || $this->requestText !== $this->question) {
            $this->requestKey = (string) Str::uuid();
            $this->requestText = $this->question;
            $this->requestConversationId = $this->conversationId;
        }

        return true;
    }

    public function dailyOverview(): void
    {
        if (! ($context = $this->context()) || $this->contextChanged) {
            return;
        }

        $this->run(fn () => app(BackofficeAssistantService::class)->briefing(...[...$context, $this->conversationId]));
    }

    public function confirmAction(int $id): void
    {
        $this->resolveAssistantAction('confirm', $id);
    }

    public function cancelAction(int $id): void
    {
        $this->resolveAssistantAction('cancel', $id);
    }

    public function budgetExhausted(): bool
    {
        $budget = $this->assistantState['budget'] ?? [];

        return isset($budget['limit_usd'], $budget['used_usd'])
            && (float) $budget['used_usd'] >= (float) $budget['limit_usd'];
    }

    public function displayValue(mixed $value): string
    {
        return match (true) {
            $value === null => '-',
            is_bool($value) => $value ? __('Yes') : __('No'),
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '-',
        };
    }

    public function resultFields(array $row): array
    {
        $labels = [
            'id' => __('Record ID'), 'status_label' => __('Status'), 'workflow_label' => __('Workflow'),
            'submitted_on' => __('Submitted on'), 'amount' => __('Amount'), 'currency' => __('Currency'),
            'released_on' => __('Released on'), 'payment_released_on' => __('Payment approved on'),
            'paid_on' => __('Paid on'), 'open_amount' => __('Outstanding amount'),
            'balance_observed_at' => __('Balance checked on'), 'overdue' => __('Overdue'),
            'starts_at' => __('Starts at'), 'ends_at' => __('Ends at'), 'count' => __('Count'),
        ];
        $fields = [];
        foreach ($labels as $key => $label) {
            if (array_key_exists($key, $row)) {
                $fields[] = ['label' => $label, 'value' => $row[$key]];
            }
        }

        return $fields;
    }

    public function resultGroups(array $message): array
    {
        $groups = [];
        foreach ($message['results'] ?? [] as $result) {
            $groups[] = $result;
            foreach ($result['sections'] ?? [] as $section) {
                $groups[] = $section;
            }
        }

        return $groups;
    }

    public function resultCountGroups(array $result): array
    {
        $counts = $result['counts'] ?? [];
        if (! is_array($counts)) {
            return [];
        }

        $modules = [
            'members' => __('Mitglieder'), 'registrations' => __('Anmeldungen'),
            'expenses' => __('Spesen'), 'invoices' => __('Rechnungen'),
            'bus' => __('Busreservierungen'), 'pitch' => __('Sportplatzbelegung'),
        ];
        $groups = [];
        foreach ($modules as $module => $title) {
            if (! isset($counts[$module]) || ! is_array($counts[$module])) {
                continue;
            }
            $labels = [
                'matching' => __('Matching records'), 'returned' => __('Records shown'),
                $module.'_pending_all_dates' => __('Pending requests (all dates)'),
                $module.'_pending_before_date' => __('Pending requests before selected day'),
                $module.'_on_date' => __('Reservations on selected day'),
                'overdue' => __('Overdue'),
            ];
            $metrics = [];
            foreach ($labels as $key => $label) {
                if (! array_key_exists($key, $counts[$module])) {
                    continue;
                }
                $value = $counts[$module][$key];
                if ($value === null || (is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value))) {
                    $metrics[] = ['key' => $key, 'label' => $label, 'value' => $value ?? __('Not available')];
                }
            }
            if ($metrics !== []) {
                $groups[] = ['module' => $module, 'title' => $title, 'metrics' => $metrics];
            }
        }

        return $groups;
    }

    public function resultUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', rawurldecode($url))) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (str_starts_with($url, '/') && ! str_starts_with(rawurldecode($url), '//')) {
            return ! isset($parts['host']) && ! isset($parts['scheme']) ? $url : null;
        }
        $origin = parse_url(url('/'));

        return in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && ($parts['scheme'] ?? null) === ($origin['scheme'] ?? null)
            && ($parts['host'] ?? null) === ($origin['host'] ?? null)
            && ($parts['port'] ?? null) === ($origin['port'] ?? null) ? $url : null;
    }

    private function resolveAssistantAction(string $method, int $id): void
    {
        if (! ($context = $this->context()) || $this->contextChanged) {
            return;
        }

        $action = collect($this->assistantState['actions'] ?? [])->firstWhere('id', $id);
        if (! $this->stateReady || $id < 1 || ($action['status'] ?? null) !== 'pending') {
            $this->addError('assistant', __('Assistant action unavailable. Reload the conversation.'));

            return;
        }

        $this->run(fn () => app(BackofficeAssistantService::class)->{$method}(...[...$context, $id]));
    }

    private function context(): ?array
    {
        $actor = Filament::auth()->user();
        $tenant = Filament::getTenant();
        abort_unless($actor instanceof Account && $tenant instanceof Tenant, 403);
        $key = $actor->getKey().':'.$tenant->getKey();
        if ($this->contextKey !== $key) {
            $this->contextChanged = $this->contextKey !== null;
            $this->clearConversation();
            $this->contextKey = $key;
        }

        try {
            $allowed = app(BackofficeAssistantService::class)->access($actor, $tenant);
        } catch (Throwable) {
            $this->assistantState = [];
            $this->stateReady = false;
            $this->addError('assistant', __('The assistant is currently unavailable. Please try again later.'));

            return null;
        }
        if (! $allowed) {
            $this->clearConversation();
            abort(403);
        }

        return [$actor, $tenant];
    }

    private function loadState(array $context, ?int $conversationId): void
    {
        $this->stateReady = false;
        $this->run(fn () => app(BackofficeAssistantService::class)->state(...[...$context, $conversationId]));
    }

    private function run(callable $operation): bool
    {
        $this->resetErrorBag('assistant');
        try {
            $state = $operation();
            $this->assistantState = $state;
            $this->conversationId = $state['conversation_id'] ?? null;
            $this->stateReady = true;

            return true;
        } catch (ValidationException $error) {
            // Only this contract field is safe for display; never expose other exception details.
            $this->addError('assistant', $error->errors()['assistant'][0]
                ?? __('The assistant is currently unavailable. Please try again later.'));
        } catch (Throwable) {
            $this->addError('assistant', __('The assistant is currently unavailable. Please try again later.'));
        }

        return false;
    }

    private function clearConversation(): void
    {
        $this->assistantState = [];
        $this->stateReady = false;
        $this->conversationId = null;
        $this->question = '';
        $this->clearRequest();
        $this->resetErrorBag();
    }

    private function clearRequest(): void
    {
        $this->requestKey = null;
        $this->requestText = null;
        $this->requestConversationId = null;
    }
}

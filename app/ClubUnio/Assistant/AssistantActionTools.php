<?php

namespace App\ClubUnio\Assistant;

use App\ClubUnio\Mobile\MobileReadContext;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\BusReservationService;
use App\ClubUnio\Services\ExpenseService;
use App\ClubUnio\Services\ImpersonationService;
use App\ClubUnio\Services\LookupTranslations;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\MemberService;
use App\ClubUnio\Services\ModulePermissionService;
use App\ClubUnio\Services\PitchPlanningGeometry;
use App\ClubUnio\Services\PitchPlanningProjection;
use App\ClubUnio\Services\PitchPlanningService;
use App\ClubUnio\Services\RegistrationReviewService;
use Carbon\CarbonImmutable as Time;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Only these seven operations are supported. No acceptance/member creation,
 * financial release/payment, deletion, roles, URLs or external provider calls.
 * The caller owns durable approval, expiry and single-execution claims. Never
 * expose execute directly as a model tool. Keep _revision in approved arguments.
 */
class AssistantActionTools
{
    private const CONTACT = ['email', 'street', 'house_number', 'postal_code', 'city', 'phone_private', 'phone_business', 'mobile'];

    private const OPERATIONS = [
        'prepare_member_contact' => ['members', 'update'],
        'prepare_registration_review' => ['registrations', 'approve'],
        'prepare_bus_approve' => ['bus', 'approve'],
        'prepare_bus_reject' => ['bus', 'approve'],
        'prepare_pitch_approve' => ['competitions', 'update'],
        'prepare_pitch_reject' => ['competitions', 'update'],
        'prepare_expense_status' => ['expenses', 'update'],
    ];

    public function definitions(): array
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $reason = ['type' => 'string', 'minLength' => 10, 'maxLength' => 500];
        $definitions = [];
        foreach (array_keys(self::OPERATIONS) as $name) {
            $properties = ['id' => $id] + match ($name) {
                'prepare_member_contact' => ['changes' => ['type' => 'array', 'minItems' => 1, 'maxItems' => count(self::CONTACT),
                    'items' => $this->objectSchema(['field' => ['type' => 'string', 'enum' => self::CONTACT],
                        'value' => ['type' => ['string', 'null'], 'maxLength' => 255]])]],
                'prepare_registration_review' => ['status' => ['type' => 'string', 'enum' => ['review', 'trial', 'pool', 'rejected']],
                    'team_season_id' => ['type' => ['integer', 'null'], 'minimum' => 1], 'reason' => $reason],
                'prepare_bus_approve', 'prepare_bus_reject' => ['note' => ['type' => ['string', 'null'], 'maxLength' => 1000]],
                'prepare_expense_status' => ['status' => ['type' => 'string', 'enum' => ['O', 'R']], 'reason' => $reason],
                default => [],
            };
            $definitions[] = ['type' => 'function', 'name' => $name, 'strict' => true,
                'description' => __(('assistant.action.'.$name)), 'parameters' => $this->objectSchema($properties)];
        }

        return $definitions;
    }

    public function prepare(Account $actor, Tenant $tenant, string $name, array $arguments): array
    {
        return $this->freshContext(function () use ($actor, $tenant, $name, $arguments) {
            $this->known($name);
            $actor = $this->authorize($actor, $tenant, $name, $arguments);
            $data = $this->normalize($name, $arguments);
            $state = $this->inspect($actor, $tenant, $name, $data, false);
            $revision = $this->fingerprint($actor, $tenant, $name, $data, $state['snapshot']);
            if (array_key_exists('_revision', $arguments)) {
                $this->checkRevision($arguments['_revision'], $revision);
            }

            return ['operation' => $name, 'arguments' => $data + ['_revision' => $revision],
                'preview' => ['title' => __(('assistant.action.'.$name)), 'changes' => $state['changes'],
                    'effects' => array_map(fn ($key) => __(('assistant.action.effect.'.$key)), $state['effects'])],
                'revision' => $revision];
        });
    }

    public function execute(Account $actor, Tenant $tenant, string $operation, array $arguments): array
    {
        $this->known($operation);
        // Reject calls without a prepared snapshot before opening a transaction.
        $this->checkRevision($arguments['_revision'] ?? null, null);

        return $this->freshContext(fn () => DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $operation, $arguments) {
            $tenant = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $actor = $this->authorize($actor, $tenant, $operation, $arguments, true);
            $data = $this->normalize($operation, $arguments);
            $state = $this->inspect($actor, $tenant, $operation, $data, true);
            $this->checkRevision($arguments['_revision'], $this->fingerprint($actor, $tenant, $operation, $data, $state['snapshot']));
            $row = $state['row'];
            $revision = match ($operation) {
                'prepare_member_contact' => MemberService::version(app(MemberService::class)->update($actor, $tenant, $data['id'],
                    array_column($data['changes'], 'value', 'field') + ['_version' => MemberService::version($row)])),
                'prepare_registration_review' => (string) app(RegistrationReviewService::class)->review($actor, $tenant, $data['id'], [
                    'revision' => (int) $row->revision, 'status_id' => (int) $state['target']->id,
                    'team_season_id' => $data['team_season_id'], 'reason' => $data['reason'],
                ])->revision,
                'prepare_bus_approve', 'prepare_bus_reject' => $this->executeBus($actor, $tenant, $operation, $data, $row),
                'prepare_pitch_approve', 'prepare_pitch_reject' => app(PitchPlanningService::class)->decide($actor, $tenant, $data['id'], [
                    'revision' => (string) $row->revision, 'decision' => $this->decision($operation),
                ])['revision'],
                'prepare_expense_status' => (string) app(ExpenseService::class)->transition($actor, $tenant, $data['id'], [
                    'revision' => (int) $row->revision, 'status_id' => (int) $state['target']->id, 'reason' => $data['reason'],
                ])->revision,
            };

            return ['operation' => $operation, 'status' => 'executed', 'id' => $data['id'], 'revision' => $revision];
        }));
    }

    private function objectSchema(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    private function known(string $name): void
    {
        if (! isset(self::OPERATIONS[$name])) {
            $this->invalid('operation', 'unsupported');
        }
    }

    private function freshContext(\Closure $callback): mixed
    {
        abort_if(request()->attributes->has('clubunio_impersonation')
            || (request()->hasSession() && request()->session()->has(ImpersonationService::KEY)), 403);
        $frames = [];
        foreach ([BackofficeReadContext::class, MobileReadContext::class] as $key) {
            $frames[$key] = request()->attributes->get($key);
            request()->attributes->remove($key);
        }
        try {
            return $callback();
        } finally {
            foreach ($frames as $key => $value) {
                if ($value !== null) {
                    request()->attributes->set($key, $value);
                } else {
                    request()->attributes->remove($key);
                }
            }
        }
    }

    private function authorize(Account $actor, Tenant $tenant, string $name, array $arguments, bool $lock = false): Account
    {
        $actor = Account::whereKey($actor->id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        // Tenants have no active column; domain activation is not a club-wide access flag.
        abort_unless($actor?->active && ! $actor->api_only && Tenant::whereKey($tenant->id)->exists(), 403);
        $membership = $this->table($tenant, 'tenant_accounts')->where('account_id', $actor->id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($membership && in_array($membership->role, ['admin', 'office'], true), 403);
        [$module, $function] = self::OPERATIONS[$name];
        if ($name === 'prepare_expense_status' && ($arguments['status'] ?? null) === 'R') {
            $function = 'cancel';
        }
        app(ModulePermissionService::class)->authorize($actor, $tenant, $module, 'view');
        app(ModulePermissionService::class)->authorize($actor, $tenant, $module, $function);

        return $actor;
    }

    private function normalize(string $name, array $arguments): array
    {
        unset($arguments['_revision']);
        $definition = collect($this->definitions())->firstWhere('name', $name);
        $this->shape($arguments, $definition['parameters']);
        // Stable order independent of JSON key order; null explicitly clears a contact/team.
        $data = array_replace(array_fill_keys(array_keys($definition['parameters']['properties']), null), $arguments);
        foreach (['reason', 'note'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }
        if (isset($data['reason']) && mb_strlen($data['reason']) < 10) {
            $this->invalid('reason');
        }
        if ($name === 'prepare_member_contact') {
            $changes = [];
            foreach ($data['changes'] as $change) {
                $field = $change['field'];
                if (array_key_exists($field, $changes)) {
                    $this->invalid('changes');
                }
                $value = $change['value'] === null ? null : trim($change['value']);
                $value = $value === '' ? null : $value;
                $rule = match ($field) {
                    'email' => 'nullable|email:rfc|max:254',
                    'house_number', 'postal_code' => 'nullable|string|max:20',
                    'phone_private', 'phone_business', 'mobile' => 'nullable|string|max:50',
                    default => 'nullable|string|max:255',
                };
                Validator::make([$field => $value], [$field => $rule], [], [$field => __(('assistant.action.label.'.$field))])->validate();
                $changes[$field] = $value;
            }
            ksort($changes);
            $data['changes'] = [];
            foreach ($changes as $field => $value) {
                $data['changes'][] = ['field' => $field, 'value' => $value];
            }
        }

        return $data;
    }

    /** Enforce the same closed schema locally, including JSON types (no coercion). */
    private function shape(mixed $value, array $schema, string $path = 'arguments'): void
    {
        $type = match (true) {
            $value === null => 'null', is_int($value) => 'integer', is_string($value) => 'string',
            is_array($value) => ($schema['type'] === 'object' ? 'object' : 'array'), default => 'invalid',
        };
        if (! in_array($type, (array) $schema['type'], true)
            || (isset($schema['enum']) && ! in_array($value, $schema['enum'], true))) {
            $this->invalid($path);
        }
        if ($type === 'object') {
            if (array_diff(array_keys($value), array_keys($schema['properties'])) || array_diff($schema['required'], array_keys($value))) {
                $this->invalid($path);
            }
            foreach ($value as $key => $child) {
                $this->shape($child, $schema['properties'][$key], $path.'.'.$key);
            }
        } elseif ($type === 'array') {
            if (! array_is_list($value) || count($value) < $schema['minItems'] || count($value) > $schema['maxItems']) {
                $this->invalid($path);
            }
            foreach ($value as $key => $child) {
                $this->shape($child, $schema['items'], $path.'.'.$key);
            }
        } elseif (($type === 'integer' && $value < ($schema['minimum'] ?? 0))
            || ($type === 'string' && (mb_strlen($value) > ($schema['maxLength'] ?? PHP_INT_MAX)
                || mb_strlen($value) < ($schema['minLength'] ?? 0)))) {
            $this->invalid($path);
        }
    }

    private function inspect(Account $actor, Tenant $tenant, string $name, array $data, bool $lock): array
    {
        return match ($name) {
            'prepare_member_contact' => $this->member($tenant, $data, $lock),
            'prepare_registration_review' => $this->registration($actor, $tenant, $data, $lock),
            'prepare_bus_approve', 'prepare_bus_reject' => $this->bus($actor, $tenant, $name, $data, $lock),
            'prepare_pitch_approve', 'prepare_pitch_reject' => $this->pitch($tenant, $name, $data, $lock),
            'prepare_expense_status' => $this->expense($actor, $tenant, $data, $lock),
        };
    }

    private function member(Tenant $tenant, array $data, bool $lock): array
    {
        $row = Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNull('archived_at')
            ->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($data['id']);
        $values = array_replace($row->only(MemberService::FIELDS), array_column($data['changes'], 'value', 'field'));
        $recipient = $row->billing_recipient_id ? $this->table($tenant, 'billing_recipients')
            ->when($lock, fn ($q) => $q->sharedLock())->find($row->billing_recipient_id) : null;
        if ($row->billing_recipient_id && (! $recipient || empty($values[$recipient->email_field]))) {
            $this->invalid('changes', 'recipient');
        }
        $status = $row->status_id ? $this->table($tenant, 'member_statuses')
            ->when($lock, fn ($q) => $q->sharedLock())->find($row->status_id) : null;
        // Do not let MemberService implicitly set an exit date during contact-only editing.
        if ($status?->ends_membership && ! $row->exit_date) {
            $this->invalid('changes', 'business');
        }
        $changes = [$this->change('subject', $row->display_name, $row->display_name)];
        foreach ($data['changes'] as $change) {
            $changes[] = $this->change($change['field'], $row->{$change['field']}, $change['value']);
        }
        if (! array_filter($changes, fn ($change) => $change['before'] !== $change['after'])) {
            $this->invalid('changes', 'unchanged');
        }

        return $this->state($row, $changes, ['audit', 'contact'], [$recipient, $status]);
    }

    private function registration(Account $actor, Tenant $tenant, array $data, bool $lock): array
    {
        $service = app(RegistrationReviewService::class);
        $row = $service->visible($actor, $tenant)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($data['id']);
        abort_unless($service->canReview($actor, $tenant, $row), 403);
        $target = $this->table($tenant, 'registration_statuses')->where('active', true)->where('workflow_key', $data['status'])
            ->when($lock, fn ($q) => $q->sharedLock())->sole();
        $old = $this->table($tenant, 'registration_statuses')->when($lock, fn ($q) => $q->sharedLock())->find($row->status_id);
        if (! $old || $old->workflow_key === 'accepted') {
            $this->invalid('status', 'business');
        }
        $team = $data['team_season_id'] === null ? null : $service->assignableTeams($actor, $tenant)
            ->when($lock, fn ($q) => $q->sharedLock())->findOrFail($data['team_season_id']);
        if ($data['status'] === 'trial' && ! $team) {
            $this->invalid('team_season_id', 'business');
        }
        if ((int) $row->status_id === (int) $target->id && $row->team_season_id === $data['team_season_id']) {
            $this->invalid('status', 'unchanged');
        }
        $oldTeam = $row->team_season_id ? $this->table($tenant, 'team_seasons')
            ->when($lock, fn ($q) => $q->sharedLock())->find($row->team_season_id) : null;
        $effects = ['audit'];
        if ((int) $row->status_id !== (int) $target->id) {
            $effects[] = 'registration';
        }

        return $this->state($row, [
            $this->change('subject', trim($row->first_name.' '.$row->last_name), trim($row->first_name.' '.$row->last_name)),
            $this->change('status', $this->label('registration_statuses', $old, $tenant), $this->label('registration_statuses', $target, $tenant)),
            $this->change('team', $oldTeam?->label, $team?->label), $this->change('reason', null, $data['reason']),
        ], $effects, [$old, $target, $oldTeam, $team?->getAttributes()]) + ['target' => $target];
    }

    private function bus(Account $actor, Tenant $tenant, string $name, array $data, bool $lock): array
    {
        $service = app(BusReservationService::class);
        $row = $service->visible($actor, $tenant)->where('id', $data['id'])->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        if (! in_array($row->status, ['ANGEFRAGT', 'UNBEKANNT'], true) || Time::parse($row->ends_at) <= now()) {
            $this->invalid('status', 'business');
        }
        $status = $name === 'prepare_bus_approve' ? 'GENEHMIGT' : 'ABGELEHNT';
        $target = $this->table($tenant, 'bus_statuses')->where('code', $status)->where('active', true)
            ->when($lock, fn ($q) => $q->sharedLock())->firstOrFail();
        $old = $this->table($tenant, 'bus_statuses')->where('code', $row->status)->when($lock, fn ($q) => $q->sharedLock())->first();
        $bus = $this->table($tenant, 'buses')->when($lock, fn ($q) => $q->sharedLock())->find($row->bus_id);
        $team = $row->team_season_id ? $service->reservableTeams($actor, $tenant)
            ->when($lock, fn ($q) => $q->sharedLock())->find($row->team_season_id) : null;
        if ($status === 'GENEHMIGT') {
            if (! $bus?->active || ! $team || ! $row->member_id
                || ! app(MemberAccessService::class)->activeMembers($tenant)->whereKey($row->member_id)->exists()) {
                $this->invalid('id', 'business');
            }
            if ($this->table($tenant, 'bus_reservations')->where('active', true)->where('bus_id', $row->bus_id)
                ->whereIn('status', ['ANGEFRAGT', 'GENEHMIGT', 'UNBEKANNT'])->where('id', '<>', $row->id)
                ->where('starts_at', '<', $row->ends_at)->where('ends_at', '>', $row->starts_at)->exists()) {
                $this->invalid('id', 'conflict');
            }
        }

        return $this->state($row, [
            $this->change('bus', $bus?->name, $bus?->name), $this->change('starts_at', $row->starts_at, $row->starts_at),
            $this->change('ends_at', $row->ends_at, $row->ends_at),
            $this->change('status', $this->label('bus_statuses', $old, $tenant), $this->label('bus_statuses', $target, $tenant)),
            $this->change('note', $row->decision_note, $data['note']),
        ], ['audit', 'bus'], [$target, $old, $bus, $team]);
    }

    private function pitch(Tenant $tenant, string $name, array $data, bool $lock): array
    {
        $projection = app(PitchPlanningProjection::class);
        abort_unless($projection->ready(), 503);
        $row = $this->table($tenant, 'pitch_requests')->where('id', $data['id'])->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
        if ($row->status !== 'pending') {
            $this->invalid('status', 'business');
        }
        $team = $this->table($tenant, 'team_seasons')->where('active', true)->when($lock, fn ($q) => $q->sharedLock())->find($row->team_season_id);
        if (! $team || ! $this->table($tenant, 'teams')->where('active', true)
            ->when($lock, fn ($q) => $q->sharedLock())->find($team->team_id)) {
            $this->invalid('id', 'business');
        }
        $rawField = $this->table($tenant, 'training_options')->when($lock, fn ($q) => $q->sharedLock())->find($row->field_id);
        $rawArea = $row->area_id ? $this->table($tenant, 'pitch_areas')->when($lock, fn ($q) => $q->sharedLock())->find($row->area_id) : null;
        $entry = $projection->stored('request', $row);
        $fields = $projection->fields($tenant);
        if ($name === 'prepare_pitch_approve' && PitchPlanningGeometry::conflicts($entry,
            $projection->entries($tenant, $entry['date'], Time::parse($entry['ends_at_iso'])->toDateString()), $fields) !== []) {
            $this->invalid('id', 'conflict');
        }
        $field = collect($fields)->firstWhere('id', (int) $row->field_id);
        $area = collect($field['areas'] ?? [])->firstWhere('id', (int) $row->area_id);

        return $this->state($row, [
            $this->change('team', $team->label, $team->label), $this->change('opponent', $row->opponent, $row->opponent),
            $this->change('field', $field['name'] ?? null, $field['name'] ?? null),
            $this->change('area', $area['name'] ?? null, $area['name'] ?? null),
            $this->change('starts_at', $entry['starts_at_iso'], $entry['starts_at_iso']),
            $this->change('ends_at', $entry['ends_at_iso'], $entry['ends_at_iso']),
            $this->change('status', __('assistant.action.status.pending'), __(('assistant.action.status.'.$this->decision($name)))),
        ], ['audit', 'pitch'], [$team, $rawField, $rawArea]);
    }

    private function expense(Account $actor, Tenant $tenant, array $data, bool $lock): array
    {
        $service = app(ExpenseService::class);
        $row = $service->visible($actor, $tenant)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail($data['id']);
        $old = $this->table($tenant, 'expense_statuses')->when($lock, fn ($q) => $q->sharedLock())->find($row->status_id);
        $target = $this->table($tenant, 'expense_statuses')->where('active', true)->where('code', $data['status'])
            ->when($lock, fn ($q) => $q->sharedLock())->sole();
        if (! in_array($old?->code, ['O', 'F', 'R'], true) || ! in_array($data['status'], $service->targets($actor, $tenant, $row), true)
            || $row->payment_released_on || $row->payment_released_by || $row->paid_on || $row->paid_by
            || $row->bexio_bill_id || $row->bexioBalance()->exists()
            || $this->table($tenant, 'bexio_sandbox_dispatches')->where('expense_id', $row->id)->exists()
            || $this->table($tenant, 'bexio_dispatch_trials')->where('object_type', 'expense')->where('object_id', $row->id)->exists()) {
            $this->invalid('status', 'business');
        }
        $member = Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->when($lock, fn ($q) => $q->sharedLock())->find($row->member_id);
        $team = $row->team_season_id ? $this->table($tenant, 'team_seasons')->when($lock, fn ($q) => $q->sharedLock())->find($row->team_season_id) : null;
        $season = $row->season_id ? $this->table($tenant, 'seasons')->when($lock, fn ($q) => $q->sharedLock())->find($row->season_id) : null;
        $changes = [$this->change('subject', $member?->display_name, $member?->display_name),
            $this->change('team', $team?->label, $team?->label), $this->change('season', $season?->name, $season?->name),
            $this->change('status', $this->label('expense_statuses', $old, $tenant), $this->label('expense_statuses', $target, $tenant)),
            $this->change('reason', null, $data['reason'])];
        foreach (['released_on', 'released_by', 'payment_released_on', 'payment_released_by', 'paid_on', 'paid_by'] as $field) {
            $changes[] = $this->change($field, $row->getRawOriginal($field), null);
        }

        return $this->state($row, $changes, ['audit', 'expense'], [$old, $target,
            $member?->only(['id', 'first_name', 'last_name', 'company', 'member_number', 'legacy_id']), $team, $season]) + ['target' => $target];
    }

    private function executeBus(Account $actor, Tenant $tenant, string $name, array $data, object $row): string
    {
        app(BusReservationService::class)->decide($actor, $tenant, $data['id'], (int) $row->revision,
            $name === 'prepare_bus_approve' ? 'GENEHMIGT' : 'ABGELEHNT', $data['note']);

        return (string) ($row->revision + 1);
    }

    private function decision(string $name): string
    {
        return $name === 'prepare_pitch_approve' ? 'approved' : 'rejected';
    }

    private function state(object $row, array $changes, array $effects, array $dependencies = []): array
    {
        array_unshift($changes, $this->change('id', (int) $row->id, (int) $row->id));

        return ['row' => $row, 'changes' => $changes, 'effects' => $effects,
            'snapshot' => [$row instanceof Model ? $row->getAttributes() : (array) $row, $dependencies]];
    }

    private function fingerprint(Account $actor, Tenant $tenant, string $name, array $data, array $snapshot): string
    {
        // Raw persisted values, never translated labels or relative times. No secret is returned.
        return hash('sha256', json_encode([(int) $actor->id, (int) $tenant->id, $name, $data, $snapshot], JSON_THROW_ON_ERROR));
    }

    private function checkRevision(mixed $revision, ?string $expected): void
    {
        if (! is_string($revision) || ! preg_match('/^[a-f0-9]{64}$/D', $revision)
            || ($expected !== null && ! hash_equals($expected, $revision))) {
            $this->invalid('_revision', 'stale');
        }
    }

    private function change(string $label, mixed $before, mixed $after): array
    {
        return ['label' => __(('assistant.action.label.'.$label)), 'before' => $before, 'after' => $after];
    }

    private function label(string $table, ?object $row, Tenant $tenant): ?string
    {
        // Existing per-tenant language variants, with the documented original-label fallback.
        return $row ? app(LookupTranslations::class)->label($table, $row, null, (int) $tenant->id) : null;
    }

    private function table(Tenant $tenant, string $table): Builder
    {
        return DB::connection('clubunio')->table('clubora.'.$table)->where('tenant_id', $tenant->id);
    }

    private function invalid(string $field, string $reason = 'invalid'): never
    {
        throw ValidationException::withMessages([$field => __(('assistant.action.error.'.$reason))]);
    }
}

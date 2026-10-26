<?php

namespace App\ClubUnio\Api\Modules;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Models\Announcement;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\ExpenseLine;
use App\ClubUnio\Models\Family;
use App\ClubUnio\Models\FamilyMember;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\InvoiceLine;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MessageTemplate;
use App\ClubUnio\Models\Registration;
use App\ClubUnio\Services\AnnouncementService;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\BusReservationService;
use App\ClubUnio\Services\ContributionInvoiceService;
use App\ClubUnio\Services\ExpenseService;
use App\ClubUnio\Services\FamilyService;
use App\ClubUnio\Services\InvoiceWorkflowService;
use App\ClubUnio\Services\MessageTemplateService;
use App\ClubUnio\Services\PlanningGuestService;
use App\ClubUnio\Services\PlanningSeriesService;
use App\ClubUnio\Services\RegistrationDataService;
use App\ClubUnio\Services\RegistrationReviewService;
use App\ClubUnio\Services\TeamPlanningService;
use App\ClubUnio\Services\TrainingPlanContent;
use App\ClubUnio\Services\TrainingPlanService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Local workflows only. The registry owns durable request idempotency. */
final class WorkflowApiModules
{
    private const LOOKUP_TABLES = [
        'planning_options' => 'planning_options',
        'bus_reservation_statuses' => 'bus_statuses',
        'material_receipt_statuses' => 'material_receipt_statuses',
        'announcement_statuses' => 'announcement_statuses',
        'sponsor_run_statuses' => 'sponsor_run_statuses',
    ];

    public function definitions(): array
    {
        $definitions = [];
        $add = function ($key, $model, $module, $read, $search, $filters, $create, $update, $version = 'revision', $actions = []) use (&$definitions) {
            foreach ($actions as $action => $function) {
                $actions[$action] = ['function' => $function, 'fields' => $this->actionFields($key, $action)];
                $actions[$action]['required'] = $this->actionRequired($key, $action);
                $actions[$action]['schema'] = $this->actionSchema($key, $action);
            }
            $definitions[$key] = ['key' => $key, 'model' => $model, 'permission_module' => $module,
                'read_fields' => $read, 'search_fields' => $search, 'filter_fields' => $filters,
                'create_fields' => $create, 'update_fields' => $update, 'version' => $version, 'actions' => $actions];
        };
        $add('expenses', Expense::class, 'expenses', ['id', 'member_id', 'team_season_id', 'season_id', 'round_id', 'status_id', 'revision', 'active'], [], ['member_id', 'team_season_id', 'status_id', 'active'],
            ['member_id', 'team_season_id', 'bank_account_id', 'round_id', 'notes'], ['revision', 'member_id', 'team_season_id', 'bank_account_id', 'round_id', 'notes'], 'revision',
            ['submit' => 'send', 'approve' => 'approve', 'reject' => 'cancel', 'reopen' => 'update']);
        $add('expense_lines', ExpenseLine::class, 'expenses', ['id', 'expense_id', 'expense_type_id', 'game_type_id', 'opponent', 'amount', 'suggested_amount', 'suggestion_accepted', 'revision', 'active'], ['opponent'], ['expense_id', 'expense_type_id', 'active'],
            ['expense_id', 'expense_revision', 'expense_type_id', 'game_type_id', 'opponent', 'amount', 'notes'], ['revision', 'expense_revision', 'expense_type_id', 'game_type_id', 'opponent', 'amount', 'notes', 'active']);
        $add('invoices', Invoice::class, 'invoices', ['id', 'member_id', 'season_id', 'invoice_category_id', 'workflow_status_id', 'tax_mode', 'cash_rounding', 'rounding_cents', 'revision', 'active'], [], ['member_id', 'season_id', 'workflow_status_id', 'active'],
            ['member_id', 'season_id', 'invoice_category_id', 'tax_mode', 'notes'], ['revision', 'invoice_category_id', 'tax_mode', 'notes'], 'revision',
            ['add_line' => 'create', 'update_line' => 'update', 'remove_line' => 'deactivate', 'restore_line' => 'restore', 'release' => 'approve', 'cancel' => 'cancel', 'archive' => 'deactivate', 'restore' => 'restore']);
        $add('invoice_lines', InvoiceLine::class, 'invoices', ['id', 'invoice_id', 'description', 'gross_amount', 'discount', 'net_amount', 'status_id', 'contribution', 'active'], ['description'], ['invoice_id', 'active'], [], [], null);
        $add('registrations', Registration::class, 'registrations', ['id', 'first_name', 'last_name', 'email', 'city', 'birth_date', 'status_id', 'team_season_id', 'technical_lead_member_id', 'member_id', 'revision', 'active'], ['first_name', 'last_name'], ['status_id', 'team_season_id', 'active'],
            [...RegistrationDataService::CORE, ...RegistrationDataService::DETAILS], [...RegistrationDataService::CORE, ...RegistrationDataService::DETAILS, 'revision'], 'revision', ['review' => 'approve', 'accept' => 'approve']);
        $definitions['registrations']['actions']['accept']['description'] = 'Local acceptance through RegistrationReviewService: creates/updates or restores a member, copies applicant/AHV/portrait data and existing identity-document references, creates notes/team-player assignments, proposes pending parent links and records audit/notification/outbox events. No provider IO or account-link approval. Requires members:create or members:update for the effective member; existing members with exit_date or a status ending membership additionally require members:restore in token and profile. New members do not require restore. Also requires teams:create for an effective team allocation and documents:create when non-portrait documents are copied. Existing members require member_version; pending Azure uploads block acceptance.';
        $family = ['name', 'street', 'city', 'postal_code', 'country_id', 'active'];
        $add('families', Family::class, 'families', ['id', ...$family], ['name'], ['active'], $family, [...$family, '_version'], '_version');
        $fm = ['family_id', 'member_id', 'discount', 'active'];
        $add('family_members', FamilyMember::class, 'families', ['id', ...$fm], [], ['family_id', 'member_id', 'active'], [...$fm, 'notes'], [...$fm, 'notes', '_version'], '_version');
        $event = ['team_season_id', 'title', 'kind', 'starts_at', 'ends_at', 'deadline_at', 'location', 'description', 'mode', 'status', 'capacity'];
        $add('team_events', null, 'planning', ['id', ...array_diff($event, ['description']), 'revision', 'series_key', 'series_exception'], ['title', 'location'], ['team_season_id', 'kind', 'status', 'series_key'], [...$event, 'repeat_count'], [...$event, 'revision'], 'revision',
            ['participate' => 'update', 'invitations' => 'view', 'invite' => 'send', 'uninvite' => 'cancel', 'series_preview' => 'update', 'series_apply' => 'update']);
        $absence = ['starts_on', 'ends_on', 'kind', 'note'];
        $add('team_absences', null, 'planning', ['id', 'member_id', 'starts_on', 'ends_on', 'revision'], [], ['member_id'], ['member_id', ...$absence], [...$absence, 'revision'], 'revision', ['deactivate' => 'deactivate']);
        $add('team_participations', null, 'planning', ['id', 'event_id', 'member_id', 'status', 'attendance', 'revision'], [], ['event_id', 'member_id', 'status'], [], ['revision', 'status', 'attendance', 'comment']);
        $guest = ['first_name', 'last_name', 'status', 'attendance', 'comment'];
        $add('team_guests', null, 'planning', ['id', 'event_id', 'first_name', 'last_name', 'status', 'attendance', 'revision'], ['first_name', 'last_name'], ['event_id', 'status'], ['event_id', ...$guest], ['revision', ...$guest], 'revision', ['deactivate' => 'deactivate']);
        $add('training_plans', null, 'training', ['id', 'team_season_id', 'title', 'shared', 'revision', 'archived_at', 'legacy_quality_pending'], ['title'], ['team_season_id', 'shared'], [], ['revision', 'plan', 'shared'], 'revision',
            ['content' => 'view', 'archive' => 'deactivate', 'restore' => 'restore', 'copy' => 'copy']);
        $definitions['training_plans']['update_schema'] = ['type' => 'object', 'additionalProperties' => false, 'required' => ['revision', 'plan', 'shared'],
            'properties' => ['revision' => ['type' => 'integer', 'minimum' => 1], 'plan' => app(TrainingPlanContent::class)->schema(), 'shared' => ['type' => 'boolean']]];
        $add('buses', null, 'bus', ['id', 'name', 'active'], ['name'], ['active'], [], [], null);
        $add('bus_reservations', null, 'bus', ['id', 'bus_id', 'member_id', 'team_season_id', 'status', 'starts_at', 'ends_at', 'location', 'revision', 'active'], ['location'], ['bus_id', 'team_season_id', 'status', 'active'],
            ['bus_id', 'member_id', 'team_season_id', 'status', 'starts_at', 'ends_at', 'location', 'description'], ['revision', 'bus_id', 'starts_at', 'ends_at', 'location', 'description', 'reason'], 'revision', ['approve' => 'approve', 'reject' => 'approve', 'cancel' => 'cancel']);
        $announcement = ['title', 'body', 'target_type', 'target_ids'];
        $add('announcements', Announcement::class, 'announcements', ['id', 'title', 'body', 'target_type', 'state', 'revision'], ['title'], ['state'], $announcement, [...$announcement, 'revision']);
        $template = ['event_key', 'channel', 'locale', 'title', 'body', 'active'];
        $add('message_templates', MessageTemplate::class, 'templates', ['id', ...$template, 'revision'], ['title'], ['event_key', 'channel', 'locale', 'active'], $template, ['title', 'body', 'active', 'revision']);
        // Contributions are member-based calculations; creation returns an internal invoice DTO.
        $add('contributions', Member::class, 'contributions', ['id', 'first_name', 'last_name', 'member_number'], ['first_name', 'last_name'], [], ['member_id', 'season_id', 'fingerprint'], [], null, ['preview' => 'view']);
        $add('planning_options', null, 'planning', ['id', 'category', 'code', 'name', 'sort_order', 'active'], ['name', 'code'], ['category', 'code', 'active'], [], [], null);
        foreach (['bus_reservation_statuses' => 'bus', 'material_receipt_statuses' => 'documents', 'announcement_statuses' => 'announcements', 'sponsor_run_statuses' => 'sponsor_runs'] as $key => $module) {
            $fields = ['id', 'code', 'name', 'active'];
            if ($key !== 'bus_reservation_statuses') {
                $fields[] = 'sort_order';
            }
            $add($key, null, $module, $fields, ['name', 'code'], ['code', 'active'], [], [], null);
        }
        foreach ($definitions as $key => &$definition) {
            foreach (['create', 'update'] as $operation) {
                $definition[$operation.'_required'] = $definition[$operation.'_fields'] === [] ? [] : $this->writeRequired($key, $operation);
            }
        }
        unset($definition);

        return $definitions;
    }

    public function unsupported(): array
    {
        return ['expense_payments' => 'No bank payments or paid assertions.',
            'external_actions' => 'Generic resource writes remain local. Reviewed Bexio/SFV/AI work uses separate durable provider-actions; no arbitrary provider URLs or delivery.',
            'announcement_delivery' => 'Draft editing only; recipient snapshots and publishing are not exposed.',
            'planning_private_sources' => 'Absence reasons, participation/guest comments, original source values and encrypted notes are never returned. Manager deadline overrides follow the existing services; profiles never impersonate members.',
            'keybox_operations' => 'No direct keybox issue/simulation/hardware endpoint; reservation changes retain service-local code invalidation.',
            'training_ai' => 'AI generation/review uses separate durable provider-actions with existing quota/quality checks. Generic content editing/archive/copy remains local.',
            'registration_acceptance_delivery' => 'Acceptance only copies existing private storage references and proposes pending parent links; no provider upload, external delivery or automatic account-link approval.',
            'contribution_batches' => 'Only single-member preview and internal draft creation are supported.'];
    }

    public function authorize(ApiContext $context, string $resource, string $operation): void
    {
        $this->fresh(function () use ($context, $resource, $operation) {
            $d = $this->definition($resource);
            $function = match ($operation) {
                'list', 'show', 'read' => 'view',
                'create' => $d['create_fields'] ? 'create' : abort(404, 'Creation unsupported.'),
                'update' => $d['update_fields'] ? 'update' : abort(404, 'Update unsupported.'),
                default => $d['actions'][$operation]['function'] ?? abort(404, 'Action unsupported.'),
            };
            $this->permission($context, $d['permission_module'], $function);
        });
    }

    private function permission(ApiContext $context, string $module, string $function): void
    {
        $actor = $context->actor->fresh();
        abort_unless($actor?->active && $actor->api_only, 403);
        abort_unless(DB::connection('clubunio')->table('clubora.tenant_accounts')
            ->where('tenant_id', $context->tenant->id)->where('account_id', $actor->id)->where('role', 'profile')->exists(), 403);
        $context->authorize($module.':'.($function === 'view' ? 'read' : $function), $module, $function);
    }

    public function query(ApiContext $context, string $resource): Builder|QueryBuilder
    {
        $this->authorize($context, $resource, 'read');

        return $this->baseQuery($context, $resource)->select($this->definition($resource)['read_fields']);
    }

    private function baseQuery(ApiContext $context, string $resource): Builder|QueryBuilder
    {
        $d = $this->definition($resource);
        $q = $d['model'] ? $d['model']::withoutGlobalScopes() : DB::connection('clubunio')->table('clubora.'.(self::LOOKUP_TABLES[$resource] ?? $resource));
        $q->where('tenant_id', $context->tenant->id);
        if ($resource === 'contributions') {
            $q->whereNull('archived_at');
        }
        if (in_array($resource, ['team_absences', 'team_guests'], true)) {
            $q->whereNull('deleted_at');
        }

        return $q;
    }

    public function list(ApiContext $context, string $resource, array $input = []): array
    {
        return $this->fresh(function () use ($context, $resource, $input) {
            $this->authorize($context, $resource, 'list');
            $this->allow($input, ['page', 'per_page', 'search', 'filters']);
            $data = Validator::make($input, ['page' => 'sometimes|integer|min:1|max:10000', 'per_page' => 'sometimes|integer|min:1|max:100',
                'search' => 'sometimes|string|max:200', 'filters' => 'sometimes|array'])->validate();
            $d = $this->definition($resource);
            $q = $this->baseQuery($context, $resource);
            $this->allow($data['filters'] ?? [], $d['filter_fields']);
            foreach ($data['filters'] ?? [] as $field => $value) {
                $rule = in_array($field, ['active', 'shared'], true) ? 'required|boolean' : (str_ends_with($field, '_id') ? 'required|integer|min:1' : 'required|string|max:80');
                Validator::make([$field => $value], [$field => $rule])->validate();
                $q->where($field, $value);
            }
            if (trim($data['search'] ?? '') !== '') {
                if (! $d['search_fields']) {
                    throw ValidationException::withMessages(['search' => 'Search unsupported.']);
                }
                $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($data['search'])).'%';
                $q->where(function ($q) use ($d, $pattern) {
                    foreach ($d['search_fields'] as $field) {
                        $q->orWhere($field, 'ilike', $pattern);
                    }
                });
            }
            $page = (int) ($data['page'] ?? 1);
            $size = (int) ($data['per_page'] ?? 25);
            $rows = $q->orderBy('id')->offset(($page - 1) * $size)->limit($size + 1)->get();

            return ['data' => $rows->take($size)->map(fn ($row) => $this->dto($resource, $row))->values()->all(),
                'meta' => ['page' => $page, 'per_page' => $size, 'has_more' => $rows->count() > $size]];
        });
    }

    public function show(ApiContext $context, string $resource, int $id): array
    {
        return $this->fresh(function () use ($context, $resource, $id) {
            $this->authorize($context, $resource, 'show');

            return $this->dto($resource, $this->record($context, $resource, $id));
        });
    }

    public function create(ApiContext $context, string $resource, array $input): array
    {
        return $this->write($context, $resource, null, $input);
    }

    public function update(ApiContext $context, string $resource, int $id, array $input): array
    {
        return $this->write($context, $resource, $id, $input);
    }

    private function write(ApiContext $c, string $r, ?int $id, array $input): array
    {
        return $this->fresh(function () use ($c, $r, $id, $input) {
            $this->authorize($c, $r, $id === null ? 'create' : 'update');
            $d = $this->definition($r);
            $this->allow($input, $d[$id === null ? 'create_fields' : 'update_fields']);
            $old = $id === null ? null : $this->record($c, $r, $id);
            if ($old && $d['version'] === 'revision') {
                $this->revision($input);
            }
            if ($old && array_key_exists('active', $input) && (bool) $old->active !== (bool) $input['active']) {
                $this->permission($c, $d['permission_module'], $input['active'] ? 'restore' : 'deactivate');
            }
            if ($r === 'team_events') {
                if (($input['status'] ?? null) === 'ABGESAGT' && ($old->status ?? null) !== 'ABGESAGT') {
                    $this->permission($c, 'planning', 'cancel');
                }
                if (($old->status ?? null) === 'ABGESAGT' && ($input['status'] ?? null) === 'AKTIV') {
                    $this->permission($c, 'planning', 'restore');
                }
            }
            if ($r === 'bus_reservations' && $id === null && ($input['status'] ?? null) === 'GENEHMIGT') {
                $this->permission($c, 'bus', 'approve');
            }
            if ($r === 'training_plans' && $old && array_key_exists('shared', $input) && (bool) $old->shared !== (bool) $input['shared']) {
                $this->permission($c, 'training', 'send');
            }
            $a = $c->actor;
            $t = $c->tenant;
            $saved = match ($r) {
                'expenses' => app(ExpenseService::class)->save($a, $t, $id, $input),
                'expense_lines' => $this->expenseLine($c, $old, $input),
                'invoices' => $id === null ? app(InvoiceWorkflowService::class)->create($a, $t, $input)
                    : app(InvoiceWorkflowService::class)->change($a, $t, $id, $this->revision($input), 'header', array_diff_key($input, ['revision' => true])),
                'registrations' => app(RegistrationDataService::class)->save($a, $t, $id, $input),
                'families', 'family_members' => app(FamilyService::class)->save($a, $t, $d['model'], $id, $input),
                'team_events' => $id === null && array_key_exists('repeat_count', $input)
                    ? app(TeamPlanningService::class)->createSeries($a, $t, $input)[0]
                    : app(TeamPlanningService::class)->saveEvent($a, $t, $id, $input),
                'team_absences' => app(TeamPlanningService::class)->saveAbsence($a, $t, $id, $input)['id'],
                'team_participations' => $this->saveParticipation($c, $old->event_id, $old->member_id, $input),
                'team_guests' => $this->saveGuest($c, $old, $input),
                'training_plans' => $this->saveTraining($c, $id, $input),
                'bus_reservations' => $id === null ? app(BusReservationService::class)->create($a, $t, $input) : $this->reschedule($c, $id, $input),
                'announcements' => app(AnnouncementService::class)->save($a, $t, $id, $input),
                'message_templates' => app(MessageTemplateService::class)->save($a, $t, $id, $input),
                'contributions' => $this->contribution($c, $input),
                default => abort(404),
            };
            if ($r === 'contributions') {
                return $this->dto('invoices', $saved);
            }

            return $this->dto($r, $saved instanceof Model ? $saved->refresh() : $this->record($c, $r, $saved));
        });
    }

    public function action(ApiContext $c, string $r, int $id, string $action, array $input = []): array
    {
        return $this->fresh(function () use ($c, $r, $id, $action, $input) {
            abort_unless(isset($this->definition($r)['actions'][$action]), 404, 'Action unsupported.');
            $this->authorize($c, $r, $action);
            $this->allow($input, $this->actionFields($r, $action));
            $record = $this->record($c, $r, $id);
            if ($r === 'team_events') {
                return $this->planningAction($c, $id, $action, $input);
            }
            if ($r === 'training_plans') {
                return $this->trainingAction($c, $id, $action, $input);
            }
            if ($r === 'team_guests') {
                app(PlanningGuestService::class)->save($c->actor, $c->tenant, $record->event_id, $id, ['revision' => $this->revision($input)], true);

                return ['id' => $id, 'revision' => $this->revision($input) + 1, 'deactivated' => true];
            }
            if ($r === 'team_absences') {
                app(TeamPlanningService::class)->saveAbsence($c->actor, $c->tenant, $id, ['revision' => $this->revision($input)], true);

                return ['id' => $id, 'revision' => $this->revision($input) + 1, 'deactivated' => true];
            }
            if ($r === 'contributions') {
                $this->allow($input, ['season_id']);
                $data = Validator::make($input, ['season_id' => 'required|integer|min:1'])->validate();
                $preview = app(ContributionInvoiceService::class)->preview($c->actor, $c->tenant, $id, $data['season_id']);

                return array_intersect_key($preview, array_flip(['fingerprint', 'gross_cents', 'discount_cents', 'net_cents', 'existing_invoice_id', 'season']));
            }
            if ($r === 'invoices') {
                $fields = match ($action) {
                    'add_line' => ['description', 'gross_amount', 'discount', 'notes', 'bexio_catalog_article_id', 'bexio_catalog_tax_id'],
                    'update_line' => ['line_id', 'description', 'gross_amount', 'discount', 'notes', 'bexio_catalog_article_id', 'bexio_catalog_tax_id'],
                    'remove_line', 'restore_line' => ['line_id', 'reason'],
                    'cancel', 'archive', 'restore' => ['reason'], default => [],
                };
                $this->allow($input, ['revision', ...$fields]);
                if (in_array($action, ['update_line', 'remove_line', 'restore_line'], true)) {
                    Validator::make($input, ['line_id' => 'required|integer|min:1'])->validate();
                }
                $operation = in_array($action, ['add_line', 'update_line'], true) ? 'line' : $action;
                $saved = app(InvoiceWorkflowService::class)->change($c->actor, $c->tenant, $id, $this->revision($input), $operation, array_diff_key($input, ['revision' => true]));
            } elseif ($r === 'expenses') {
                $this->allow($input, ['revision', 'reason']);
                $code = match ($action) {
                    'submit' => 'F', 'approve' => 'Z', 'reject' => 'R', 'reopen' => 'O'
                };
                $status = DB::connection('clubunio')->table('clubora.expense_statuses')->where('tenant_id', $c->tenant->id)->where('active', true)->where('code', $code)->value('id');
                abort_unless($status, 422, 'Expense status unavailable.');
                $saved = app(ExpenseService::class)->transition($c->actor, $c->tenant, $id, $input + ['status_id' => $status]);
            } elseif ($r === 'registrations') {
                if ($action === 'accept') {
                    return $this->dto($r, $this->acceptRegistration($c, $id, $input));
                }
                $this->allow($input, ['revision', 'reason', 'status_id', 'team_season_id', 'technical_lead_member_id']);
                // General review cannot bypass the dedicated action's compound token checks.
                if (isset($input['status_id'])) {
                    $status = DB::connection('clubunio')->table('clubora.registration_statuses')->where('tenant_id', $c->tenant->id)->where('id', $input['status_id'])->first();
                    abort_if($status?->workflow_key === 'accepted', 422, 'Use the dedicated accept action.');
                }
                $saved = app(RegistrationReviewService::class)->review($c->actor, $c->tenant, $id, $input);
            } elseif ($r === 'bus_reservations') {
                $this->allow($input, ['revision', 'note']);
                $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'note' => 'nullable|string|max:1000'])->validate();
                $status = match ($action) {
                    'approve' => 'GENEHMIGT', 'reject' => 'ABGELEHNT', 'cancel' => 'STORNIERT'
                };
                app(BusReservationService::class)->decide($c->actor, $c->tenant, $id, (int) $data['revision'], $status, $data['note'] ?? null);
                $saved = $this->record($c, $r, $id);
            } else {
                abort(404);
            }

            return $this->dto($r, $saved);
        });
    }

    private function expenseLine(ApiContext $c, ?object $old, array $input): ExpenseLine
    {
        $expense = $old?->expense_id ?? Validator::make($input, ['expense_id' => 'required|integer|min:1'])->validate()['expense_id'];
        $this->record($c, 'expenses', (int) $expense);

        return app(ExpenseService::class)->saveLine($c->actor, $c->tenant, (int) $expense, $old?->id, array_diff_key($input, ['expense_id' => true]));
    }

    private function acceptRegistration(ApiContext $c, int $id, array $input): Registration
    {
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'reason' => 'required|string|min:10|max:500',
            'member_id' => 'sometimes|nullable|integer|min:1', 'member_version' => 'sometimes|nullable|string|size:64',
            'member_type_id' => 'sometimes|nullable|integer|min:1', 'document_type_id' => 'sometimes|nullable|integer|min:1',
            'team_season_id' => 'sometimes|nullable|integer|min:1'])->validate();

        return DB::connection('clubunio')->transaction(function () use ($c, $id, $data) {
            $db = DB::connection('clubunio');
            // Match the review/upload lock order so conditional effects cannot change after authorization.
            $db->table('clubora.tenants')->where('id', $c->tenant->id)->lockForUpdate()->firstOrFail();
            $this->permission($c, 'registrations', 'approve');
            $registration = Registration::withoutGlobalScopes()->where('tenant_id', $c->tenant->id)->where('active', true)->lockForUpdate()->findOrFail($id);
            $member = $data['member_id'] ?? $registration->member_id;
            $this->permission($c, 'members', $member ? 'update' : 'create');
            if ($member) {
                $existing = Member::withoutGlobalScopes()->where('tenant_id', $c->tenant->id)->whereNull('archived_at')->lockForUpdate()->findOrFail($member);
                $status = $db->table('clubora.member_statuses')->where('tenant_id', $c->tenant->id)->where('id', $existing->status_id)->sharedLock()->first(['ends_membership']);
                if ($existing->exit_date !== null || ($status?->ends_membership ?? false)) {
                    $this->permission($c, 'members', 'restore');
                }
            }
            $team = array_key_exists('team_season_id', $data) ? $data['team_season_id'] : $registration->team_season_id;
            if ($team) {
                $this->permission($c, 'teams', 'create');
            }
            if ($db->table('clubora.registration_documents')->where('tenant_id', $c->tenant->id)->where('registration_id', $id)
                ->where('active', true)->where('kind', '<>', 'portrait')->exists()) {
                $this->permission($c, 'documents', 'create');
            }
            $statuses = $db->table('clubora.registration_statuses')->where('tenant_id', $c->tenant->id)->where('active', true)->where('workflow_key', 'accepted')->get(['id']);
            if ($statuses->count() !== 1) {
                throw ValidationException::withMessages(['status_id' => 'Exactly one active accepted status must be configured.']);
            }

            return app(RegistrationReviewService::class)->review($c->actor, $c->tenant, $id, $data + ['status_id' => $statuses->sole()->id]);
        });
    }

    private function saveParticipation(ApiContext $c, int $event, int $member, array $input): int
    {
        Validator::make($input, ['revision' => 'required|integer|min:0'])->validate();

        return DB::connection('clubunio')->transaction(function () use ($c, $event, $member, $input) {
            app(TeamPlanningService::class)->participate($c->actor, $c->tenant, $event, $member, $input);

            return (int) DB::connection('clubunio')->table('clubora.team_participations')->where('tenant_id', $c->tenant->id)
                ->where('event_id', $event)->where('member_id', $member)->sole()->id;
        });
    }

    private function saveGuest(ApiContext $c, ?object $old, array $input): int
    {
        $event = $old?->event_id ?? Validator::make($input, ['event_id' => 'required|integer|min:1'])->validate()['event_id'];

        return DB::connection('clubunio')->transaction(function () use ($c, $old, $input, $event) {
            // The existing service returns void; retain the shared tenant lock while resolving its new ID.
            app(TeamPlanningService::class)->lock($c->tenant);
            app(PlanningGuestService::class)->save($c->actor, $c->tenant, $event, $old?->id, array_diff_key($input, ['event_id' => true]));

            return $old?->id ?? (int) DB::connection('clubunio')->table('clubora.team_guests')->where('tenant_id', $c->tenant->id)
                ->where('event_id', $event)->orderByDesc('id')->value('id');
        });
    }

    private function planningAction(ApiContext $c, int $id, string $action, array $input): array
    {
        if ($action === 'invitations') {
            app(TeamPlanningService::class)->event($c->actor, $c->tenant, $id);
            $members = DB::connection('clubunio')->table('clubora.team_invitations')->where('tenant_id', $c->tenant->id)->where('event_id', $id)->orderBy('member_id')->pluck('member_id')->all();

            return ['event_id' => $id, 'member_ids' => $members];
        }

        return DB::connection('clubunio')->transaction(function () use ($c, $id, $action, $input) {
            $planning = app(TeamPlanningService::class);
            $planning->lock($c->tenant);
            $event = $planning->event($c->actor, $c->tenant, $id, true,
                match ($action) {
                    'invite' => 'send', 'uninvite' => 'cancel', default => 'update'
                });
            if ($action === 'participate') {
                $data = Validator::make($input, ['member_id' => 'required|integer|min:1', 'revision' => 'required|integer|min:0'])->validate();
                $part = $this->saveParticipation($c, $id, $data['member_id'], array_diff_key($input, ['member_id' => true]));

                return $this->dto('team_participations', $this->record($c, 'team_participations', $part));
            }
            $planning->conflict($event, ['revision' => $this->revision($input)]);
            if (in_array($action, ['invite', 'uninvite'], true)) {
                $data = Validator::make($input, ['member_id' => 'required|integer|min:1'])->validate();
                app(PlanningGuestService::class)->invite($c->actor, $c->tenant, $id, $data['member_id'], $action === 'uninvite');

                return ['event_id' => $id, 'member_id' => (int) $data['member_id'], 'invited' => $action === 'invite', 'revision' => $event->revision];
            }
            if ($input['cancel'] ?? false) {
                $this->permission($c, 'planning', 'cancel');
            }
            $changes = array_diff_key($input, ['revision' => true, 'fingerprint' => true]);
            $series = app(PlanningSeriesService::class);
            if ($action === 'series_apply') {
                $data = Validator::make($input, ['fingerprint' => 'required|string|size:64'])->validate();
                $count = $series->apply($c->actor, $c->tenant, $id, $changes, $data['fingerprint']);

                return ['id' => $id, 'applied' => $count, 'revision' => $this->record($c, 'team_events', $id)->revision];
            }
            $preview = $series->preview($c->actor, $c->tenant, $id, $changes);
            $safe = [];
            foreach ($preview['changes'] as $change) {
                $before = $this->dto('team_events', $change['before']);
                $after = array_intersect_key($change['input'], array_flip($this->definition('team_events')['read_fields']));
                foreach (['starts_at', 'ends_at', 'deadline_at'] as $field) {
                    $after[$field] = CarbonImmutable::parse($after[$field], 'Europe/Zurich')->toIso8601String();
                }
                $safe[] = ['id' => $change['id'], 'before' => $before, 'after' => $after];
            }

            return ['id' => $id, 'revision' => $event->revision, 'fingerprint' => $preview['fingerprint'], 'skipped' => $preview['skipped'], 'changes' => $safe];
        });
    }

    private function saveTraining(ApiContext $c, int $id, array $input): int
    {
        $data = Validator::make($input, ['plan' => 'required|array', 'shared' => 'required|boolean'])->validate();
        $this->strictStructure($data['plan'], app(TrainingPlanContent::class)->schema());
        app(TrainingPlanService::class)->save($c->actor, $c->tenant, $id, $this->revision($input), $data['plan'], (bool) $data['shared']);

        return $id;
    }

    private function trainingAction(ApiContext $c, int $id, string $action, array $input): array
    {
        $service = app(TrainingPlanService::class);
        if ($action === 'content') {
            $row = $service->get($c->actor, $c->tenant, $id);
            $inputFields = ['team_season_id', 'durationMinutes', 'playerCount', 'goal', 'fieldSize', 'material', 'teamName'];

            return $this->dto('training_plans', $row) + ['input' => array_filter(array_intersect_key($row->input, array_flip($inputFields)), fn ($value) => is_scalar($value) || $value === null),
                'plan' => $this->safeStructure($row->plan, app(TrainingPlanContent::class)->schema())];
        }
        if ($action === 'copy') {
            $data = Validator::make($input, ['request_key' => 'required|uuid'])->validate();
            $id = $service->copy($c->actor, $c->tenant, $id, $this->revision($input), $data['request_key']);
        } else {
            $service->archive($c->actor, $c->tenant, $id, $this->revision($input), $action === 'archive');
        }

        return $this->dto('training_plans', $this->record($c, 'training_plans', $id));
    }

    private function strictStructure(mixed $value, array $schema): void
    {
        if (($schema['type'] ?? null) === 'object') {
            if (! is_array($value)) {
                throw ValidationException::withMessages(['plan' => 'Expected a structured object.']);
            }
            $this->allow($value, array_keys($schema['properties']));
            if (array_diff($schema['required'] ?? [], array_keys($value))) {
                throw ValidationException::withMessages(['plan' => 'Required structured fields are missing.']);
            }
            foreach ($value as $key => $child) {
                $this->strictStructure($child, $schema['properties'][$key]);
            }
        } elseif (($schema['type'] ?? null) === 'array') {
            if (! is_array($value) || ! array_is_list($value)) {
                throw ValidationException::withMessages(['plan' => 'Expected a JSON list.']);
            }
            foreach ($value as $child) {
                $this->strictStructure($child, $schema['items']);
            }
        }
    }

    private function safeStructure(mixed $value, array $schema): mixed
    {
        if (($schema['type'] ?? null) === 'object') {
            $safe = [];
            foreach ($schema['properties'] as $key => $child) {
                if (is_array($value) && array_key_exists($key, $value)) {
                    $safe[$key] = $this->safeStructure($value[$key], $child);
                }
            }

            return $safe;
        }
        if (($schema['type'] ?? null) === 'array') {
            return is_array($value) && array_is_list($value) ? array_map(fn ($child) => $this->safeStructure($child, $schema['items']), $value) : [];
        }

        return is_scalar($value) || $value === null ? $value : null;
    }

    private function reschedule(ApiContext $c, int $id, array $input): int
    {
        app(BusReservationService::class)->reschedule($c->actor, $c->tenant, $id, $input);

        return $id;
    }

    private function contribution(ApiContext $c, array $input): Invoice
    {
        $this->permission($c, 'contributions', 'view');
        $this->permission($c, 'invoices', 'create');
        $data = Validator::make($input, ['member_id' => 'required|integer|min:1', 'season_id' => 'required|integer|min:1', 'fingerprint' => 'required|string|size:64'])->validate();

        return app(ContributionInvoiceService::class)->create($c->actor, $c->tenant, $data['member_id'], $data['season_id'], $data['fingerprint']);
    }

    private function record(ApiContext $c, string $r, int $id): object
    {
        return $this->baseQuery($c, $r)->where('id', $id)->firstOrFail();
    }

    private function dto(string $resource, object $record): array
    {
        $d = $this->definition($resource);
        $result = [];
        foreach ($d['read_fields'] as $field) {
            $value = $record instanceof Model ? $record->getAttribute($field) : ($record->$field ?? null);
            if ($value !== null && in_array($resource, ['team_events', 'bus_reservations', 'training_plans'], true)
                && in_array($field, ['starts_at', 'ends_at', 'deadline_at', 'archived_at'], true)) {
                $value = CarbonImmutable::parse($value, 'Europe/Zurich')->toIso8601String();
            }
            $result[$field] = $value instanceof \DateTimeInterface ? $value->format($field === 'birth_date' ? 'Y-m-d' : DATE_ATOM) : $value;
        }
        if ($d['version'] === '_version') {
            $result['_version'] = FamilyService::version($record);
        }

        return $result;
    }

    private function revision(array $input): int
    {
        return (int) Validator::make($input, ['revision' => 'required|integer|min:1'])->validate()['revision'];
    }

    private function allow(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed)) {
            throw ValidationException::withMessages(['input' => 'Unknown or protected fields.']);
        }
    }

    private function definition(string $resource): array
    {
        return $this->definitions()[$resource] ?? abort(404, 'Unsupported workflow resource.');
    }

    private function actionFields(string $resource, string $action): array
    {
        return match ($resource) {
            'expenses' => ['revision', 'reason'],
            'registrations' => $action === 'accept'
                ? ['revision', 'reason', 'member_id', 'member_version', 'member_type_id', 'document_type_id', 'team_season_id']
                : ['revision', 'reason', 'status_id', 'team_season_id', 'technical_lead_member_id'],
            'bus_reservations' => ['revision', 'note'],
            'contributions' => ['season_id'],
            'team_absences' => ['revision'],
            'team_guests' => ['revision'],
            'training_plans' => match ($action) {
                'content' => [], 'copy' => ['revision', 'request_key'], default => ['revision']
            },
            'team_events' => match ($action) {
                'invitations' => [],
                'participate' => ['member_id', 'revision', 'status', 'attendance', 'comment'],
                'invite', 'uninvite' => ['member_id', 'revision'],
                default => ['revision', 'from', 'shift_minutes', 'location', 'change_location', 'cancel', 'change_title', 'title', 'change_capacity', 'capacity', ...($action === 'series_apply' ? ['fingerprint'] : [])],
            },
            'invoices' => ['revision', ...match ($action) {
                'add_line' => ['description', 'gross_amount', 'discount', 'notes', 'bexio_catalog_article_id', 'bexio_catalog_tax_id'],
                'update_line' => ['line_id', 'description', 'gross_amount', 'discount', 'notes', 'bexio_catalog_article_id', 'bexio_catalog_tax_id'],
                'remove_line', 'restore_line' => ['line_id', 'reason'],
                'cancel', 'archive', 'restore' => ['reason'], default => [],
            }],
            default => [],
        };
    }

    private function actionRequired(string $resource, string $action): array
    {
        return match ($resource) {
            'team_events' => match ($action) {
                'invitations' => [],
                'participate' => ['member_id', 'revision', 'status', 'attendance'],
                'invite', 'uninvite' => ['member_id', 'revision'],
                default => ['revision', 'from', 'shift_minutes', ...($action === 'series_apply' ? ['fingerprint'] : [])],
            },
            'training_plans' => match ($action) {
                'content' => [], 'copy' => ['revision', 'request_key'], default => ['revision']
            },
            'team_absences', 'team_guests' => ['revision'],
            'contributions' => ['season_id'],
            'expenses', 'registrations' => ['revision', 'reason'],
            'bus_reservations' => ['revision'],
            'invoices' => match ($action) {
                'add_line' => ['revision', 'description', 'gross_amount', 'discount'],
                'update_line' => ['revision', 'line_id', 'description', 'gross_amount', 'discount'],
                'remove_line', 'restore_line' => ['revision', 'line_id', 'reason'],
                'archive', 'restore', 'cancel' => ['revision', 'reason'], default => ['revision'],
            },
            default => [],
        };
    }

    private function writeRequired(string $resource, string $operation): array
    {
        $update = $operation === 'update';

        return match ($resource) {
            'expenses' => $update ? ['revision'] : ['member_id', 'team_season_id', 'bank_account_id', 'round_id'],
            'expense_lines' => $update ? ['revision', 'expense_revision'] : ['expense_id', 'expense_revision', 'expense_type_id'],
            'invoices' => $update ? ['revision'] : ['member_id', 'invoice_category_id', 'tax_mode'],
            'registrations' => $update ? ['revision'] : [...RegistrationDataService::CORE, 'street', 'postal_code', 'country_id', 'nationality_id', 'gender_id', 'billing_recipient_id'],
            'families' => ['name', 'active', ...($update ? ['_version'] : [])],
            'family_members' => ['family_id', 'member_id', 'discount', 'active', ...($update ? ['_version'] : [])],
            'team_events' => ['team_season_id', 'title', 'kind', 'starts_at', 'ends_at', 'deadline_at', 'mode', 'status', ...($update ? ['revision'] : [])],
            'team_absences' => ['starts_on', 'ends_on', 'kind', $update ? 'revision' : 'member_id'],
            'team_participations' => ['revision', 'status', 'attendance'],
            'team_guests' => ['first_name', 'last_name', 'status', 'attendance', $update ? 'revision' : 'event_id'],
            'training_plans' => ['revision', 'plan', 'shared'],
            'bus_reservations' => $update ? ['revision', 'bus_id', 'starts_at', 'ends_at', 'reason'] : ['bus_id', 'team_season_id', 'member_id', 'starts_at', 'ends_at', 'status'],
            'announcements' => ['title', 'body', 'target_type', 'target_ids', ...($update ? ['revision'] : [])],
            'message_templates' => $update ? ['revision', 'title', 'body', 'active'] : ['event_key', 'channel', 'locale', 'title', 'body', 'active'],
            'contributions' => ['member_id', 'season_id', 'fingerprint'],
            default => [],
        };
    }

    private function actionSchema(string $resource, string $action): array
    {
        $properties = [];
        foreach ($this->actionFields($resource, $action) as $field) {
            $properties[$field] = match (true) {
                $field === 'revision' => ['type' => 'integer', 'minimum' => $resource === 'team_events' && $action === 'participate' ? 0 : 1],
                $field === 'shift_minutes' => ['type' => 'integer', 'minimum' => -1440, 'maximum' => 1440],
                $field === 'capacity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'nullable' => true],
                str_ends_with($field, '_id'), in_array($field, ['revision', 'capacity', 'shift_minutes'], true) => ['type' => 'integer'],
                in_array($field, ['cancel', 'change_location', 'change_title', 'change_capacity'], true) => ['type' => 'boolean'],
                $field === 'from' => ['type' => 'string', 'format' => 'date'],
                $field === 'request_key' => ['type' => 'string', 'format' => 'uuid'],
                in_array($field, ['fingerprint', 'member_version'], true) => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                default => ['type' => 'string'],
            };
        }

        return ['type' => 'object', 'additionalProperties' => false, 'required' => $this->actionRequired($resource, $action), 'properties' => $properties ?: new \stdClass];
    }

    private function fresh(Closure $operation): mixed
    {
        $request = request();
        $previous = $request->attributes->get(BackofficeReadContext::class);
        $request->attributes->remove(BackofficeReadContext::class);
        try {
            return $operation();
        } finally {
            $request->attributes->remove(BackofficeReadContext::class);
            if ($previous !== null) {
                $request->attributes->set(BackofficeReadContext::class, $previous);
            }
        }
    }
}

<?php

namespace App\ClubUnio\Api\Modules;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Models;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\ContributionTrainingService;
use App\ClubUnio\Services\CreditNoteService;
use App\ClubUnio\Services\DossierMaintenanceService;
use App\ClubUnio\Services\FacilityService;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\MemberChangeService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit local operations only; provider reconciliation and cash assertions are absent. */
final class AuxiliaryApiModules
{
    private function configurations(): array
    {
        $definitions = [];
        $add = function ($key, $model, $module, $read, $search = [], $filters = [], $create = [], $update = [], $actions = [], $version = 'revision') use (&$definitions) {
            $definitions[$key] = ['key' => $key, 'model' => $model, 'permission_module' => $module,
                'service' => 'auxiliary', 'read_fields' => $read, 'search_fields' => $search, 'filter_fields' => $filters,
                'sort_fields' => array_values(array_unique(['id', ...$search])), 'create_fields' => $create,
                'update_fields' => $update, 'actions' => $actions, 'version' => $version];
        };
        $add('credit_notes', Models\CreditNote::class, 'credits',
            ['id', 'invoice_id', 'member_id', 'status_id', 'amount_cents', 'included_tax', 'reason', 'revision'], ['reason'], ['invoice_id', 'member_id', 'status_id'],
            ['invoice_id', 'request_id', 'revision', 'reason', 'lines'], [],
            ['release' => ['function' => 'approve', 'fields' => ['revision']], 'cancel' => ['function' => 'cancel', 'fields' => ['revision', 'reason']]]);
        $add('credit_note_lines', Models\CreditNoteLine::class, 'credits', ['id', 'credit_note_id', 'invoice_line_id', 'description', 'amount_cents', 'included_tax'],
            ['description'], ['credit_note_id', 'invoice_line_id'], [], [], [], null);
        $add('customer_credits', Models\CustomerCredit::class, 'credits', ['id', 'member_id', 'credit_note_id', 'amount_cents', 'revision'], [], ['member_id', 'credit_note_id']);
        $add('refunds', Models\Refund::class, 'credits', ['id', 'invoice_id', 'credit_note_id', 'status_id', 'amount_cents', 'reason', 'revision'], ['reason'], ['invoice_id', 'credit_note_id', 'status_id'], [], [],
            ['cancel' => ['function' => 'cancel', 'fields' => ['revision', 'reason']]]);
        $add('member_changes', Models\MemberChangeRequest::class, 'member_changes', ['id', 'member_id', 'status_id', 'state', 'revision'], [], ['member_id', 'status_id', 'state'], [], [],
            ['approve' => ['function' => 'approve', 'fields' => ['revision', 'note']], 'reject' => ['function' => 'approve', 'fields' => ['revision', 'note']]]);
        $event = ['name', 'location', 'season_id', 'team_id', 'active'];
        $add('events', Models\ClubEvent::class, 'events', ['id', ...$event, 'revision'], ['name', 'location'], ['season_id', 'team_id', 'active'], $event, [...$event, 'revision']);
        $guest = ['member_id', 'club_event_id', 'active'];
        $add('event_guests', Models\EventGuest::class, 'events', ['id', ...$guest, 'revision'], [], ['member_id', 'club_event_id', 'active'], $guest, [...$guest, 'revision']);
        $notice = ['shows_on', 'title', 'body', 'active'];
        $add('signage_notices', Models\SignageNotice::class, 'signage', ['id', ...$notice, 'revision'], ['title'], ['shows_on', 'active'], $notice, [...$notice, 'revision']);
        $price = ['name', 'amount', 'active'];
        $add('contribution_prices', Models\ContributionPrice::class, 'contributions', ['id', ...$price, 'revision'], ['name'], ['active'], $price, [...$price, 'revision']);
        $rule = ['season_id', 'price_id', 'gender_id', 'year_from', 'year_to', 'active'];
        $add('contribution_rules', Models\ContributionRule::class, 'contributions', ['id', ...$rule, 'revision'], [], ['season_id', 'price_id', 'gender_id', 'active'], $rule, [...$rule, 'revision']);
        $add('documents', Models\MemberDocument::class, 'documents',
            ['id', 'member_id', 'document_type_id', 'original_filename', 'content_type', 'file_size', 'portal_visible', 'active', 'revision'],
            ['original_filename'], ['member_id', 'document_type_id', 'active']);
        $add('registration_documents', RegistrationDocumentMetadata::class, 'registrations',
            ['id', 'registration_id', 'kind', 'original_filename', 'mime_type', 'file_size', 'active'],
            ['original_filename'], ['registration_id', 'kind', 'active'], [], [], [], null);
        $add('material_receipts', Models\MaterialReceipt::class, 'documents', ['id', 'member_id', 'title', 'handover_on', 'state', 'revision'],
            ['title'], ['member_id', 'state']);
        $add('legacy_material_receipts', Models\LegacyMaterialReceipt::class, 'documents', ['id', 'member_id', 'title', 'source_state', 'signed_at'],
            ['title'], ['member_id', 'source_state'], [], [], [], null);

        return $definitions;
    }

    public function definitions(): array
    {
        return array_map(fn ($d) => (new ApiModule($d))->definition(), $this->configurations());
    }

    public function unsupported(): array
    {
        return ['customer_credit_writes' => 'Existing writes require admin/provider verification; no impersonation or bypass.',
            'refund_creation_and_confirmation' => 'No provider entitlement calls, bank payments or paid attestations.',
            'credit_provider_confirmation' => 'Only local credit draft creation/release/cancellation; no remote confirmation.',
            'member_change_submission' => 'Technical profiles cannot represent members or parents; review existing requests only.',
            'document_writes' => 'No generic metadata edits, signature or issue operations. Upload staging, processing and downloads use separate file controllers.',
            'document_series' => 'No series/template generation adapter.',
            'external_signage_delivery' => 'Local notice publication only; no external display/provider dispatch.'];
    }

    private function definition(string $resource): array
    {
        return $this->configurations()[$resource] ?? abort(404, 'Unsupported auxiliary resource.');
    }

    private function reader(string $resource): ApiModule
    {
        return new ApiModule($this->definition($resource));
    }

    public function authorize(ApiContext $context, string $resource, string $operation): void
    {
        $this->fresh(function () use ($context, $resource, $operation) {
            $d = $this->definition($resource);
            $function = match ($operation) {
                'read', 'list', 'show' => 'view',
                'create', 'update' => $d[$operation.'_fields'] !== [] ? $operation : abort(405),
                default => $d['actions'][$operation]['function'] ?? abort(404),
            };
            abort_unless(app(MemberAccessService::class)->membership($context->actor, $context->tenant)?->role === 'profile', 403);
            $context->authorize($d['permission_module'].':'.($function === 'view' ? 'read' : $function), $d['permission_module'], $function);
            if ($resource === 'signage_notices' && in_array($operation, ['create', 'update'], true)) {
                $context->authorize('signage:send', 'signage', 'send');
            }
            if ($resource === 'member_changes' && $operation === 'approve') {
                $context->authorize('members:update', 'members', 'update');
            }
        });
    }

    public function query(ApiContext $context, string $resource): Builder
    {
        return $this->reader($resource)->query($context);
    }

    public function list(ApiContext $context, string $resource, array $input = []): array
    {
        return $this->reader($resource)->list($context, $input);
    }

    public function show(ApiContext $context, string $resource, int $id): array
    {
        return $this->reader($resource)->show($context, $id);
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
        return $this->fresh(fn () => DB::connection('clubunio')->transaction(function () use ($c, $r, $id, $input) {
            $this->authorize($c, $r, $id === null ? 'create' : 'update');
            $d = $this->definition($r);
            $this->allow($input, $d[$id === null ? 'create_fields' : 'update_fields']);
            Models\Tenant::whereKey($c->tenant->id)->lockForUpdate()->firstOrFail();
            $old = $id === null ? null : $d['model']::withoutGlobalScopes()->where('tenant_id', $c->tenant->id)->lockForUpdate()->findOrFail($id);
            if ($old && array_key_exists('active', $input) && (bool) $old->active !== (bool) $input['active']) {
                $function = $input['active'] ? 'restore' : 'deactivate';
                $c->authorize($d['permission_module'].':'.$function, $d['permission_module'], $function);
            }
            $saved = match ($r) {
                'events' => app(DossierMaintenanceService::class)->save($c->actor, $c->tenant, $d['model'], null, $id, $input),
                'event_guests' => $this->guest($c, $id, $input),
                'signage_notices' => app(FacilityService::class)->save($c->actor, $c->tenant, $d['model'], $id, $input),
                'contribution_prices', 'contribution_rules' => app(ContributionTrainingService::class)->save($c->actor, $c->tenant, $d['model'], $id, $input),
                'credit_notes' => $this->createCredit($c, $input),
                default => abort(405),
            };

            return $this->reader($r)->serialize($saved->refresh());
        }));
    }

    private function guest(ApiContext $c, ?int $id, array $input): Models\EventGuest
    {
        $data = Validator::make($input, ['member_id' => 'required|integer|min:1'])->validate();
        $member = (int) $data['member_id'];
        unset($input['member_id']);

        return app(DossierMaintenanceService::class)->save($c->actor, $c->tenant, Models\EventGuest::class, $member, $id, $input);
    }

    private function createCredit(ApiContext $c, array $input): Models\CreditNote
    {
        $data = Validator::make($input, ['invoice_id' => 'required|integer|min:1', 'lines' => 'required|array|max:100',
            'lines.*' => 'required|array:invoice_line_id,amount'])->validate();
        unset($input['invoice_id']);

        return app(CreditNoteService::class)->create($c->actor, $c->tenant, (int) $data['invoice_id'], $input);
    }

    public function action(ApiContext $c, string $r, int $id, string $action, array $input = []): array
    {
        abort_unless(isset($this->definition($r)['actions'][$action]), 404, 'Unsupported auxiliary action.');

        return $this->fresh(fn () => DB::connection('clubunio')->transaction(function () use ($c, $r, $id, $action, $input) {
            $this->authorize($c, $r, $action);
            $d = $this->definition($r);
            $this->allow($input, $d['actions'][$action]['fields']);
            $data = Validator::make($input, ['revision' => 'required|integer|min:1'])->validate();
            if ($r === 'member_changes') {
                Validator::make($input, ['note' => 'nullable|string|max:2000'])->validate();
            }
            $saved = match ($r) {
                'credit_notes' => app(CreditNoteService::class)->change($c->actor, $c->tenant, $id, (int) $data['revision'], $action, $input),
                'refunds' => app(CreditNoteService::class)->changeRefund($c->actor, $c->tenant, $id, (int) $data['revision'], $action, $input),
                'member_changes' => app(MemberChangeService::class)->review($c->actor, $c->tenant, $id, (int) $data['revision'], $action === 'approve', $input['note'] ?? null),
                default => abort(404),
            };

            return $this->reader($r)->serialize($saved->refresh());
        }));
    }

    private function allow(array $input, array $fields): void
    {
        if (array_diff(array_keys($input), $fields)) {
            throw ValidationException::withMessages(['input' => 'Unknown or protected fields.']);
        }
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

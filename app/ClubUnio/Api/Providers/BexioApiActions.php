<?php

namespace App\ClubUnio\Api\Providers;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Api\ApiTokenService;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\SyncWorkService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class BexioApiActions
{
    public function definitions(): array
    {
        $definitions = [];
        foreach ([
            'invoice-transfer' => ['Rechnung an Bexio übertragen', 'invoices', 'send'],
            'invoice-issue' => ['Bexio-Rechnung freigeben', 'invoices', 'send'],
            'invoice-cancel' => ['Bexio-Rechnung stornieren', 'invoices', 'cancel'],
            'invoice-status' => ['Bexio-Rechnungsstatus lesen', 'invoices', 'sync'],
            'invoice-sync' => ['Bexio-Rechnungsstatus übernehmen', 'invoices', 'sync'],
            'expense-status' => ['Bexio-Spesenstatus lesen', 'expenses', 'sync'],
            'expense-sync' => ['Bexio-Spesenstatus übernehmen', 'expenses', 'sync'],
            'catalog-sync' => ['Bexio-Katalog abgleichen', 'bexio', 'sync'],
            'invoice-reconcile' => ['Bexio-Rechnungsaktion abgleichen', 'invoices', 'sync'],
        ] as $action => [$title, $module, $function]) {
            $scopes = ['bexio:read' => ['bexio', 'view']];
            if ($module !== 'bexio') {
                $scopes[$module.':read'] = [$module, 'view'];
            }
            $scopes[$module.':'.$function] = [$module, $function];
            // Issue/cancel reconcile their outcome and apply a fresh status check.
            if ($action !== 'invoice-transfer') {
                $scopes['bexio:sync'] = ['bexio', 'sync'];
                if ($module !== 'bexio') {
                    $scopes[$module.':sync'] = [$module, 'sync'];
                }
            }
            $definitions['bexio.'.$action] = ['title' => $title, 'approval' => 'admin',
                'scopes' => $scopes,
                'fields' => $action === 'invoice-transfer' ? ['revision' => 'required|integer|min:1|max:2147483647'] : []];
        }

        return $definitions;
    }

    public function validate(ApiContext $c, string $action, int $objectId, array $input): array
    {
        return $this->fresh(function () use ($c, $action, $objectId, $input) {
            $definition = $this->definitions()[$action] ?? null;
            abort_unless($definition, 404);
            foreach ($definition['scopes'] as $scope => [$module, $function]) {
                $c->authorize($scope, $module, $function);
            }
            if (array_diff(array_keys($input), array_keys($definition['fields']))) {
                throw ValidationException::withMessages(['input' => 'Unknown or protected fields.']);
            }
            $data = Validator::make($input, $definition['fields'])->validate();
            abort_unless($objectId > 0, 404);
            if ($action === 'bexio.catalog-sync') {
                abort_unless($objectId === (int) $c->tenant->id, 404);

                return $data;
            }

            $kind = str_starts_with($action, 'bexio.expense-') ? 'expense' : 'invoice';
            $class = $kind === 'invoice' ? Invoice::class : Expense::class;
            $document = $class::where('tenant_id', $c->tenant->id)->where('active', true)->findOrFail($objectId);
            // Local-only preflight: technical actors cannot call admin-only provider services.
            if ($action === 'bexio.invoice-transfer') {
                abort_unless($document->legacy_id === null, 409);
                $existing = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')
                    ->where('tenant_id', $c->tenant->id)->where('kind', 'invoice')->where('invoice_id', $objectId)->exists();
                if (! $existing) {
                    $workflow = $document->workflowStatus;
                    abort_unless((int) $document->revision === (int) $data['revision'] && ! $document->bexio_invoice_id
                        && $workflow?->active && (int) $workflow->tenant_id === (int) $c->tenant->id
                        && $workflow->code === 'released', 409);
                }
                $data['revision'] = (int) $data['revision'];
            }
            if (in_array($action, ['bexio.invoice-issue', 'bexio.invoice-cancel', 'bexio.invoice-reconcile'], true)) {
                $dispatch = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')
                    ->where('tenant_id', $c->tenant->id)->where('kind', 'invoice')->where('invoice_id', $objectId)
                    ->where('state', 'accepted')->first(['id', 'remote_id']);
                abort_unless($dispatch && ! $document->legacy_id
                    && (string) $document->bexio_invoice_id === (string) $dispatch->remote_id, 409);
                if ($action === 'bexio.invoice-reconcile') {
                    abort_unless(DB::connection('clubunio')->table('clubora.bexio_invoice_actions')
                        ->where('dispatch_id', $dispatch->id)->exists(), 409);
                }
            }

            return $data;
        });
    }

    public function execute(ApiContext $c, string $action, int $objectId, array $input, ?Account $executor = null): array
    {
        // Provider effects must survive failure of the API queue's final SQL update.
        abort_if(DB::connection('clubunio')->transactionLevel() !== 0, 409, 'Provider execution requires a committed work request.');
        if (array_key_exists('_request_key', $input)) {
            Validator::make($input, ['_request_key' => 'required|uuid'])->validate();
            unset($input['_request_key']);
        }

        return $this->fresh(function () use ($c, $action, $objectId, $input, $executor) {
            $data = $this->validate($c, $action, $objectId, $input);
            $admin = $executor?->fresh();
            abort_unless($admin && app(ApiTokenService::class)->isAdmin($admin, $c->tenant)
                && BexioConnectionService::canManage($admin, $c->tenant), 403);
            [$operation, $payload] = match ($action) {
                'bexio.invoice-transfer' => ['invoice_transfer', $data],
                'bexio.invoice-issue' => ['invoice_action', ['operation' => 'issue']],
                'bexio.invoice-cancel' => ['invoice_action', ['operation' => 'cancel']],
                'bexio.invoice-status' => ['invoice_status', []],
                'bexio.invoice-sync' => ['invoice_sync', []],
                'bexio.expense-status' => ['expense_status', []],
                'bexio.expense-sync' => ['expense_sync', []],
                'bexio.catalog-sync' => ['catalog', []],
                'bexio.invoice-reconcile' => ['invoice_result', []],
            };
            $sync = app(SyncWorkService::class);
            $workId = $sync->remember($admin, $c->tenant, $operation, $objectId, $payload);
            $work = $sync->table()->where('tenant_id', $c->tenant->id)->where('id', $workId)->first(['state']);
            $allowedStates = $operation === 'invoice_result' ? ['pending', 'succeeded', 'attention'] : ['pending', 'succeeded'];
            abort_unless($work && in_array($work->state, $allowedStates, true), 409,
                'Existing work requires explicit reconciliation, not a generic retry.');
            // No catches/retries here: the existing services retain uncertain write outcomes.
            $result = $sync->run($admin, $c->tenant, $operation, $objectId, $payload);
            $base = ['id' => $objectId, 'sync_work_id' => $workId];
            if ($operation === 'catalog') {
                return $base + ['state' => 'synchronized', 'confirmed' => true];
            }
            if (in_array($operation, ['invoice_status', 'invoice_sync', 'expense_status', 'expense_sync'], true)) {
                $check = DB::connection('clubunio')->table('clubora.bexio_checks')->where('tenant_id', $c->tenant->id)
                    ->where('object_type', str_starts_with($operation, 'invoice') ? 'invoice' : 'expense')
                    ->where('object_id', $objectId)->where('id', $result)->first(['id', 'state', 'remote_status']);
                abort_unless($check, 409);

                return $base + ['check_id' => (int) $check->id, 'state' => $check->state,
                    'status' => $check->remote_status, 'confirmed' => in_array($check->state, ['ready', 'applied', 'unchanged'], true)];
            }
            $dispatch = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')
                ->where('tenant_id', $c->tenant->id)->where('kind', 'invoice')->where('invoice_id', $objectId)
                ->first(['id', 'state']);
            abort_unless($dispatch, 409);
            if ($operation === 'invoice_transfer') {
                return $base + ['dispatch_id' => (int) $dispatch->id, 'state' => $dispatch->state,
                    'confirmed' => $dispatch->state === 'accepted'];
            }
            $actions = DB::connection('clubunio')->table('clubora.bexio_invoice_actions')->where('dispatch_id', $dispatch->id)
                ->when($operation === 'invoice_action', fn ($q) => $q->where('operation', $payload['operation']))->pluck('state');
            $confirmed = $dispatch->state === 'accepted' && $actions->isNotEmpty()
                && $actions->every(fn ($state) => $state === 'accepted');

            return $base + ['dispatch_id' => (int) $dispatch->id, 'state' => $confirmed ? 'accepted' : 'uncertain', 'confirmed' => $confirmed];
        });
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

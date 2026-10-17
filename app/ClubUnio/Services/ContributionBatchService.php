<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\Tenant;
use Illuminate\Validation\ValidationException;

class ContributionBatchService
{
    public function preview(Account $actor, Tenant $tenant, int $seasonId, int $after = 0): array
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'contributions', 'view');
        Season::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($seasonId);
        $members = app(MemberAccessService::class)->activeMembers($tenant)->where('id', '>', $after)
            ->whereHas('teamPlayers', fn ($q) => $q->where('active', true)->whereHas('teamSeason', fn ($q) => $q->where('active', true)->where('season_id', $seasonId)))
            ->orderBy('id')->limit(26)->get(['clubora.members.id']);
        $rows = app(ContributionInvoiceService::class)->previewMany($actor, $tenant, $seasonId, $members->take(25)->pluck('id')->all());

        return ['season_id' => $seasonId, 'rows' => $rows, 'next' => $members->count() > 25 ? $members[24]->id : null];
    }

    public function create(Account $actor, Tenant $tenant, array $batch): array
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'contributions', 'create');
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'invoices', 'create');
        abort_if(count($batch['rows'] ?? []) > 25, 422);
        $results = [];
        foreach ($batch['rows'] as $row) {
            if (isset($row['error']) || ! empty($row['existing_invoice_id'])) {
                $results[] = ['member' => $row['member'], 'result' => $row['error'] ?? 'Saisonbeitrag bereits vorhanden'];

                continue;
            }
            try {
                $invoice = app(ContributionInvoiceService::class)->create($actor, $tenant, $row['member_id'], $batch['season_id'], $row['fingerprint']);
                $results[] = ['member' => $row['member'], 'result' => 'Rechnungsentwurf #'.$invoice->id, 'invoice_id' => $invoice->id];
            } catch (ValidationException $error) {
                $results[] = ['member' => $row['member'], 'result' => $error->validator->errors()->first()];
            }
        }

        return $results;
    }
}

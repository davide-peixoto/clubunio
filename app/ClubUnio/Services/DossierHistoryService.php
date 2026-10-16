<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class DossierHistoryService
{
    public function entries(Account $actor, Tenant $tenant, Model $record): array
    {
        abort_unless((int) $record->tenant_id === (int) $tenant->id, 403);
        $module = ModulePermissionService::modelModule($record);
        if ($module === null) {
            abort_unless($actor->fresh()?->hasUnrestrictedBackofficeAccess($tenant), 403);
        } else {
            app(ModulePermissionService::class)->authorize($actor, $tenant, $module, 'view');
        }
        // Only audited business fields, never raw storage paths, credentials or banking secrets.
        $fields = ['active', 'revision', 'name', 'number', 'color', 'body', 'notes', 'amount', 'expires_on', 'description', 'gross_amount', 'discount', 'net_amount', 'status_id', 'workflow_status_id', 'member_id', 'family_id', 'team_id', 'season_id', 'team_season_id', 'staff_function_id', 'club_function_id', 'club_event_id', 'qualification_ids', 'bank_id', 'iban_last4', 'portal_visible', 'document_type_id', 'expense_type_id', 'game_type_id', 'opponent', 'suggested_amount', 'suggestion_accepted'];

        return DB::connection('clubunio')->table('clubora.object_changes as changes')
            ->leftJoin('clubora.accounts as actors', 'actors.id', '=', 'changes.actor_id')
            ->where('changes.tenant_id', $tenant->id)->where('changes.object_type', str($record->getTable())->afterLast('.')->toString())
            ->where('changes.object_id', $record->id)->orderByDesc('changes.id')->limit(100)
            ->get(['changes.before_value', 'changes.after_value', 'changes.created_at', 'actors.display_name'])
            ->map(function ($entry) use ($fields) {
                $before = json_decode($entry->before_value ?? '{}', true, flags: JSON_THROW_ON_ERROR) ?? [];
                $after = json_decode($entry->after_value, true, flags: JSON_THROW_ON_ERROR) ?? [];
                $changes = [];
                foreach ($fields as $field) {
                    if ((array_key_exists($field, $before) || array_key_exists($field, $after)) && ($before[$field] ?? null) !== ($after[$field] ?? null)) {
                        $changes[] = ['field' => $field, 'before' => $before[$field] ?? null, 'after' => $after[$field] ?? null];
                    }
                }

                return ['at' => $entry->created_at, 'actor' => $entry->display_name ?? 'System', 'changes' => $changes];
            })->all();
    }
}

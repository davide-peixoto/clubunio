<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;

class AccountLinksImporter
{
    public function run(): array
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new \LogicException('Unexpected target');
        }

        return $db->transaction(function () use ($db) {
            $db->select('SELECT pg_advisory_xact_lock(20261011)');
            if ($db->table('clubora.imports')->where('name', 'account-links-v1')->exists()) {
                return ['already_imported' => true];
            }
            $tenant = Tenant::where('slug', 'sv-sissach')->lockForUpdate()->firstOrFail();
            $members = $db->table('clubora.members')->where('tenant_id', $tenant->id)->whereNotNull('legacy_id')->pluck('id', 'legacy_id');
            $stats = ['self_links' => 0, 'parent_proposals' => 0, 'unresolved_self' => 0];
            $users = $db->table('legacy_prod.Users')->select('Username', 'MitgliederID')->get()->groupBy(fn ($u) => mb_strtolower(trim($u->Username)));
            foreach ($db->table('clubora.tenant_accounts as ta')->join('clubora.accounts as a', 'a.id', '=', 'ta.account_id')
                ->where('ta.tenant_id', $tenant->id)->select('a.id', 'a.username', 'ta.legacy_user_level', 'ta.role')->get() as $account) {
                $source = $users->get($account->username);
                if (! $source || $source->count() !== 1) {
                    throw new \LogicException('Ambiguous account snapshot mapping');
                }
                $known = in_array((int) $account->legacy_user_level, [-1, 1, 2, 10, 11], true);
                $role = match ((int) $account->legacy_user_level) {
                    2 => 'technical_lead', 10 => 'trainer', default => $account->role
                };
                $db->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->where('account_id', $account->id)
                    ->update(['role' => $role, 'portal_enabled' => $known]);
                $legacyMember = (int) $source->first()->MitgliederID;
                if ($legacyMember && isset($members[$legacyMember])) {
                    $db->table('clubora.account_member_links')->insert(['tenant_id' => $tenant->id, 'account_id' => $account->id,
                        'member_id' => $members[$legacyMember], 'kind' => 'self', 'state' => 'approved', 'source' => 'snapshot_user_member']);
                    $stats['self_links']++;
                } elseif ($legacyMember) {
                    $stats['unresolved_self']++;
                }
                // Matching parent addresses are proposals, not proof of parental authority.
                if (filter_var($account->username, FILTER_VALIDATE_EMAIL)) {
                    foreach ($db->table('clubora.members')->where('tenant_id', $tenant->id)->whereNull('archived_at')
                        ->where(fn ($q) => $q->whereRaw('lower(trim(mother_email)) = ?', [$account->username])
                            ->orWhereRaw('lower(trim(father_email)) = ?', [$account->username]))->pluck('id') as $memberId) {
                        $db->table('clubora.account_member_links')->insert(['tenant_id' => $tenant->id, 'account_id' => $account->id,
                            'member_id' => $memberId, 'kind' => 'parent', 'state' => 'pending', 'source' => 'snapshot_parent_email']);
                        $stats['parent_proposals']++;
                    }
                }
            }
            $db->table('clubora.imports')->insert(['name' => 'account-links-v1']);

            return $stats;
        });
    }
}

<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Registration;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\DB;

class RegistrationParentService
{
    /** Proposals confer no access; existing approved/revoked decisions are preserved. */
    public function propose(Account $actor, Tenant $tenant, Registration $registration, Member $member): array
    {
        $db = DB::connection('clubunio');
        if ($db->transactionLevel() === 0) {
            throw new \LogicException('Parent proposals require acceptance transaction');
        }
        if (! $member->birth_date || $member->birth_date->copy()->addYearsNoOverflow(18)->toDateString() <= now('Europe/Zurich')->toDateString()) {
            return [];
        }
        $values = app(RegistrationDataService::class)->values($registration);
        $ids = [];
        foreach (array_unique(array_filter(array_map(fn ($field) => mb_strtolower(trim($values[$field] ?? '')), ['mother_email', 'father_email']))) as $email) {
            $accounts = $db->table('clubora.accounts as a')->join('clubora.tenant_accounts as ta', 'ta.account_id', '=', 'a.id')
                ->where('ta.tenant_id', $tenant->id)->where('a.active', true)
                ->whereRaw('lower(trim(a.username)) = ?', [$email])->pluck('a.id');
            if ($accounts->count() !== 1) {
                continue;
            }
            $account = $accounts->sole();
            if ($db->table('clubora.account_member_links')->where('tenant_id', $tenant->id)->where('account_id', $account)->where('member_id', $member->id)->exists()) {
                continue;
            }
            $id = $db->table('clubora.account_member_links')->insertGetId(['tenant_id' => $tenant->id, 'account_id' => $account,
                'member_id' => $member->id, 'kind' => 'parent', 'state' => 'pending', 'source' => 'registration_review']);
            $db->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                'object_type' => 'account_member_links', 'object_id' => $id,
                'after_value' => json_encode(['registration_id' => $registration->id, 'operation' => 'parent_proposed', 'state' => 'pending'], JSON_THROW_ON_ERROR)]);
            $ids[] = $id;
        }

        return $ids;
    }
}

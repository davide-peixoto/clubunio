<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\AccountMemberLink;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;

class MemberPersonalAccessService
{
    public function authorize(Account $actor, Tenant $tenant, Member $member): void
    {
        abort_unless($actor->fresh()?->hasUnrestrictedBackofficeAccess($tenant)
            && (int) $member->tenant_id === (int) $tenant->id
            && Member::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($member->id)->exists(), 403);
    }

    public function scope(Builder $query, Tenant $tenant): Builder
    {
        return $query->select('clubora.account_member_links.*')
            ->join('clubora.tenant_accounts as personal_membership', function ($join) {
                $join->on('personal_membership.tenant_id', '=', 'clubora.account_member_links.tenant_id')
                    ->on('personal_membership.account_id', '=', 'clubora.account_member_links.account_id');
            })->where('clubora.account_member_links.tenant_id', $tenant->id)
            ->addSelect(['personal_membership.role as personal_role', 'personal_membership.portal_enabled as personal_portal'])
            ->with('account:id,username,display_name,active,api_only');
    }

    /** Personal representation only: never infer access from a staff or administration role. */
    public function capability(AccountMemberLink $link, Member $member, bool $activeMember): string
    {
        if ($link->state !== 'approved') {
            return 'Nicht freigegeben';
        }
        if (! $link->account?->active || $link->account->api_only) {
            return 'Kein persönlicher Login';
        }
        if ($link->personal_role === 'profile' || (! $link->personal_portal && ! in_array($link->personal_role, ['admin', 'office'], true))) {
            return 'Portal nicht freigegeben';
        }
        if (! $activeMember) {
            return 'Mitgliedschaft nicht aktiv';
        }
        $today = now('Europe/Zurich')->toDateString();
        if ($link->kind === 'parent' && (! $member->birth_date || $member->birth_date->toDateString() > $today
            || $member->birth_date->copy()->addYearsNoOverflow(18)->toDateString() <= $today)) {
            return 'Elternvertretung nicht wirksam';
        }

        return in_array($link->kind, ['self', 'parent'], true) ? 'Lesen und Änderungsantrag' : 'Keine persönliche Zuordnung';
    }
}

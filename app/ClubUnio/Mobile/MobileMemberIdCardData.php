<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{MemberAccessService, MemberCardService, ModulePermissionService};

final class MobileMemberIdCardData
{
    public function allowed(Account $actor, Tenant $tenant, int $member): bool
    {
        // Team/contact visibility does not authorize a private card.
        return app(MemberAccessService::class)->represented($actor, $tenant)->whereKey($member)->exists()
            || app(ModulePermissionService::class)->allows($actor, $tenant, 'members', 'view');
    }

    public function show(Account $actor, Tenant $tenant, int $id): array
    {
        abort_unless(config('clubunio-mobile.native_member_id_card_enabled', false), 503, 'Mitgliederausweis noch nicht freigeschaltet.');
        $member = app(MobileMemberData::class)->member($actor, $tenant, $id);
        abort_unless($this->allowed($actor, $tenant, $id), 403);
        $cards = app(MemberCardService::class);
        if ($cards->enabled()) $cards->ensureCard($tenant, $id);
        $snapshot = $cards->snapshot($tenant, $id);
        $member = $snapshot['member'];
        // Card-specific projection: no contact, financial, AHV or portrait data.
        return ['MitgliederID' => (int) $member->id, 'TenantID' => (int) $tenant->id, 'TenantName' => $tenant->name,
            'Vorname' => $member->first_name, 'Nachname' => $member->last_name,
            'Geburtsdatum' => $member->birth_date?->format('Y-m-d'),
            'StatusText' => $member->status?->name, 'MitgliederartText' => $member->type?->name,
            'Ehrenmitglied' => (bool) $member->honorary, 'Freimitglied' => (bool) $member->free_membership,
            'Brand' => $snapshot['Brand'],
            'Verification' => $cards->verification($tenant, $id, $snapshot),
            'Wallet' => app(\App\ClubUnio\Wallet\MemberWalletService::class)->capabilities($tenant),
            'SourceVersion' => $snapshot['SourceVersion']];
    }
}

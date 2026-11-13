<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, MemberChangeRequest, Tenant};
use App\ClubUnio\Services\{ExpenseService, PitchPlanningService, RegistrationReviewService};

final class MobileWorkCounts
{
    public function count(string $module, Account $actor, Tenant $tenant): ?int
    {
        return match ($module) {
            'Mitglieder' => MemberChangeRequest::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('state', 'pending')
                ->whereIn('member_id', app(MobileMemberData::class)->scope($actor, $tenant)->select('clubora.members.id'))
                ->count(),
            'MitgliederAnmeldungen' => app(RegistrationReviewService::class)->visible($actor, $tenant)->count(),
            'Platzverfugbarkeit' => app(PitchPlanningService::class)->pendingRequestCount($actor, $tenant),
            'Spesen' => app(ExpenseService::class)->visible($actor, $tenant)
                ->whereHas('status', fn ($status) => $status->where('code', 'F'))->count(),
            'BusReservationen' => app(MobileBusData::class)->visible($actor, $tenant)
                ->where('status', 'ANGEFRAGT')->where('ends_at', '>', now()->toIso8601String())->count(),
            'Dokumente' => app(MobileMemberDocuments::class)->ownDocumentCount($actor, $tenant),
            default => null,
        };
    }
}

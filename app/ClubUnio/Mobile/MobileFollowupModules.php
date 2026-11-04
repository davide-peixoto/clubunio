<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\{ModulePermissionService, RegistrationReviewService, SponsorRunService};
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MobileFollowupModules
{
    public const CONTRACTS = [
        'Mitglieder' => ['native_members_enabled', 'members-v1'],
        'MitgliederAnmeldungen' => ['native_registrations_enabled', 'registrations-v1'],
        'Trainingsplaner' => ['native_training_enabled', 'training-v1'],
        'Sponsorenlauf' => ['native_sponsor_enabled', 'sponsor-v1'],
    ];

    public function allowed(string $key, Account $actor, Tenant $tenant): bool
    {
        if (! isset(self::CONTRACTS[$key]) || ! config('clubunio-mobile.'.self::CONTRACTS[$key][0], false)) return false;
        try {
            app(MobileAccess::class)->enter($actor, $tenant);
            return match ($key) {
                'Mitglieder' => app(ModulePermissionService::class)->allows($actor, $tenant, 'members', 'view')
                    || app(MobileMemberData::class)->scope($actor, $tenant)->exists(),
                'MitgliederAnmeldungen' => $this->registrations($actor, $tenant),
                'Trainingsplaner' => $this->training($actor, $tenant),
                'Sponsorenlauf' => app(SponsorRunService::class)->allows($actor, $tenant)
                    || app(SponsorRunService::class)->events($actor, $tenant, ['scope' => 'all'])->exists(),
            };
        } catch (HttpException $error) {
            if ($error->getStatusCode() !== 403) throw $error;
            return false;
        }
    }

    private function registrations(Account $actor, Tenant $tenant): bool
    {
        app(MobileRegistrationData::class)->authorizeReader($actor, $tenant);
        return app(ModulePermissionService::class)->allows($actor, $tenant, 'registrations', 'view')
            || app(RegistrationReviewService::class)->visible($actor, $tenant)->exists();
    }

    private function training(Account $actor, Tenant $tenant): bool
    {
        $service = app(MobileTrainingPlanService::class);
        $service->authorizeOperation($actor, $tenant, 'view');
        return app(ModulePermissionService::class)->allows($actor, $tenant, 'training', 'view')
            || $service->teams($actor, $tenant)->isNotEmpty();
    }
}

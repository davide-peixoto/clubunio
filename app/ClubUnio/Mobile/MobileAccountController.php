<?php

namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\ModulePermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class MobileAccountController
{
    public function profile(Request $request)
    {
        $actor = $request->attributes->get('mobile.actor');
        $language = app(MobileLanguages::class)->forAccount($actor);
        return response()->json(['data' => ['id' => (int) $actor->id,
            'username' => $actor->username, 'name' => $actor->display_name,
            'language' => $language['language'], 'language_policy' => $language['language_policy'], 'language_fallback' => $language['language_fallback'],
            'recovery_tenants' => $this->recoveryTenants($request, $actor),
            'keybox_tenants' => app(MobileKeyboxService::class)->profileTenants($actor,
                app(\App\ClubUnio\Services\TenantDomainService::class)->resolve($request)),
            'password_change' => true, 'push' => app(MobilePushReady::class)->available($request), 'registration' => false,
            'password_reset' => false]]);
    }

    private function recoveryTenants(Request $request, Account $actor): array
    {
        if (!config('clubunio-mobile.public_identity.enabled', false)) return [];
        $policy = app(MobilePublicIdentityPolicy::class);
        try { $origin = $policy->origin($request); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) { return []; }
        $result = [];
        foreach ($actor->tenants()->limit(100)->get() as $tenant) {
            try {
                $policy->tenant($origin, (int) $tenant->id);
                app(MobileAccess::class)->enter($actor, $tenant);
                if ($policy->enabled($tenant, 'email_enrollment')) $result[] = (int) $tenant->id;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException $error) { }
        }
        return $result;
    }

    public function password(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|string|max:4096',
            'password' => 'required|string|min:12|max:72|confirmed',
            'password_confirmation' => 'required|string|max:72']);
        if (strlen($data['password']) > 72) {
            throw ValidationException::withMessages(['password' => 'Maximal 72 UTF-8-Bytes.']);
        }
        $actor = $request->attributes->get('mobile.actor');
        $key = 'mobile-password:'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429);
        RateLimiter::hit($key, 60);
        DB::connection('clubunio')->transaction(function () use ($request, $actor, $data) {
            $fresh = Account::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            app(MobileSessions::class)->authenticate($request);
            if (! Hash::check($data['current_password'], $fresh->password)) {
                throw ValidationException::withMessages(['current_password' => 'Aktuelles Passwort ungueltig.']);
            }
            $fresh->forceFill(['password' => Hash::make($data['password'])])->save();
            DB::connection('clubunio')->table('clubora.mobile_sessions')->where('account_id', $actor->id)
                ->whereNull('revoked_at')->update(['revoked_at' => now()->toIso8601String()]);
        });
        return response()->json(['data' => ['reauthentication_required' => true]]);
    }

    public function modules(Request $request)
    {
        $actor = $request->attributes->get('mobile.actor');
        $tenant = $request->attributes->get('mobile.tenant');
        $hasTeams = app(MobileAccess::class)->teams($actor, $tenant)->exists();
        $map = ['Spesen' => 'expenses', 'Mitglieder' => 'members', 'Platzverfugbarkeit' => 'field_availability',
            'MitgliederAnmeldungen' => 'registrations', 'Teams' => 'teams', 'Teamplanung' => 'teams',
            'Trainingsplaner' => 'training', 'BusReservationen' => 'bus', 'Dokumente' => 'documents',
            'Sponsorenlauf' => 'sponsor_runs', 'Penalty' => 'penalty'];
        $modules = [];
        $busAllowed = false;
        if (config('clubunio-mobile.native_bus_enabled')) {
            try {
                app(\App\ClubUnio\Services\BusReservationService::class)->enter($actor, $tenant);
                $busAllowed = true;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                if ($error->getStatusCode() !== 403) { throw $error; }
            }
        }
        $visibleKeys = app(\App\ClubUnio\Services\MobileModuleSettings::class)->visibleKeys($actor, $tenant);
        // Presentation compatibility only; feature claims never grant permissions.
        $supported = explode(',', substr((string) $request->header('X-ClubUnio-Mobile-Features', ''), 0, 512));
        $pitchAllowed = in_array('Platzverfugbarkeit', $visibleKeys, true)
            && config('clubunio-mobile.native_pitch_planning_enabled', false)
            && app(\App\ClubUnio\Services\PitchPlanningProjection::class)->ready()
            && app(\App\ClubUnio\Services\PitchPlanningService::class)->canView($actor, $tenant);
        $pitchNative = $pitchAllowed && in_array('pitch-planning-v1', $supported, true);
        $facilitiesNative = config('clubunio-mobile.native_facilities_enabled')
            && in_array('facilities-v1', $supported, true)
            && app(ModulePermissionService::class)->allows($actor, $tenant, 'field_availability', 'view');
        foreach ($map as $key => $permission) {
            if (! in_array($key, $visibleKeys, true)) { continue; }
            $followupAllowed = app(MobileFollowupModules::class)->allowed($key, $actor, $tenant);
            $penaltyAllowed = $key === 'Penalty' && app(MobilePenaltyAccess::class)->canPlay($actor, $tenant);
            $allowed = app(ModulePermissionService::class)->allows($actor, $tenant, $permission, 'view')
                || ($key === 'Spesen' && config('clubunio-mobile.native_expenses_enabled') && app(\App\ClubUnio\Services\ExpenseService::class)->portalAllowed($actor, $tenant))
                || ($key === 'Dokumente' && config('clubunio-mobile.native_documents_enabled') && app(\App\ClubUnio\Services\MemberAccessService::class)->represented($actor, $tenant)->exists())
                || ($key === 'BusReservationen' && $busAllowed)
                || ($key === 'Platzverfugbarkeit' && $pitchAllowed)
                || ($hasTeams && in_array($key, ['Teams', 'Teamplanung'], true))
                || $followupAllowed || $penaltyAllowed;
            if ($allowed) {
                $native = match ($key) {
                    'Penalty' => $penaltyAllowed && in_array('penalty-v1', $supported, true),
                    'Mitglieder', 'MitgliederAnmeldungen', 'Trainingsplaner', 'Sponsorenlauf' =>
                        $followupAllowed
                        && in_array(MobileFollowupModules::CONTRACTS[$key][1], $supported, true),
                    'Teamplanung' => config('clubunio-mobile.native_planning_enabled') && in_array('svs-planning-v1', $supported, true),
                    'Teams' => config('clubunio-mobile.native_my_teams_enabled') && in_array('my-teams-v1', $supported, true),
                    'Spesen' => config('clubunio-mobile.native_expenses_enabled') && in_array('expenses-v1', $supported, true),
                    'Platzverfugbarkeit' => $facilitiesNative || $pitchNative,
                    'BusReservationen' => config('clubunio-mobile.native_bus_enabled') && in_array('bus-v1', $supported, true),
                    'Dokumente' => config('clubunio-mobile.native_documents_enabled') && in_array('member-documents-v1', $supported, true), default => false,
                };
                $modules[] = ['key' => $key, 'available' => $key === 'Teamplanung' || $native, 'native' => $native]
                    + ($key === 'Platzverfugbarkeit' ? ['pitch_planning' => $pitchNative, 'facilities' => $facilitiesNative] : []);
            }
        }
        $modules[] = ['key' => 'Profil', 'available' => true];
        return response()->json(['data' => ['modules' => $modules]]);
    }
}

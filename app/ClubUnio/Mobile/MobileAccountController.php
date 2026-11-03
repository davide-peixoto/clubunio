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
        return response()->json(['data' => ['id' => (int) $actor->id,
            'username' => $actor->username, 'name' => $actor->display_name,
            'password_change' => true, 'push' => false, 'registration' => false,
            'password_reset' => false]]);
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
            'Sponsorenlauf' => 'sponsor_runs'];
        $modules = [];
        foreach ($map as $key => $permission) {
            $allowed = app(ModulePermissionService::class)->allows($actor, $tenant, $permission, 'view')
                || ($hasTeams && in_array($key, ['Teams', 'Teamplanung'], true));
            if ($allowed) {
                $modules[] = ['key' => $key, 'available' => $key === 'Teamplanung'];
            }
        }
        $modules[] = ['key' => 'Profil', 'available' => true];
        return response()->json(['data' => ['modules' => $modules]]);
    }
}

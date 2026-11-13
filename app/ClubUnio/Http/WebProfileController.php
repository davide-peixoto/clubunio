<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\{ProfilePhotoService, TenantDomainService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Hash, RateLimiter};
use Illuminate\Validation\ValidationException;

final class WebProfileController
{
    private function guard(Request $request): string
    {
        return $request->routeIs('clubunio.portal.profile.*') ? 'portal' : 'clubunio';
    }

    private function actor(Request $request): Account
    {
        abort_if($request->attributes->has('clubunio_impersonation') || $request->session()->has('clubunio_impersonation'), 403);
        $actor = $request->user($this->guard($request))?->fresh();
        abort_unless($actor?->active && ! $actor->api_only, 403);

        return $actor;
    }

    private function routePrefix(Request $request): string
    {
        return $this->guard($request) === 'portal' ? 'clubunio.portal.profile.' : 'clubunio.web-profile.';
    }

    public function show(Request $request, ProfilePhotoService $photos)
    {
        $actor = $this->actor($request);
        $tenant = app(TenantDomainService::class)->current();
        $prefix = $this->routePrefix($request);
        $portal = $this->guard($request) === 'portal';
        $club = $portal ? $actor->tenants()->get()->first(fn ($candidate) => (! $tenant || $tenant->is($candidate))
            && app(\App\ClubUnio\Services\MemberAccessService::class)->canEnter($actor, $candidate)) : null;

        return response()->view('clubunio.portal.profile', [
            'actor' => $actor, 'prefix' => $prefix,
            'backUrl' => $portal ? ($club ? route('clubunio.portal.members', $club->slug) : route('clubunio.portal.login')) : url('/'),
            'logoutUrl' => route($portal ? 'clubunio.portal.logout' : 'filament.clubunio.auth.logout'),
            'hasPhoto' => $photos->available($actor, $tenant),
            'canUpload' => $photos->canUpload($actor, $tenant),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function photo(Request $request, ProfilePhotoService $photos)
    {
        return $photos->response($photos->personal($this->actor($request), app(TenantDomainService::class)->current()));
    }

    public function upload(Request $request, ProfilePhotoService $photos)
    {
        $actor = $this->actor($request);
        $key = 'web-profile-photo:'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($key, 8), 429);
        RateLimiter::hit($key, 3600);
        $request->validate(['file' => 'required|file|max:5120'], [], ['file' => __('photo.title')]);
        $state = $photos->upload($actor, app(TenantDomainService::class)->current(), $request->file('file'));

        return redirect()->route($this->routePrefix($request).'show')
            ->with('profile_notice', $state === 'pending' ? __('profile_web.photo_pending') : __('profile_web.photo_saved'));
    }

    public function password(Request $request)
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'current_password' => 'required|string|max:4096',
            'password' => 'required|string|min:12|max:72|confirmed',
        ], [], ['current_password' => __('profile_web.current'), 'password' => __('profile_web.new')]);
        if (strlen($data['password']) > 72) {
            throw ValidationException::withMessages(['password' => __('profile_web.max_bytes')]);
        }
        $key = 'web-profile-password:'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429);
        RateLimiter::hit($key, 60);
        DB::connection('clubunio')->transaction(function () use ($actor, $data) {
            $fresh = Account::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->active && ! $fresh->api_only, 403);
            if (! Hash::check($data['current_password'], $fresh->password)) {
                throw ValidationException::withMessages(['current_password' => __('profile_web.invalid')]);
            }
            $fresh->forceFill(['password' => Hash::make($data['password'])])->save();
            DB::connection('clubunio')->table('clubunio.mobile_sessions')->where('account_id', $actor->id)
                ->whereNull('revoked_at')->update(['revoked_at' => now()->toIso8601String()]);
        });
        $guard = $this->guard($request);
        Auth::guard($guard)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect($guard === 'portal' ? route('clubunio.portal.login') : route('filament.clubunio.auth.login'));
    }
}

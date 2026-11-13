<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\TenantDomainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class PublicSupportIntake
{
    public function __invoke(Request $request)
    {
        abort_unless(config('clubunio-support.enabled'), 404);
        $tenant = app(TenantDomainService::class)->resolve($request);
        if ($request->isMethod('POST')) {
            $validator = Validator::make($request->only(['email','body']), ['email' => 'required|email:rfc|max:254', 'body' => 'required|string|max:2000']);
            if ($validator->fails()) return redirect()->route('clubunio.support.login-help')->withErrors($validator);
            $data = $validator->validated();
            DB::connection('clubunio')->table('clubora.support_public_requests')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant?->id,
                'payload' => encrypt(json_encode($data, JSON_THROW_ON_ERROR)),
            ]);
            return redirect()->route('clubunio.support.login-help')->with('support_received', true);
        }
        return response()->view('clubunio.support.login-help')->header('Cache-Control', 'private, no-store')
            ->header('Content-Security-Policy', "frame-ancestors 'none'; form-action 'self'");
    }

    public function inbox(Request $request, \App\ClubUnio\Services\SupportAccess $access, ?int $club = null)
    {
        abort_unless(config('clubunio-support.enabled'), 404);
        $actor = $request->user('clubunio');
        abort_unless($actor, 401);
        if ($club === null) {
            $access->platform($request, $actor);
        } else {
            abort_unless($access->staff($access->role($request, $actor, \App\ClubUnio\Models\Tenant::findOrFail($club))), 403);
        }
        $rows = DB::connection('clubunio')->table('clubora.support_public_requests')
            ->where('tenant_id', $club)->latest()->limit(50)->get();
        return response()->view('clubunio.support.intake-inbox', compact('rows'))
            ->header('Cache-Control', 'private, no-store')->header('Content-Security-Policy', "frame-ancestors 'none'");
    }
}

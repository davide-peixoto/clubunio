<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Api\ApiDocumentation;
use App\ClubUnio\Api\ApiTokenService;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ActiveTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ApiDocumentationController
{
    public function show(Request $request): Response
    {
        $tenant = $this->authorize($request);

        return response()->view('clubunio.api-docs', ['tenant' => $tenant])
            ->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function specification(Request $request): JsonResponse
    {
        $this->authorize($request);

        return response()->json(app(ApiDocumentation::class)->specification())
            ->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function authorize(Request $request): Tenant
    {
        $account = $request->user('clubunio');
        abort_unless($account instanceof Account, 401);
        $tenant = app(ActiveTenant::class)->resolve($request);
        abort_unless(app(ApiTokenService::class)->isAdmin($account, $tenant), 403);

        return $tenant;
    }
}

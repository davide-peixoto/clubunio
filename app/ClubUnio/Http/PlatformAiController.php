<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{PlatformAdministrationService, TenantAiConfiguration};
use Illuminate\Http\Request;

final class PlatformAiController
{
    public function __invoke(Request $request, Tenant $tenant, TenantAiConfiguration $configuration)
    {
        $actor = $request->user(PlatformOidcController::guard($request));
        app(PlatformAdministrationService::class)->authorize($actor);
        abort_unless(config('clubunio-tenant-ai.enabled'),404);
        if ($request->isMethod('post')) {
            try {
                if ($request->input('operation') === 'verify') {
                    $configuration->verify($actor,$tenant,true);
                } else {
                    $configuration->save($actor,$tenant,[
                        'mode'=>'clubunio','project_id'=>$request->input('project_id'), 'api_key'=>$request->input('api_key'),
                        'revision'=>$request->integer('revision'),'enabled'=>$request->boolean('enabled'),
                        'privacy_reviewed'=>$request->boolean('privacy_reviewed'),
                        'monthly_limit_microusd'=>(int)round((float)$request->input('monthly_limit_usd')*1_000_000),
                    ],true);
                }
            } catch (\Throwable) {
                // No input flashing: API keys must not enter session old-input or logs.
                return back()->with('notice',trans('tenant_ai.failed'));
            }
            return back()->with('notice',trans('tenant_ai.completed'));
        }
        return response()->view('clubunio.platform.ai',['tenant'=>$tenant,'actor'=>$actor,'configuration'=>$configuration->summary($tenant)])
            ->header('Cache-Control','private, no-store');
    }
}

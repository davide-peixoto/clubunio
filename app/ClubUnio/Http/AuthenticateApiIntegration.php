<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Api\ApiContext;
use App\ClubUnio\Api\ApiErrors;
use App\ClubUnio\Api\ApiTokenService;
use App\ClubUnio\Api\Modules\ApiModuleRegistry;
use App\ClubUnio\Api\Providers\ProviderApiRegistry;
use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ApiAccessToken;
use App\ClubUnio\Models\ApiIntegration;
use App\ClubUnio\Models\Tenant;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

final class AuthenticateApiIntegration
{
    public function handle(Request $request, Closure $next)
    {
        $id = (string) Str::uuid();
        $start = hrtime(true);
        $context = null;
        $request->attributes->set('clubunio.api.request_id', $id);
        $request->headers->set('Accept', 'application/json');
        try {
            $this->admit('clubunio-api-auth:'.hash('sha256', (string) $request->ip()), 600);
            // Bearer only: an administrator's browser session never authenticates an API call.
            abort_unless(is_string($request->bearerToken()) && preg_match('/^[0-9]{1,18}\|cu_[A-Za-z0-9]{30,100}$/D', $request->bearerToken()), 401);
            auth('sanctum')->forgetUser();
            $integration = auth('sanctum')->user();
            abort_unless($integration instanceof ApiIntegration && $integration->active, 401);
            $token = $integration->currentAccessToken();
            abort_unless($token instanceof ApiAccessToken && ! $token->revoked_at && $token->expires_at?->isFuture(), 401);
            $actor = Account::find($integration->account_id);
            $tenant = Tenant::find($integration->tenant_id);
            $profile = AccessProfile::where('tenant_id', $integration->tenant_id)->where('active', true)->find($integration->access_profile_id);
            abort_unless($actor?->active && $actor->api_only && $tenant && $profile, 401);
            abort_unless(DB::connection('clubunio')->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)
                ->where('account_id', $actor->id)->where('role', 'profile')->where('access_profile_id', $profile->id)->exists(), 401);
            $context = new ApiContext($actor, $tenant, $integration, $token);
            $request->attributes->set('clubunio.api.context', $context);
            $request->attributes->set('clubunio.api.request_id', $id);
            $this->admit('clubunio-api:'.$integration->id, $integration->rate_limit);
            $response = $next($request);
        } catch (Throwable $error) {
            $response = app(ApiErrors::class)->render($error, $request);
        }
        if ($context) {
            try {
                app(ApiTokenService::class)->audit($context->tenant, $context->integration->id, $context->actor->id, $context->token->id, 'request', [
                    'request_id' => $id, 'method' => $request->method(), 'route' => $this->auditRoute($request),
                    'status' => $response->getStatusCode(), 'duration_ms' => (int) ((hrtime(true) - $start) / 1_000_000),
                ]);
            } catch (Throwable) {
                // Preserve the known business outcome, and retain a sanitized fallback trace.
                logger()->critical('ClubUnio API audit unavailable', ['request_id' => $id,
                    'integration_id' => $context->integration->id, 'token_id' => $context->token->id,
                    'tenant_id' => $context->tenant->id, 'status' => $response->getStatusCode()]);
                $response->headers->set('X-Audit-Status', 'fallback');
            }
        }

        $response->headers->set('X-Request-ID', $id);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function admit(string $key, int $limit): void
    {
        try {
            Cache::lock($key.':admission', 5)->block(1, function () use ($key, $limit) {
                abort_if(RateLimiter::tooManyAttempts($key, $limit), 429);
                RateLimiter::hit($key, 60);
            });
        } catch (LockTimeoutException) {
            abort(429);
        }
    }

    private function auditRoute(Request $request): ?string
    {
        $route = $request->route();
        if (! $route) {
            return null;
        }
        $path = $route->uri();
        $definitions = app(ApiModuleRegistry::class)->definitions();
        $resource = $route->parameter('resource');
        if (is_string($resource) && isset($definitions[$resource])) {
            $path = str_replace('{resource}', $resource, $path);
            $action = $route->parameter('action');
            if (is_string($action) && isset($definitions[$resource]['actions'][$action])) {
                $path = str_replace('{action}', $action, $path);
            }
        }
        $id = (string) $route->parameter('id');
        if (preg_match('/^[1-9][0-9]{0,17}$/D', $id)) {
            $path = str_replace('{id}', $id, $path);
        }
        $kind = $route->parameter('kind');
        $operation = $route->parameter('operation');
        if (is_string($operation) && isset(app(ProviderApiRegistry::class)->definitions()[$operation])) {
            $path = str_replace('{operation}', $operation, $path);
        }
        $known = [...array_keys(ApiFileController::TYPES), ...array_keys(ApiUploadController::TYPES), ...array_keys(ApiReportController::REPORTS), 'invoices', 'payments'];
        if (is_string($kind) && in_array($kind, $known, true)) {
            $path = str_replace('{kind}', $kind, $path);
        }

        return $path;
    }
}

<?php

namespace App\ClubUnio\Api;

use App\ClubUnio\Api\Modules\ApiModuleRegistry;
use App\ClubUnio\Api\Providers\ProviderApiRegistry;
use App\ClubUnio\Http\ApiFileController;
use App\ClubUnio\Http\ApiUploadController;
use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ApiAccessToken;
use App\ClubUnio\Models\ApiIntegration;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\PermissionCatalog;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ApiTokenService
{
    public const WEBSITE = [
        'website:teams:read' => ['teams', 'view', 'Webseite: Mannschaften'],
        'website:fixtures:read' => ['competitions', 'view', 'Webseite: Spielplan'],
        'website:training-times:read' => ['training_slots', 'view', 'Webseite: Trainingszeiten'],
        'website:facility-status:read' => ['field_availability', 'view', 'Webseite: Anlagenstatus'],
    ];

    public function isAdmin(?Account $actor, Tenant $tenant): bool
    {
        return $actor && ! $actor->api_only && DB::connection('clubunio')->table('clubora.tenant_accounts as ta')
            ->join('clubora.accounts as a', 'a.id', '=', 'ta.account_id')
            ->where('ta.tenant_id', $tenant->id)->where('ta.account_id', $actor->id)
            ->where('ta.role', 'admin')->where('a.active', true)->where('a.api_only', false)->exists();
    }

    private function authorizeAdminLocked(Account $actor, Tenant $tenant): void
    {
        Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
        $row = DB::connection('clubunio')->table('clubora.tenant_accounts as ta')
            ->join('clubora.accounts as a', 'a.id', '=', 'ta.account_id')
            ->where('ta.tenant_id', $tenant->id)->where('ta.account_id', $actor->id)
            ->where('ta.role', 'admin')->where('a.active', true)->where('a.api_only', false)->sharedLock()->first(['ta.account_id']);
        abort_unless($row, 403);
    }

    public function scopes(): array
    {
        $scopes = array_map(fn ($row) => $row[2], self::WEBSITE);
        $functions = [];
        foreach (app(ApiModuleRegistry::class)->definitions() as $definition) {
            $module = $definition['permission_module'];
            $enabled = ['view'];
            foreach (['create', 'update'] as $function) {
                if ($definition[$function.'_fields'] ?? []) {
                    $enabled[] = $function;
                }
            }
            foreach ($definition['actions'] ?? [] as $action) {
                $enabled[] = $action['function'];
            }
            if (in_array('active', $definition['update_fields'] ?? [], true) || $module === 'members') {
                $enabled = [...$enabled, 'deactivate', 'restore'];
            }
            $functions[$module] = [...($functions[$module] ?? []), ...$enabled];
        }
        foreach (ApiFileController::TYPES as $module) {
            $functions[$module][] = 'download';
        }
        foreach (ApiUploadController::TYPES as $module) {
            $functions[$module][] = 'upload';
        }
        foreach (app(ProviderApiRegistry::class)->definitions() as $definition) {
            foreach ($definition['scopes'] as $scope => [$module, $function]) {
                $scopes[$scope] = PermissionCatalog::MODULES[$module].': '.(PermissionCatalog::functions($module)[$function] ?? $function);
            }
        }
        foreach (['signage' => ['send'], 'documents' => ['send', 'create'], 'training' => ['send'], 'planning' => ['cancel', 'restore'],
            'reports' => ['view', 'export'], 'invoices' => ['export'], 'expenses' => ['export']] as $module => $extra) {
            $functions[$module] = [...($functions[$module] ?? []), ...$extra];
        }
        foreach ($functions as $module => $enabled) {
            foreach (array_unique($enabled) as $function) {
                $caption = PermissionCatalog::functions($module)[$function] ?? null;
                if ($caption) {
                    $scopes[$module.':'.($function === 'view' ? 'read' : $function)] = PermissionCatalog::MODULES[$module].': '.$caption;
                }
            }
        }

        return $scopes;
    }

    public function integrations(Account $actor, Tenant $tenant): Collection
    {
        abort_unless($this->isAdmin($actor, $tenant), 403);

        return ApiIntegration::where('tenant_id', $tenant->id)->with(['accessProfile', 'tokens' => fn ($q) => $q->orderByDesc('id')])->orderByDesc('id')->get();
    }

    public function createIntegration(Account $actor, Tenant $tenant, array $input): ApiIntegration
    {
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $input) {
            $this->authorizeAdminLocked($actor, $tenant);
            if (is_string($input['name'] ?? null)) {
                $input['name'] = trim($input['name']);
            }
            $data = Validator::make($input, [
                'name' => ['required', 'string', 'max:120', Rule::unique('clubunio.clubora.api_integrations')->where('tenant_id', $tenant->id)],
                'access_profile_id' => 'required|integer',
                'scopes' => 'required|array|min:1', 'scopes.*' => ['required', 'string', 'distinct', Rule::in(array_keys($this->scopes()))],
                'rate_limit' => 'sometimes|integer|min:1|max:600',
            ])->validate();
            $profile = AccessProfile::where('tenant_id', $tenant->id)->where('active', true)->lockForUpdate()->findOrFail($data['access_profile_id']);
            $this->assertProfileScopes($profile, $data['scopes']);
            $service = new Account;
            $service->forceFill(['username' => 'api-'.Str::uuid(), 'display_name' => 'API: '.$data['name'],
                'password' => Hash::make(Str::random(80)), 'active' => true, 'api_only' => true])->save();
            DB::connection('clubunio')->table('clubora.tenant_accounts')->insert([
                'tenant_id' => $tenant->id, 'account_id' => $service->id, 'role' => 'profile', 'access_profile_id' => $profile->id,
            ]);
            $integration = new ApiIntegration;
            $integration->forceFill(['tenant_id' => $tenant->id, 'account_id' => $service->id, 'access_profile_id' => $profile->id,
                'name' => trim($data['name']), 'active' => true, 'scopes' => $data['scopes'],
                'rate_limit' => $data['rate_limit'] ?? 60, 'created_by' => $actor->id])->save();
            $this->audit($tenant, $integration->id, $actor->id, null, 'integration.created');

            return $integration;
        });
    }

    public function issueToken(Account $actor, Tenant $tenant, int $integrationId, string $name, array $scopes, CarbonInterface $expiresAt): array
    {
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $integrationId, $name, $scopes, $expiresAt) {
            $this->authorizeAdminLocked($actor, $tenant);
            $name = trim($name);
            $integration = ApiIntegration::where('tenant_id', $tenant->id)->where('active', true)->lockForUpdate()->findOrFail($integrationId);
            Validator::make(['name' => $name, 'scopes' => $scopes, 'expires_at' => $expiresAt], [
                'name' => 'required|string|max:120', 'scopes' => 'required|array|min:1',
                'scopes.*' => ['required', 'string', 'distinct', Rule::in($integration->scopes)],
                'expires_at' => 'required|date|after:now|before_or_equal:'.now()->addYear()->toIso8601String(),
            ])->validate();
            $profile = AccessProfile::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($integration->access_profile_id);
            $this->assertProfileScopes($profile, $scopes);
            $issued = $integration->createToken(trim($name), $scopes, $expiresAt);
            $this->audit($tenant, $integration->id, $actor->id, $issued->accessToken->id, 'token.issued');

            return ['token' => $issued->plainTextToken, 'id' => $issued->accessToken->id];
        });
    }

    public function revokeToken(Account $actor, Tenant $tenant, int $tokenId): void
    {
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $tokenId) {
            $this->authorizeAdminLocked($actor, $tenant);
            $token = ApiAccessToken::whereHasMorph('tokenable', [ApiIntegration::class], fn ($q) => $q->where('tenant_id', $tenant->id))->lockForUpdate()->findOrFail($tokenId);
            $token->forceFill(['revoked_at' => now()])->save();
            $this->audit($tenant, $token->tokenable_id, $actor->id, $token->id, 'token.revoked');
        });
    }

    public function disableIntegration(Account $actor, Tenant $tenant, int $id): void
    {
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id) {
            $this->authorizeAdminLocked($actor, $tenant);
            $integration = ApiIntegration::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
            $integration->forceFill(['active' => false])->save();
            $integration->tokens()->update(['revoked_at' => now()]);
            $this->audit($tenant, $id, $actor->id, null, 'integration.disabled');
        });
    }

    private function assertProfileScopes(AccessProfile $profile, array $scopes): void
    {
        foreach ($scopes as $scope) {
            [$module, $function] = self::WEBSITE[$scope] ?? explode(':', $scope, 2);
            $function = $function === 'read' ? 'view' : $function;
            $allowed = in_array($module.'.view', $profile->permissions, true)
                && in_array($module.'.'.$function, $profile->permissions, true);
            if ($module === 'field_availability') {
                $allowed = $allowed || (in_array('facilities.view', $profile->permissions, true)
                    && in_array('facilities.'.$function, $profile->permissions, true));
            }
            abort_unless($allowed, 422, 'Scopes überschreiten das Rechteprofil.');
        }
    }

    public function audit(Tenant $tenant, ?int $integration, ?int $actor, ?int $token, string $event, array $extra = []): void
    {
        DB::connection('clubunio')->table('clubora.api_audit')->insert([
            'tenant_id' => $tenant->id, 'integration_id' => $integration, 'actor_id' => $actor,
            'token_id' => $token, 'request_id' => $extra['request_id'] ?? (string) Str::uuid(), 'event' => $event,
            ...array_intersect_key($extra, array_flip(['method', 'route', 'status', 'duration_ms'])),
        ]);
    }
}

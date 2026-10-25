<?php

namespace App\ClubUnio\Api;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ApiAccessToken;
use App\ClubUnio\Models\ApiIntegration;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\ModulePermissionService;
use Illuminate\Support\Facades\DB;

final class ApiContext
{
    public function __construct(
        public readonly Account $actor,
        public readonly Tenant $tenant,
        public readonly ApiIntegration $integration,
        public readonly ApiAccessToken $token,
    ) {}

    public function authorize(string $scope, string $module, string $function): void
    {
        [$ceiling, $abilities] = $this->current();
        abort_unless(in_array($scope, $abilities, true) && in_array($scope, $ceiling, true), 403);
        app(ModulePermissionService::class)->authorize($this->actor, $this->tenant, $module, $function);
    }

    /** Shared row locks serialize local writes with revocation/profile changes. */
    private function current(): array
    {
        $db = DB::connection('clubunio');
        $row = $db->table('clubora.api_integrations as integration')
            ->join('clubora.api_access_tokens as token', 'token.tokenable_id', '=', 'integration.id')
            ->join('clubora.accounts as actor', 'actor.id', '=', 'integration.account_id')
            ->join('clubora.access_profiles as profile', function ($join) {
                $join->on('profile.id', '=', 'integration.access_profile_id')->on('profile.tenant_id', '=', 'integration.tenant_id');
            })->join('clubora.tenant_accounts as membership', function ($join) {
                $join->on('membership.account_id', '=', 'integration.account_id')->on('membership.tenant_id', '=', 'integration.tenant_id')
                    ->on('membership.access_profile_id', '=', 'profile.id');
            })->where('integration.id', $this->integration->id)->where('integration.tenant_id', $this->tenant->id)
            ->where('integration.account_id', $this->actor->id)->where('integration.active', true)
            ->where('token.id', $this->token->id)->where('token.tokenable_type', ApiIntegration::class)
            ->whereNull('token.revoked_at')->where('token.expires_at', '>', now()->toIso8601String())
            ->where('actor.active', true)->where('actor.api_only', true)->where('profile.active', true)
            ->where('membership.role', 'profile')->when($db->transactionLevel() > 0, fn ($q) => $q->sharedLock())
            ->first(['integration.scopes', 'token.abilities', 'profile.id as profile_id', 'profile.permissions']);
        abort_unless($row, 403);

        return [json_decode($row->scopes, true, flags: JSON_THROW_ON_ERROR),
            json_decode($row->abilities, true, flags: JSON_THROW_ON_ERROR), (int) $row->profile_id,
            json_decode($row->permissions, true, flags: JSON_THROW_ON_ERROR)];
    }

    public function authorizationFingerprint(): string
    {
        return hash('sha256', json_encode($this->current(), JSON_THROW_ON_ERROR));
    }
}

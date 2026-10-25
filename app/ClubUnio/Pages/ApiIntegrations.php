<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Api\ApiTokenService;
use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class ApiIntegrations extends Page
{
    protected string $view = 'clubunio.api-integrations';

    protected static ?string $title = 'API-Integrationen';

    public string $name = '';

    public ?int $accessProfileId = null;

    public int $rateLimit = 60;

    public array $integrationScopes = [];

    public ?int $integrationId = null;

    public string $tokenName = '';

    public array $tokenScopes = [];

    public string $expiresAt = '';

    public bool $readOnly = true;

    #[Locked]
    public int $contextTenantId;

    #[Locked]
    public int $contextAccountId;

    // Never dehydrate plaintext into the Livewire snapshot or session.
    protected ?string $issuedToken = null;

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        $tenant = Filament::getTenant();
        $actor = $actor instanceof Account ? $actor->fresh() : null;
        $tenant = $tenant instanceof Tenant ? $tenant->fresh() : null;

        return $actor && ! $actor->api_only && $tenant && app(ApiTokenService::class)->isAdmin($actor, $tenant);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->contextTenantId = (int) Filament::getTenant()->getKey();
        $this->contextAccountId = (int) Filament::auth()->id();
    }

    private function context(): array
    {
        $actor = Filament::auth()->user();
        $tenant = Filament::getTenant();
        abort_unless($actor instanceof Account && $tenant instanceof Tenant, 403);
        abort_unless(isset($this->contextTenantId, $this->contextAccountId)
            && $this->contextTenantId === (int) $tenant->getKey()
            && $this->contextAccountId === (int) $actor->getKey(), 403);
        $actor = $actor->fresh();
        $tenant = $tenant->fresh();
        abort_unless($actor && ! $actor->api_only && $tenant && app(ApiTokenService::class)->isAdmin($actor, $tenant), 403);

        return [$actor, $tenant];
    }

    public function createIntegration(): void
    {
        $this->issuedToken = null;
        [$actor, $tenant] = $this->context();
        $this->name = trim($this->name);
        $this->validate([
            'name' => 'required|string|max:120',
            'accessProfileId' => ['required', 'integer', Rule::exists(AccessProfile::class, 'id')
                ->where('tenant_id', $tenant->id)->where('active', true)],
            'rateLimit' => 'required|integer|min:1|max:600',
            'integrationScopes' => 'required|array|min:1',
            'integrationScopes.*' => ['required', 'string', 'distinct', Rule::in(array_keys($this->scopeLabels()))],
        ]);
        app(ApiTokenService::class)->createIntegration($actor, $tenant, [
            'name' => trim($this->name), 'access_profile_id' => $this->accessProfileId,
            'rate_limit' => $this->rateLimit, 'scopes' => $this->integrationScopes,
        ]);
        $this->reset('name', 'accessProfileId', 'rateLimit', 'integrationScopes');
        Notification::make()->title('Integration erstellt')->success()->send();
    }

    public function issueToken(): void
    {
        $this->issuedToken = null;
        [$actor, $tenant] = $this->context();
        $this->tokenName = trim($this->tokenName);
        $this->validate([
            'integrationId' => 'required|integer|min:1',
            'tokenName' => 'required|string|max:120',
            'tokenScopes' => 'required|array|min:1',
            'tokenScopes.*' => ['required', 'string', 'distinct', Rule::in(array_keys($this->scopeLabels()))],
            'expiresAt' => 'required|date_format:Y-m-d\TH:i|after:now|before_or_equal:'.now()->addYear()->toIso8601String(),
        ]);
        $integration = app(ApiTokenService::class)->integrations($actor, $tenant)->firstWhere('id', $this->integrationId);
        abort_unless($integration && $integration->active, 403);
        if (array_diff($this->tokenScopes, $integration->scopes ?? [])) {
            throw ValidationException::withMessages(['tokenScopes' => 'Scopes liegen ausserhalb der Integration.']);
        }
        $result = app(ApiTokenService::class)->issueToken($actor, $tenant, $this->integrationId,
            trim($this->tokenName), $this->tokenScopes, CarbonImmutable::parse($this->expiresAt, config('app.timezone')));
        $this->issuedToken = $result['token'];
        $this->reset('tokenName', 'tokenScopes', 'expiresAt');
        Notification::make()->title('Token erstellt')->success()->send();
    }

    public function revokeToken(int $id): void
    {
        $this->issuedToken = null;
        [$actor, $tenant] = $this->context();
        app(ApiTokenService::class)->revokeToken($actor, $tenant, $id);
        Notification::make()->title('Token widerrufen')->success()->send();
    }

    public function disableIntegration(int $id): void
    {
        $this->issuedToken = null;
        [$actor, $tenant] = $this->context();
        app(ApiTokenService::class)->disableIntegration($actor, $tenant, $id);
        Notification::make()->title('Integration deaktiviert')->success()->send();
    }

    public function clearToken(): void
    {
        $this->issuedToken = null;
        $this->context();
    }

    public function updatedIntegrationId(): void
    {
        $this->tokenScopes = [];
        $this->issuedToken = null;
    }

    public function selectWebsiteScopes(): void
    {
        $this->context();
        $this->readOnly = true;
        $this->integrationScopes = array_values(array_intersect(array_keys(ApiTokenService::WEBSITE), array_keys($this->scopeLabels())));
        $this->tokenScopes = array_values(array_intersect($this->tokenScopes, array_keys($this->scopeLabels())));
        $this->issuedToken = null;
    }

    public function updatedReadOnly(): void
    {
        $allowed = array_keys($this->scopeLabels());
        $this->integrationScopes = array_values(array_intersect($this->integrationScopes, $allowed));
        $this->tokenScopes = array_values(array_intersect($this->tokenScopes, $allowed));
        $this->issuedToken = null;
    }

    private function scopeLabels(): array
    {
        return array_filter(app(ApiTokenService::class)->scopes(),
            fn ($scope) => ! $this->readOnly || str_ends_with($scope, ':read'), ARRAY_FILTER_USE_KEY);
    }

    protected function getViewData(): array
    {
        [$actor, $tenant] = $this->context();

        return [
            'integrations' => app(ApiTokenService::class)->integrations($actor, $tenant),
            'profiles' => AccessProfile::where('tenant_id', $tenant->id)->where('active', true)->orderBy('name')->pluck('name', 'id'),
            'scopeLabels' => $this->scopeLabels(),
            'allScopeLabels' => app(ApiTokenService::class)->scopes(),
            'issuedToken' => $this->issuedToken,
            'auditRows' => DB::connection('clubunio')->table('clubora.api_audit as audit')
                ->leftJoin('clubora.api_integrations as integration', function ($join) {
                    $join->on('integration.id', '=', 'audit.integration_id')
                        ->on('integration.tenant_id', '=', 'audit.tenant_id');
                })
                ->where('audit.tenant_id', $tenant->id)->orderByDesc('audit.id')->limit(50)
                ->get(['audit.created_at', 'audit.integration_id', 'integration.name as integration_name',
                    'audit.token_id', 'audit.event', 'audit.status', 'audit.request_id', 'audit.duration_ms']),
        ];
    }
}

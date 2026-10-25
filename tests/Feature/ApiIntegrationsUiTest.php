<?php

namespace Tests\Feature;

use App\ClubUnio\Api\ApiTokenService;
use App\ClubUnio\Models\AccessProfile;
use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\ApiIntegrations;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ApiIntegrationsUiTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private AccessProfile $profile;

    private object $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Transaction opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'api-ui-'.Str::random(12), 'name' => 'API UI fixture']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => 'api-ui-'.Str::random(16), 'display_name' => 'API UI fixture',
            'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id,
            'account_id' => $this->actor->id, 'role' => 'admin']);
        $this->profile = new AccessProfile;
        $this->profile->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'API profile fixture',
            'active' => true, 'permissions' => ['members.view'], 'revision' => 1])->save();
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);

        // A contract double keeps UI tests independent of API migrations and token storage.
        $this->service = new class
        {
            public array $writes = [];

            public $rows;

            public function isAdmin(Account $actor, Tenant $tenant): bool
            {
                return $actor->active && $actor->tenants()->whereKey($tenant->id)->wherePivot('role', 'admin')->exists();
            }

            public function scopes(): array
            {
                return ['members:read' => 'Mitglieder lesen', 'teams:read' => 'Teams lesen', 'members:create' => 'Mitglied erstellen']
                    + array_map(fn ($row) => $row[2], ApiTokenService::WEBSITE);
            }

            public function integrations(Account $actor, Tenant $tenant): Collection
            {
                return $this->rows;
            }

            public function createIntegration(Account $actor, Tenant $tenant, array $input): object
            {
                $this->writes[] = ['create', $actor->id, $tenant->id, $input];

                return (object) ['id' => 72];
            }

            public function issueToken(Account $actor, Tenant $tenant, int $id, string $name, array $scopes, CarbonInterface $expiresAt): array
            {
                $this->writes[] = ['issue', $actor->id, $tenant->id, $id, $name, $scopes, $expiresAt];

                return ['token' => 'synthetic-once-only-token', 'id' => 81];
            }

            public function revokeToken(Account $actor, Tenant $tenant, int $id): void
            {
                $this->writes[] = ['revoke', $actor->id, $tenant->id, $id];
            }

            public function disableIntegration(Account $actor, Tenant $tenant, int $id): void
            {
                $this->writes[] = ['disable', $actor->id, $tenant->id, $id];
            }
        };
        $this->service->rows = collect([(object) ['id' => 71, 'name' => 'Fixture Integration', 'active' => true,
            'access_profile_id' => $this->profile->id, 'rate_limit' => 60, 'scopes' => ['members:read'],
            'tokens' => collect([(object) ['id' => 80, 'name' => 'Existing token', 'abilities' => ['members:read'],
                'expires_at' => now()->addDay(), 'revoked_at' => null, 'last_used_at' => null,
                'token_hash' => 'existing-secret-must-never-render']])]]);
        $this->app->instance(ApiTokenService::class, $this->service);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function tokenForm()
    {
        return Livewire::test(ApiIntegrations::class)->set('integrationId', 71)->set('tokenName', 'New token')
            ->set('tokenScopes', ['members:read'])->set('expiresAt', now()->addDay()->format('Y-m-d\TH:i'));
    }

    public function test_admin_can_render_metadata_without_existing_secrets(): void
    {
        Livewire::test(ApiIntegrations::class)->assertSee('Fixture Integration')->assertSee('Existing token')
            ->assertSee('API profile fixture')->assertDontSee('existing-secret-must-never-render');
    }

    public function test_office_and_profile_roles_are_not_admin(): void
    {
        foreach (['office', 'profile'] as $role) {
            $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)->update([
                'role' => $role, 'access_profile_id' => $role === 'profile' ? $this->profile->id : null,
            ]);
            $this->assertFalse(ApiIntegrations::canAccess());
        }
        Livewire::test(ApiIntegrations::class)->assertForbidden();
    }

    public function test_inactive_account_and_missing_tenant_are_denied(): void
    {
        $this->actor->forceFill(['active' => false])->save();
        $this->assertFalse(ApiIntegrations::canAccess());
        Filament::setTenant(null);
        $this->assertFalse(ApiIntegrations::canAccess());
    }

    public function test_technical_account_is_denied_even_if_service_allows_admin_role(): void
    {
        $page = new ApiIntegrations;
        $page->mount();
        $this->actor->forceFill(['api_only' => true])->save();
        $this->assertFalse(ApiIntegrations::canAccess());
        $this->assertTrue($this->service->isAdmin($this->actor, $this->tenant));
        $this->expectException(HttpException::class);
        $page->disableIntegration(71);
    }

    public function test_audit_is_tenant_scoped_latest_fifty_metadata_only(): void
    {
        $rows = [];
        for ($i = 0; $i < 51; $i++) {
            $rows[] = ['tenant_id' => $this->tenant->id, 'integration_id' => null, 'actor_id' => null,
                'token_id' => 80, 'request_id' => (string) Str::uuid(), 'event' => 'ui.audit.'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'status' => 200, 'duration_ms' => 12, 'method' => 'POST', 'route' => 'private-route-not-displayed'];
        }
        $this->db->table('clubora.api_audit')->insert($rows);
        $other = Tenant::create(['slug' => 'audit-other-'.Str::random(12), 'name' => 'Other audit fixture']);
        $this->db->table('clubora.api_audit')->insert(['tenant_id' => $other->id,
            'request_id' => (string) Str::uuid(), 'event' => 'foreign-audit-never-display']);
        $page = new class extends ApiIntegrations
        {
            public function viewDataForTest(): array
            {
                return $this->getViewData();
            }
        };
        $page->mount();
        $audit = $page->viewDataForTest()['auditRows'];
        $this->assertCount(50, $audit);
        $this->assertSame('ui.audit.050', $audit->first()->event);
        $this->assertSame('ui.audit.001', $audit->last()->event);
        $this->assertSame(['created_at', 'integration_id', 'integration_name', 'token_id', 'event', 'status', 'request_id', 'duration_ms'],
            array_keys((array) $audit->first()));
        Livewire::test(ApiIntegrations::class)->assertSee('ui.audit.050')->assertDontSee('ui.audit.000')
            ->assertDontSee('foreign-audit-never-display')->assertDontSee('private-route-not-displayed');
    }

    public function test_create_passes_only_contract_fields_and_current_actor(): void
    {
        Livewire::test(ApiIntegrations::class)->set('name', ' Integration ')->set('accessProfileId', $this->profile->id)
            ->set('integrationScopes', ['members:read'])->call('createIntegration')->assertHasNoErrors()->assertSet('name', '');
        $this->assertSame(['create', $this->actor->id, $this->tenant->id, [
            'name' => 'Integration', 'access_profile_id' => $this->profile->id, 'rate_limit' => 60,
            'scopes' => ['members:read'],
        ]], $this->service->writes[0]);
    }

    public function test_foreign_and_inactive_profiles_are_rejected(): void
    {
        $other = Tenant::create(['slug' => 'api-other-'.Str::random(12), 'name' => 'Other fixture']);
        $component = Livewire::test(ApiIntegrations::class)->set('name', 'Integration')
            ->set('integrationScopes', ['members:read'])->set('accessProfileId', $this->profile->id);
        $this->profile->forceFill(['tenant_id' => $other->id])->save();
        $component->call('createIntegration')->assertHasErrors('accessProfileId');
        $this->profile->forceFill(['tenant_id' => $this->tenant->id, 'active' => false])->save();
        $component->call('createIntegration')->assertHasErrors('accessProfileId');
        $this->assertSame([], $this->service->writes);
    }

    public function test_unknown_scopes_and_invalid_rate_are_rejected(): void
    {
        Livewire::test(ApiIntegrations::class)->set('name', 'Integration')->set('accessProfileId', $this->profile->id)
            ->set('integrationScopes', ['unknown'])->set('rateLimit', 0)->call('createIntegration')
            ->assertHasErrors(['integrationScopes.0', 'rateLimit']);
        $this->assertSame([], $this->service->writes);
    }

    public function test_plaintext_is_one_response_only_and_not_in_snapshot(): void
    {
        $component = $this->tokenForm()->call('issueToken')->assertHasNoErrors()->assertSee('synthetic-once-only-token');
        $this->assertStringNotContainsString('synthetic-once-only-token', json_encode($component->snapshot));
        $component->call('$refresh')->assertDontSee('synthetic-once-only-token');
        $this->assertSame(['issue', $this->actor->id, $this->tenant->id, 71, 'New token', ['members:read']],
            array_slice($this->service->writes[0], 0, 6));
        $this->assertInstanceOf(CarbonInterface::class, $this->service->writes[0][6]);
    }

    public function test_explicit_clear_removes_plaintext(): void
    {
        $this->tokenForm()->call('issueToken')->assertSee('synthetic-once-only-token')
            ->call('clearToken')->assertDontSee('synthetic-once-only-token');
    }

    public function test_scope_ceiling_and_expired_date_are_rejected(): void
    {
        $component = $this->tokenForm()->set('tokenScopes', ['teams:read'])->call('issueToken')->assertHasErrors('tokenScopes');
        $component->set('tokenScopes', ['members:read'])->set('expiresAt', now()->subDay()->format('Y-m-d\TH:i'))
            ->call('issueToken')->assertHasErrors('expiresAt');
        $component->set('expiresAt', now()->addYears(2)->format('Y-m-d\TH:i'))->call('issueToken')->assertHasErrors('expiresAt');
        $this->assertSame([], $this->service->writes);
    }

    public function test_foreign_or_disabled_integration_cannot_issue(): void
    {
        $this->tokenForm()->set('integrationId', 999)->set('tokenScopes', ['members:read'])->call('issueToken')->assertForbidden();
        $this->service->rows[0]->active = false;
        $this->tokenForm()->call('issueToken')->assertForbidden();
        $this->assertSame([], $this->service->writes);
    }

    public function test_admin_revocation_blocks_every_write_on_already_mounted_page(): void
    {
        foreach (['createIntegration' => [], 'issueToken' => [], 'revokeToken' => [80], 'disableIntegration' => [71]] as $method => $args) {
            $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'admin']);
            $component = Livewire::test(ApiIntegrations::class);
            $this->db->table('clubora.tenant_accounts')->where('account_id', $this->actor->id)->update(['role' => 'office']);
            $component->call($method, ...$args)->assertForbidden();
        }
        $this->assertSame([], $this->service->writes);
    }

    public function test_revoke_and_disable_delegate_with_current_identity(): void
    {
        Livewire::test(ApiIntegrations::class)->call('revokeToken', 80)->call('disableIntegration', 71)->assertHasNoErrors();
        $this->assertSame([
            ['revoke', $this->actor->id, $this->tenant->id, 80],
            ['disable', $this->actor->id, $this->tenant->id, 71],
        ], $this->service->writes);
    }

    public function test_mounted_page_does_not_follow_a_tenant_switch(): void
    {
        $page = new ApiIntegrations;
        $page->mount();
        $other = Tenant::create(['slug' => 'api-switch-'.Str::random(12), 'name' => 'Switch fixture']);
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $other->id,
            'account_id' => $this->actor->id, 'role' => 'admin']);
        Filament::setTenant($other);
        $this->expectException(HttpException::class);
        $page->disableIntegration(71);
    }

    public function test_website_preset_and_read_only_selection(): void
    {
        Livewire::test(ApiIntegrations::class)->assertSet('readOnly', true)
            ->assertDontSee('Mitglied erstellen')->assertSee('API-Dokumentation')->call('selectWebsiteScopes')
            ->assertSet('integrationScopes', array_keys(ApiTokenService::WEBSITE))
            ->set('readOnly', false)->assertSee('Mitglied erstellen')
            ->set('integrationScopes', ['members:read', 'members:create'])->set('readOnly', true)
            ->assertSet('integrationScopes', ['members:read']);
        $this->assertSame([], $this->service->writes);
    }

    public function test_scope_groups_expand_selected_modules_and_preserve_checkbox_bindings(): void
    {
        $component = Livewire::test(ApiIntegrations::class)->assertSee('Bitte wählen')
            ->assertSee('Keine API-Audit-Einträge.')->assertDontSee('Bitte waehlen');
        $group = function (string $key) use ($component): \DOMElement {
            $document = new \DOMDocument;
            @$document->loadHTML($component->html());
            foreach ($document->getElementsByTagName('details') as $details) {
                if ($details->getAttribute('wire:key') === $key) {
                    return $details;
                }
            }
            $this->fail('Missing scope group: '.$key);
        };
        $this->assertFalse($group('api-integration-scopes-members')->hasAttribute('open'));
        $component->set('integrationScopes', ['members:read'])->set('readOnly', false);
        $this->assertTrue($group('api-integration-scopes-members')->hasAttribute('open'));
        $this->assertFalse($group('api-integration-scopes-teams')->hasAttribute('open'));
        $this->assertSame(2, $group('api-integration-scopes-members')->getElementsByTagName('input')->length);
        $component->set('integrationId', 71)->set('tokenScopes', ['members:read']);
        $tokenGroup = $group('api-token-scopes-members');
        $this->assertTrue($tokenGroup->hasAttribute('open'));
        $this->assertSame('tokenScopes', $tokenGroup->getElementsByTagName('input')->item(0)->getAttribute('wire:model'));
        $component->call('selectWebsiteScopes');
        $this->assertTrue($group('api-integration-scopes-website')->hasAttribute('open'));
        $this->assertFalse($group('api-integration-scopes-members')->hasAttribute('open'));
    }

    public function test_mounted_page_does_not_impersonate_another_account(): void
    {
        $page = new ApiIntegrations;
        $page->mount();
        $other = new Account;
        $other->forceFill(['username' => 'api-other-'.Str::random(16), 'display_name' => 'Other fixture',
            'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id,
            'account_id' => $other->id, 'role' => 'admin']);
        $this->actingAs($other, 'clubunio');
        $this->expectException(HttpException::class);
        $page->revokeToken(80);
    }
}

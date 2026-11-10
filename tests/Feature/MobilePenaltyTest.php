<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\MobilePenaltyRules;
use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{DB, Http, Mail};
use Illuminate\Support\Str;
use Tests\TestCase;

class MobilePenaltyTest extends TestCase
{
    private $db; private $grammar;
    private Tenant $tenant; private Tenant $foreign;
    private array $actors = [], $tokens = [];
    private string $base;
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Opt-in isolated rolled-back PostgreSQL required');
        config(['clubunio-mobile.enabled' => true, 'clubunio-mobile.native_penalty_enabled' => true,
            'clubunio-mobile-push.enabled' => false, 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4]);
        Http::preventStrayRequests(); Mail::fake(); $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction(); $this->db->statement("SET LOCAL application_name TO 'clubunio-mobile-penalty-test'");
        $this->tenant = Tenant::create(['slug' => 'penalty-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic challenge']);
        $this->foreign = Tenant::create(['slug' => 'penalty-foreign-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic foreign']);
        foreach ([$this->tenant, $this->foreign] as $tenant) $this->db->table('clubunio.mobile_module_options')->insert(['tenant_id' => $tenant->id, 'key' => 'Penalty', 'name' => 'Vereinschallenge']);
        foreach (['admin', 'member', 'other'] as $role) {
            $account = new Account; $account->forceFill(['username' => 'penalty-'.bin2hex(random_bytes(8)), 'display_name' => 'Synthetic '.$role,
                'active' => true, 'api_only' => false, 'password' => bcrypt('fixture')])->save();
            $this->membership($this->tenant, $account, $role === 'admin' ? 'admin' : 'member');
            $this->actors[$role] = $account;
            $this->tokens[$role] = $this->postJson('/api/mobile/v1/session', ['username' => $account->username, 'password' => 'fixture', 'device_name' => 'Synthetic penalty'])->assertOk()->json('data.token');
        }
        $this->membership($this->foreign, $this->actors['member'], 'member');
        // Migration600 is rehearsed only against TEMP parents; no FK relation locks on real DEV parents.
        $this->db->statement('CREATE TEMP TABLE penalty_parent_tenants(id bigint PRIMARY KEY)');
        $this->db->statement('CREATE TEMP TABLE penalty_parent_accounts(tenant_id bigint,account_id bigint,PRIMARY KEY(tenant_id,account_id))');
        foreach ([$this->tenant, $this->foreign] as $tenant) $this->db->table('pg_temp.penalty_parent_tenants')->insert(['id' => $tenant->id]);
        foreach ($this->actors as $actor) $this->db->table('pg_temp.penalty_parent_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id]);
        $this->db->table('pg_temp.penalty_parent_accounts')->insert(['tenant_id' => $this->foreign->id, 'account_id' => $this->actors['member']->id]);
        $queries = $this->db->pretend(fn () => (require database_path('clubunio/2026_11_04_000600_mobile_penalty_challenge.php'))->up());
        $sql = str_replace(['CREATE TABLE clubunio.mobile_penalty_', 'clubunio.mobile_penalty_', 'REFERENCES clubunio.tenants', 'REFERENCES clubunio.tenant_accounts'],
            ['CREATE TEMP TABLE mobile_penalty_', 'pg_temp.mobile_penalty_', 'REFERENCES pg_temp.penalty_parent_tenants', 'REFERENCES pg_temp.penalty_parent_accounts'], $queries[0]['query']);
        $this->db->unprepared($sql);
        $this->grammar = $this->db->getQueryGrammar();
        $this->db->setQueryGrammar(new class($this->db) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function wrapTable($table, $prefix = null) { return parent::wrapTable(str_replace('clubunio.mobile_penalty_', 'pg_temp.mobile_penalty_', $table), $prefix); }
            public function wrap($value, $prefixAlias = false) { return parent::wrap(is_string($value) ? str_replace('clubunio.mobile_penalty_', 'pg_temp.mobile_penalty_', $value) : $value, $prefixAlias); }
        });
        $this->base = '/api/mobile/v1/clubs/'.$this->tenant->id.'/penalty';
        $this->withToken($this->tokens['member']);
    }
    protected function tearDown(): void
    {
        if (isset($this->db)) { if ($this->grammar) $this->db->setQueryGrammar($this->grammar); $this->db->rollBack(); }
        parent::tearDown();
    }
    private function membership(Tenant $tenant, Account $actor, string $role): void
    {
        $this->db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => $role, 'portal_enabled' => true]);
        $member = $this->db->table('clubunio.members')->insertGetId(['tenant_id' => $tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Player', 'birth_date' => '1980-01-01']);
        $this->db->table('clubunio.account_member_links')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'synthetic-test']);
    }
    private function start(string $mode = 'shoot', ?string $key = null): array
    {
        return $this->postJson($this->base.'/sessions', ['mode' => $mode, 'command_key' => $key ?? (string) Str::uuid()])->assertOk()->json('data');
    }
    private function age(string $id, int $seconds = 10): void
    {
        $this->db->table('clubunio.mobile_penalty_sessions')->where('tenant_id', $this->tenant->id)->where('id', $id)
            ->update(['created_at' => now()->subSeconds($seconds)->toIso8601String()]);
    }
    public function test_original_scoring_both_modes_exact_finish_replay_and_best_only(): void
    {
        foreach (['shoot', 'save'] as $mode) {
            $key = (string) Str::uuid(); $round = $this->start($mode, $key);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $round['sessionId']);
            $this->assertCount(10, $round['opponents']);
            $this->assertSame($round['sessionId'], $this->start($mode, $key)['sessionId']);
            $this->postJson($this->base.'/sessions', ['mode' => $mode === 'shoot' ? 'save' : 'shoot', 'command_key' => $key])->assertConflict();
            $choices = array_map(fn ($lane) => $mode === 'shoot' ? ($lane + 1) % 3 : $lane, $round['opponents']);
            $this->postJson($this->base.'/sessions/'.$round['sessionId'].'/finish', ['choices' => $choices])->assertConflict();
            $this->age($round['sessionId']);
            $this->postJson($this->base.'/sessions/'.$round['sessionId'].'/finish', ['choices' => $choices])->assertOk()->assertJsonPath('data.score', 2100)->assertJsonPath('data.replayed', false);
            $this->postJson($this->base.'/sessions/'.$round['sessionId'].'/finish', ['choices' => $choices])->assertOk()->assertJsonPath('data.score', 2100)->assertJsonPath('data.replayed', true);
            $this->postJson($this->base.'/sessions/'.$round['sessionId'].'/finish', ['choices' => array_fill(0, 10, -1)])->assertConflict();
            $weaker = $this->start($mode); $this->age($weaker['sessionId']);
            $this->postJson($this->base.'/sessions/'.$weaker['sessionId'].'/finish', ['choices' => array_fill(0, 10, -1)])->assertOk()->assertJsonPath('data.score', 0);
            $this->getJson($this->base.'/leaderboard?mode='.$mode.'&start=0&recperpage=20')->assertOk()
                ->assertJsonPath('data.totalRecordCount', 1)->assertJsonPath('data.entries.0.Rank', 1)->assertJsonPath('data.entries.0.Score', 2100)->assertJsonPath('data.entries.0.IsMe', 1)
                ->assertJsonPath('data.entries.0.DisplayName', 'Synthetic P.')->assertJsonMissingPath('data.entries.0.account_id');
        }
    }
    public function test_strict_choice_score_expiry_scope_and_rate_bounds(): void
    {
        $round = $this->start(); $finish = $this->base.'/sessions/'.$round['sessionId'].'/finish'; $this->age($round['sessionId']);
        foreach ([['choices' => [0]], ['choices' => array_fill(0, 10, '0')], ['choices' => array_fill(0, 10, 3)],
            ['choices' => array_fill(0, 10, 0), 'score' => 999999], ['choices' => array_fill(0, 10, 0), 'account_id' => $this->actors['admin']->id]] as $invalid) $this->postJson($finish, $invalid)->assertUnprocessable();
        $this->withToken($this->tokens['other'])->postJson($finish, ['choices' => array_fill(0, 10, 0)])->assertNotFound();
        $this->withToken($this->tokens['member']);
        $foreign = str_replace('/clubs/'.$this->tenant->id.'/', '/clubs/'.$this->foreign->id.'/', $finish);
        $this->postJson($foreign, ['choices' => array_fill(0, 10, 0)])->assertNotFound();
        $this->db->table('clubunio.mobile_penalty_sessions')->where('id', $round['sessionId'])->update(['expires_at' => now()->subSecond()->toIso8601String()]);
        $this->postJson($finish, ['choices' => array_fill(0, 10, 0)])->assertConflict();
        for ($index = 1; $index < 30; $index++) $this->start();
        $this->postJson($this->base.'/sessions', ['mode' => 'shoot', 'command_key' => (string) Str::uuid()])->assertStatus(429);
        $this->getJson($this->base.'/leaderboard?mode=shoot&recperpage=51')->assertUnprocessable();
        $this->getJson($this->base.'/leaderboard?mode=bad')->assertUnprocessable();
        Http::assertNothingSent();
    }
    public function test_reset_preserves_original_confirmation_generation_and_durable_encrypted_notice(): void
    {
        $round = $this->start(); $this->age($round['sessionId']);
        $this->postJson($this->base.'/sessions/'.$round['sessionId'].'/finish', ['choices' => array_fill(0, 10, -1)])->assertOk();
        $body = ['challenge' => 1, 'confirm' => true, 'notification' => ['title' => 'Synthetic new round', 'body' => 'Synthetic invite only']];
        $this->postJson($this->base.'/leaderboard/reset', $body)->assertForbidden();
        $this->withToken($this->tokens['admin']);
        $this->postJson($this->base.'/leaderboard/reset', array_replace($body, ['confirm' => false]))->assertUnprocessable();
        $reset = $this->postJson($this->base.'/leaderboard/reset', $body)->assertOk()->assertJsonPath('data.challenge', 2)->assertJsonPath('data.pushStatus', 'queued')->json('data.resetId');
        $this->getJson($this->base.'/leaderboard?mode=shoot')->assertOk()->assertJsonPath('data.challenge', 2)->assertJsonPath('data.totalRecordCount', 0);
        $this->postJson($this->base.'/leaderboard/reset', $body)->assertConflict();
        $this->withToken($this->tokens['member'])->postJson($this->base.'/sessions/'.$round['sessionId'].'/finish', ['choices' => array_fill(0, 10, -1)])->assertNotFound();
        $notice = app(\App\ClubUnio\Mobile\MobilePenaltyAccess::class)->notice($this->actors['member'], $this->tenant, $reset);
        $this->assertStringNotContainsString('Synthetic', $notice->title_encrypted);
        $this->assertSame('Synthetic new round', decrypt($notice->title_encrypted, false));
        $this->assertSame(1, $this->db->table('clubunio.mobile_penalty_resets')->where('tenant_id', $this->tenant->id)->count());
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->db->transaction(fn () => $this->db->table('clubunio.mobile_penalty_best')->insert(['tenant_id' => $this->foreign->id,
            'account_id' => $this->actors['other']->id, 'mode' => 'shoot', 'display_name' => 'Synthetic', 'score' => 0]));
    }
    public function test_readiness_module_option_revoked_entry_and_personal_profile_safety(): void
    {
        $this->getJson($this->base.'/options')->assertOk()->assertJsonPath('data.tenantId', (int) $this->tenant->id);
        $menuUrl = '/api/mobile/v1/clubs/'.$this->tenant->id.'/modules';
        $old = $this->getJson($menuUrl)->assertOk()->json('data.modules');
        $this->assertFalse(collect($old)->firstWhere('key', 'Penalty')['available']);
        $new = $this->withHeader('X-ClubUnio-Mobile-Features', 'penalty-v1')->getJson($menuUrl)->assertOk()->json('data.modules');
        $this->assertTrue(collect($new)->firstWhere('key', 'Penalty')['native']);
        config(['clubunio-mobile.native_penalty_enabled' => false]);
        $this->getJson($this->base.'/options')->assertStatus(503);
        $this->assertNull(collect($this->getJson($menuUrl)->assertOk()->json('data.modules'))->firstWhere('key', 'Penalty'));
        config(['clubunio-mobile.native_penalty_enabled' => true]);
        $this->db->table('clubunio.mobile_module_options')->where('tenant_id', $this->tenant->id)->where('key', 'Penalty')->update(['active' => false]);
        $this->getJson($this->base.'/options')->assertForbidden();
        $this->db->table('clubunio.mobile_module_options')->where('tenant_id', $this->tenant->id)->where('key', 'Penalty')->update(['active' => true]);
        $this->db->table('clubunio.account_member_links')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actors['member']->id)->update(['state' => 'revoked']);
        $this->getJson($this->base.'/leaderboard?mode=shoot')->assertForbidden();
        $profile = $this->db->table('clubunio.access_profiles')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Synthetic profile', 'permissions' => '[]']);
        $this->db->table('clubunio.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actors['other']->id)->update(['role' => 'profile', 'access_profile_id' => $profile, 'portal_enabled' => false]);
        $this->withToken($this->tokens['other'])->getJson($this->base.'/options')->assertForbidden();
    }
}

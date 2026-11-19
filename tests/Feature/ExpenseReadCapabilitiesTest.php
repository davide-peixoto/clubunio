<?php
namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Expense, Tenant};
use App\ClubUnio\Services\ExpenseService;
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail};
use Tests\TestCase;

final class ExpenseReadCapabilitiesTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private int $member, $otherMember, $season, $team, $otherTeam, $bank, $round;
    private array $statuses, $actors, $profiles = [];
    protected function setUp(): void {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Explicit synthetic PostgreSQL rollback required');
        $this->db = DB::connection('clubunio');
        self::assertSame('pgsql', $this->db->getDriverName());
        self::assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction(); Http::preventStrayRequests(); Mail::fake();
        request()->attributes->set('mobile.season_scope', true);
        $this->tenant = Tenant::create(['slug' => 'expense-read-'.bin2hex(random_bytes(7)), 'name' => 'Synthetic']);
        $this->member = $this->insert('members', ['first_name' => 'Synthetic self']);
        $this->otherMember = $this->insert('members', ['first_name' => 'Synthetic other']);
        $this->season = $this->insert('seasons', ['name' => 'Synthetic current']);
        $this->insert('tenant_settings', ['area' => 'general', 'key' => 'SaisonID', 'label' => 'Synthetic', 'value_type' => 'integer', 'encrypted_value' => Crypt::encryptString((string) $this->season)]);
        $this->team = $this->insert('team_seasons', ['team_id' => $this->insert('teams', ['name' => 'Synthetic assigned']), 'season_id' => $this->season, 'label' => 'Synthetic assigned']);
        $this->otherTeam = $this->insert('team_seasons', ['team_id' => $this->insert('teams', ['name' => 'Synthetic other']), 'season_id' => $this->season, 'label' => 'Synthetic other']);
        $this->bank = $this->insert('member_bank_accounts', ['member_id' => $this->member, 'iban_encrypted' => Crypt::encryptString('CH9300762011623852957')]);
        $this->round = $this->insert('expense_rounds', ['code' => 'fixture', 'name' => 'Synthetic']);
        foreach (['O','F','Z','R','B'] as $code) $this->statuses[$code] = $this->insert('expense_statuses', ['code' => $code, 'name' => $code]);
        foreach (['admin','office','trainer','technical_lead','member','profile_none','profile_read','profile_edit'] as $role) {
            $actor = new Account;
            $actor->forceFill(['username' => 'expense-read-'.bin2hex(random_bytes(7)), 'display_name' => 'Synthetic', 'password' => bcrypt('fixture'), 'active' => true])->save();
            $link = ['account_id' => $actor->id, 'role' => str_starts_with($role, 'profile_') ? 'profile' : $role, 'portal_enabled' => true];
            if ($link['role'] === 'profile') {
                $link['portal_enabled'] = false;
                $permissions = match ($role) { 'profile_read' => ['expenses.view'], 'profile_edit' => ['expenses.view','expenses.create','expenses.update','expenses.send','expenses.cancel'], default => [] };
                $this->profiles[$role] = $link['access_profile_id'] = $this->insert('access_profiles', ['name' => 'Synthetic '.$role, 'permissions' => json_encode($permissions)]);
            }
            $this->insert('tenant_accounts', $link);
            $this->insert('account_member_links', ['account_id' => $actor->id, 'member_id' => $this->member, 'kind' => 'self', 'state' => 'approved', 'source' => 'synthetic']);
            $this->actors[$role] = $actor;
        }
        $this->insert('technical_leads', ['member_id' => $this->member, 'team_season_id' => $this->team]);
        $this->insert('team_staff', ['member_id' => $this->member, 'team_season_id' => $this->team]);
    }
    protected function tearDown(): void {
        if (isset($this->db)) { $this->db->disableQueryLog(); while ($this->db->transactionLevel()) $this->db->rollBack(); }
        parent::tearDown();
    }
    private function insert(string $table, array $values): int { return $this->db->table('clubunio.'.$table)->insertGetId($values + ['tenant_id' => $this->tenant->id]); }
    private function expense(string $status = 'O', ?int $member = null, ?int $team = null, array $extra = []): Expense {
        $id = $this->insert('expenses', $extra + ['member_id' => $member ?? $this->member, 'team_season_id' => $team ?? $this->team,
            'season_id' => $this->season, 'round_id' => $this->round, 'bank_account_id' => $this->bank, 'status_id' => $this->statuses[$status]]);
        return Expense::withoutGlobalScopes()->with('status')->findOrFail($id);
    }
    private function parity(Account $actor, array $rows): array {
        $service = app(ExpenseService::class);
        $actual = $service->readCapabilities($actor, $this->tenant, $rows);
        foreach ($rows as $row) self::assertSame([
            'CanManagePositions' => $service->editable($actor, $this->tenant, $row),
            'CanAddPosition' => $service->editable($actor, $this->tenant, $row, 'create'),
            'CanDeletePosition' => $service->editable($actor, $this->tenant, $row, 'deactivate'),
            'AllowedStatusCodes' => array_values(array_diff($service->targets($actor, $this->tenant, $row), ['B'])),
        ], $actual[(int) $row->id], 'Legacy parity for role/status/object');
        return $actual;
    }
    public function test_every_role_status_and_own_or_responsible_team_matches_existing_policy(): void {
        $rows = [];
        foreach (array_keys($this->statuses) as $status) foreach ([$this->member,$this->otherMember] as $member) foreach ([$this->team,$this->otherTeam] as $team) $rows[] = $this->expense($status,$member,$team);
        foreach ($this->actors as $actor) $this->parity($actor, $rows);
        Http::assertNothingSent(); Mail::assertNothingSent();
    }
    public function test_inactive_foreign_and_other_season_are_closed_without_widening_scope(): void {
        $old = $this->insert('seasons', ['name' => 'Synthetic previous']);
        $rows = [$this->expense('O', extra: ['active' => false]), $this->expense('O', extra: ['season_id' => $old])];
        $foreign = Tenant::create(['slug' => 'expense-read-foreign-'.bin2hex(random_bytes(5)), 'name' => 'Synthetic foreign']);
        $insert = fn ($table, $values) => $this->db->table('clubunio.'.$table)->insertGetId($values + ['tenant_id' => $foreign->id]);
        $member = $insert('members', ['first_name' => 'Synthetic foreign']);
        $season = $insert('seasons', ['name' => 'Synthetic foreign']);
        $team = $insert('team_seasons', ['team_id' => $insert('teams', ['name' => 'Synthetic foreign']), 'season_id' => $season, 'label' => 'Synthetic foreign']);
        $id = $insert('expenses', ['member_id' => $member, 'team_season_id' => $team, 'season_id' => $season,
            'round_id' => $insert('expense_rounds', ['code' => 'fixture', 'name' => 'Synthetic']),
            'bank_account_id' => $insert('member_bank_accounts', ['member_id' => $member, 'iban_encrypted' => Crypt::encryptString('CH9300762011623852957')]),
            'status_id' => $insert('expense_statuses', ['code' => 'O', 'name' => 'Synthetic'])]);
        $rows[] = Expense::withoutGlobalScopes()->with('status')->findOrFail($id);
        foreach ($this->actors as $actor) {
            $result = app(ExpenseService::class)->readCapabilities($actor, $this->tenant, $rows);
            foreach ($result as $caps) self::assertSame(['CanManagePositions'=>false,'CanAddPosition'=>false,'CanDeletePosition'=>false,'AllowedStatusCodes'=>[]], $caps);
            $this->parity($actor, $rows);
        }
    }
    public function test_second_invocation_observes_permission_link_role_and_account_revocation(): void {
        $service = app(ExpenseService::class); $row = $this->expense();
        $actor = $this->actors['profile_edit'];
        self::assertTrue($this->parity($actor, [$row])[$row->id]['CanAddPosition']);
        $this->db->table('clubunio.access_profiles')->where('id', $this->profiles['profile_edit'])->update(['permissions' => '[]']);
        self::assertFalse($this->parity($actor, [$row])[$row->id]['CanAddPosition']);
        $actor = $this->actors['member'];
        self::assertTrue($this->parity($actor, [$row])[$row->id]['CanAddPosition']);
        $this->db->table('clubunio.account_member_links')->where('tenant_id', $this->tenant->id)->where('account_id', $actor->id)->delete();
        self::assertFalse($this->parity($actor, [$row])[$row->id]['CanAddPosition']);
        $actor = $this->actors['office'];
        self::assertTrue($service->readCapabilities($actor, $this->tenant, [$row])[$row->id]['CanAddPosition']);
        $this->db->table('clubunio.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $actor->id)->update(['role' => 'profile', 'access_profile_id' => $this->profiles['profile_none'], 'portal_enabled' => false]);
        self::assertFalse($this->parity($actor, [$row])[$row->id]['CanAddPosition']);
        $actor = $this->actors['admin'];
        self::assertTrue($service->readCapabilities($actor, $this->tenant, [$row])[$row->id]['CanAddPosition']);
        $actor->forceFill(['active' => false])->save();
        self::assertFalse($this->parity($actor, [$row])[$row->id]['CanAddPosition']);
    }
    public function test_query_count_does_not_grow_per_receipt_and_is_less_than_legacy_projection(): void {
        $rows = []; for ($i=0; $i<20; $i++) $rows[] = $this->expense();
        $service = app(ExpenseService::class); $actor = $this->actors['trainer'];
        // Existing request-local season resolution is independent of page size.
        app(\App\ClubUnio\Mobile\MobileSeasonScope::class)->current($this->tenant);
        $count = function (callable $read): int { $this->db->flushQueryLog(); $this->db->enableQueryLog(); $read(); $count = count($this->db->getQueryLog()); $this->db->disableQueryLog(); return $count; };
        $one = $count(fn () => $service->readCapabilities($actor, $this->tenant, [$rows[0]]));
        $batch = $count(fn () => $service->readCapabilities($actor, $this->tenant, $rows));
        self::assertSame($one, $batch);
        $legacy = $count(function () use ($service,$actor,$rows) { foreach ($rows as $row) { foreach (['update','create','deactivate'] as $function) $service->editable($actor,$this->tenant,$row,$function); $service->targets($actor,$this->tenant,$row); } });
        self::assertLessThan($legacy, $batch);
        self::assertGreaterThan($batch * 5, $legacy);
    }
}

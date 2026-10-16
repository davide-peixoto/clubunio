<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\ExpenseFields;
use App\ClubUnio\Resources\MemberResource;
use App\ClubUnio\Services\MemberService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FormLookupPerformanceTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private array $input;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Transactional DB opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'form-perf-'.Str::random(12), 'name' => 'Synthetic']);
        $this->actor = new Account;
        $this->actor->forceFill(['username' => Str::random(20), 'display_name' => 'Synthetic', 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->db->table('clubora.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id, 'role' => 'office']);
        $country = $this->insert('countries', ['code' => 'CH', 'name' => 'Synthetic']);
        $this->input = ['first_name' => 'Synthetic', 'last_name' => 'Member', 'company' => null, 'email' => 'fixture@example.invalid',
            'status_id' => $this->insert('member_statuses', ['code' => 'M', 'name' => 'Member', 'restores_membership' => true]),
            'type_id' => $this->insert('member_types', ['code' => 'A', 'name' => 'Active']),
            'gender_id' => $this->insert('genders', ['code' => 'N', 'name' => 'Neutral']),
            'country_id' => $country, 'nationality_id' => $country,
            'billing_recipient_id' => $this->insert('billing_recipients', ['code' => 'E', 'name' => 'Self', 'email_field' => 'email'])];
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->disableQueryLog();
            if ($this->db->transactionLevel()) {
                $this->db->rollBack();
            }
        }
        parent::tearDown();
    }

    private function insert(string $table, array $values): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId(['tenant_id' => $this->tenant->id] + $values);
    }

    private function invalid(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail('Invalid lookup accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_save_reads_each_lookup_table_once_and_keeps_audit_and_noop_semantics(): void
    {
        $this->db->enableQueryLog();
        $member = app(MemberService::class)->create($this->actor, $this->tenant, $this->input);
        $queries = $this->db->getQueryLog();
        foreach (['countries', 'member_statuses', 'member_types', 'genders', 'billing_recipients'] as $table) {
            $reads = array_filter($queries, fn ($q) => str_contains($q['query'], 'from "clubora"."'.$table.'"'));
            $this->assertCount(1, $reads, $table);
        }
        $this->assertSame($this->input['country_id'], $member->country_id);
        $this->assertSame(1, $this->db->table('clubora.object_changes')->where('tenant_id', $this->tenant->id)->count());
        app(MemberService::class)->update($this->actor, $this->tenant, $member->id, $this->input + ['_version' => MemberService::version($member)]);
        $this->assertSame(1, $this->db->table('clubora.object_changes')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_inactive_country_is_only_retained_for_its_own_existing_field(): void
    {
        $member = app(MemberService::class)->create($this->actor, $this->tenant, $this->input);
        $inactive = $this->insert('countries', ['code' => 'X', 'name' => 'Old', 'active' => false]);
        $this->db->table('clubora.members')->where('id', $member->id)->update(['country_id' => $inactive]);
        $member->refresh();
        $input = array_replace($this->input, ['country_id' => $inactive, '_version' => MemberService::version($member)]);
        app(MemberService::class)->update($this->actor, $this->tenant, $member->id, $input);
        $this->invalid(fn () => app(MemberService::class)->update($this->actor, $this->tenant, $member->id,
            array_replace($input, ['nationality_id' => $inactive])), 'nationality_id');
        $this->invalid(fn () => app(MemberService::class)->create($this->actor, $this->tenant,
            array_replace($this->input, ['country_id' => $inactive])), 'country_id');
    }

    public function test_subsequent_save_rechecks_lookup_state_and_recipient_email_and_role(): void
    {
        $service = app(MemberService::class);
        $this->invalid(fn () => $service->create($this->actor, $this->tenant, array_replace($this->input, ['email' => null])), 'email');
        $service->create($this->actor, $this->tenant, $this->input);
        $this->db->table('clubora.countries')->where('id', $this->input['country_id'])->update(['active' => false]);
        $this->invalid(fn () => $service->create($this->actor, $this->tenant, $this->input), 'country_id');
        $this->db->table('clubora.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['role' => 'trainer']);
        try {
            $service->create($this->actor, $this->tenant, $this->input);
            $this->fail('Revoked role accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_member_lookup_has_bounded_projection_and_tenant_archive_filters(): void
    {
        $own = app(MemberService::class)->create($this->actor, $this->tenant, $this->input);
        $archived = new Member;
        $archived->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'archived_at' => now()])->save();
        $other = Tenant::create(['slug' => 'foreign-'.Str::random(12), 'name' => 'Foreign']);
        $foreign = new Member;
        $foreign->forceFill(['tenant_id' => $other->id, 'first_name' => 'Synthetic'])->save();
        $this->db->enableQueryLog();
        $results = MemberResource::memberSelect('member_id')->getSearchResults('Synthetic');
        $this->assertSame([$own->id => $own->display_name], $results);
        $sql = $this->db->getQueryLog()[0]['query'];
        $this->assertStringNotContainsString('select *', $sql);
        $this->assertStringContainsString('limit 50', $sql);
        $this->assertStringNotContainsString('ahv_encrypted', $sql);
        $this->assertStringNotContainsString('legacy_photo', $sql);
    }

    public function test_foreign_missing_and_malformed_lookup_values_are_rejected(): void
    {
        $other = Tenant::create(['slug' => 'other-'.Str::random(12), 'name' => 'Foreign']);
        $foreign = $this->db->table('clubora.countries')->insertGetId(['tenant_id' => $other->id, 'code' => 'X', 'name' => 'Foreign']);
        foreach ([$foreign, -1, 'invalid', ['id' => $this->input['country_id']], '1.5'] as $invalid) {
            $this->invalid(fn () => app(MemberService::class)->create($this->actor, $this->tenant,
                array_replace($this->input, ['nationality_id' => $invalid])), 'nationality_id');
        }
    }

    public function test_bank_choices_skip_empty_member_and_keep_masking_and_fresh_filters(): void
    {
        $member = app(MemberService::class)->create($this->actor, $this->tenant, $this->input);
        $bank = $this->insert('banks', ['name' => 'Synthetic bank']);
        $id = $this->insert('member_bank_accounts', ['member_id' => $member->id, 'bank_id' => $bank, 'iban_encrypted' => Crypt::encryptString('CH00000000001234')]);
        $this->db->enableQueryLog();
        $this->db->flushQueryLog();
        $this->assertSame([], ExpenseFields::bankOptions(null));
        $this->assertCount(0, $this->db->getQueryLog());
        $this->assertSame([$id => 'Synthetic bank •••• 1234'], ExpenseFields::bankOptions($member->id));
        $this->assertCount(2, $this->db->getQueryLog());
        $this->db->table('clubora.member_bank_accounts')->where('id', $id)->update(['active' => false]);
        $this->assertSame([], ExpenseFields::bankOptions($member->id));
    }
}

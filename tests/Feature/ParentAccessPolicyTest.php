<?php

namespace Tests\Feature;

use App\ClubUnio\Services\ParentAccessPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ParentAccessPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 12:00', 'Europe/Zurich'));
        config(['clubunio-parent-access.enabled' => true, 'database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('clubunio');
        DB::connection('clubunio')->statement("ATTACH DATABASE ':memory:' AS clubora");
        DB::connection('clubunio')->statement('CREATE TABLE clubora.account_parent_email_proofs(account_id integer primary key,email text,verified_at text)');
    }

    protected function tearDown(): void { CarbonImmutable::setTestNow(); DB::purge('clubunio'); parent::tearDown(); }

    private function link(array $values = []): object
    {
        return (object) array_replace(['account_id' => 41, 'kind' => 'parent', 'state' => 'approved', 'source' => 'backoffice_review'], $values);
    }

    public function test_adulthood_boundary_requires_explicit_consent_and_revocation_wins(): void
    {
        $policy = new ParentAccessPolicy;
        $child = (object) ['birth_date' => '2008-11-24'];
        $adult = (object) ['birth_date' => '2008-11-23'];
        $this->assertTrue($policy->effective($this->link(), $child));
        $this->assertFalse($policy->effective($this->link(), $adult));
        $consent = ['adult_consent_at' => '2026-11-23', 'adult_consent_by' => 71, 'adult_consent_source' => 'member'];
        $this->assertTrue($policy->effective($this->link($consent), $adult));
        $this->assertFalse($policy->effective($this->link($consent + ['state' => 'revoked']), $adult));
        $this->assertFalse($policy->effective($this->link($consent), (object) ['birth_date' => null]));
        $this->assertFalse($policy->effective($this->link($consent), (object) ['birth_date' => '2027-01-01']));
    }

    public function test_automatic_authority_tracks_current_parent_email_and_exact_account(): void
    {
        $policy = new ParentAccessPolicy;
        $child = (object) ['birth_date' => '2018-03-01', 'mother_email' => ' guardian@example.invalid ', 'father_email' => null];
        $auto = $this->link(['source' => 'verified_parent_login']);
        DB::connection('clubunio')->table('clubora.account_parent_email_proofs')->insert(['account_id' => 42, 'email' => 'guardian@example.invalid', 'verified_at' => '2026-11-23']);
        $this->assertFalse($policy->effective($auto, $child));
        DB::connection('clubunio')->table('clubora.account_parent_email_proofs')->insert(['account_id' => 41, 'email' => 'guardian@example.invalid', 'verified_at' => '2026-11-23']);
        $this->assertTrue($policy->effective($auto, $child));
        $child->mother_email = 'changed@example.invalid';
        $this->assertFalse($policy->effective($auto, $child));
        $this->assertTrue($policy->effective($this->link(), $child));
    }

    public function test_sql_scopes_consent_to_the_current_link_and_correlates_email_proof(): void
    {
        $query = DB::connection('clubunio')->table('clubora.account_member_links as l');
        (new ParentAccessPolicy)->scope($query, 'l', 'm');
        $sql = $query->toSql();
        $this->assertStringContainsString('"l"."adult_consent_by" is not null', $sql);
        $this->assertStringContainsString('"parent_proof"."account_id" = "l"."account_id"', $sql);
        $this->assertStringContainsString('lower(trim("m"."mother_email"))', $sql);
        $this->assertStringContainsString('"l"."state" = ?', $sql);
        $this->assertContains('approved', $query->getBindings());
        $this->expectException(\InvalidArgumentException::class);
        (new ParentAccessPolicy)->scope($query, 'l;drop', 'm');
    }

    public function test_disabled_rollout_preserves_legacy_minor_rule_without_new_schema_references(): void
    {
        config(['clubunio-parent-access.enabled' => false]);
        $query = DB::connection('clubunio')->table('clubora.account_member_links as l');
        $policy = new ParentAccessPolicy; $policy->scope($query, 'l', 'm');
        $this->assertStringNotContainsString('adult_consent', $query->toSql());
        $this->assertStringNotContainsString('parent_email_proofs', $query->toSql());
        $this->assertTrue($policy->effective($this->link(), (object) ['birth_date' => '2018-01-01']));
        $this->assertFalse($policy->effective($this->link(['adult_consent_at'=>'2026-11-23','adult_consent_by'=>71,'adult_consent_source'=>'office']), (object) ['birth_date'=>'2000-01-01']));
    }
}

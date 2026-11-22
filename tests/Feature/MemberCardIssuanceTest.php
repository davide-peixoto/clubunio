<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Member, MemberStatus, MemberType, Tenant};
use App\ClubUnio\Services\MemberCardService;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

/** Real PostgreSQL semantics, bounded synthetic fixture, always rolled back. */
class MemberCardIssuanceTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Member $member;
    private MemberCardService $cards;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Opt-in rolled-back PostgreSQL card fixture required');
        $this->db = DB::connection('clubunio');
        $this->assertSame('pgsql', $this->db->getDriverName());
        $this->assertContains($this->db->getDatabaseName(), ['db_acdf8d_dclubora', 'clubunio_dev', 'clubunio_stage']);
        if (! Schema::connection('clubunio')->hasTable('clubora.member_cards')) $this->markTestSkipped('Apply the additive card migration first');
        config(['database.default' => 'unconfigured-legacy', 'clubunio-member-cards.verification_enabled' => true, 'clubunio-member-cards.public_origin' => 'https://stage.synthetic.invalid']);
        $this->db->beginTransaction();
        $this->db->statement("SET LOCAL application_name='member-card-synthetic-rollback'");
        $this->tenant = Tenant::create(['slug' => 'card-qa-'.bin2hex(random_bytes(5)), 'name' => 'Synthetic Card Club']);
        $status = new MemberStatus; $status->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Explicitly eligible', 'member_card_eligible' => true, 'active' => true])->save();
        $type = new MemberType; $type->forceFill(['tenant_id' => $this->tenant->id, 'name' => 'Synthetic', 'active' => true])->save();
        $this->member = new Member; $this->member->forceFill(['tenant_id' => $this->tenant->id,
            'first_name' => 'Synthetic', 'last_name' => 'Card Holder', 'status_id' => $status->id, 'type_id' => $type->id,
            'birth_date' => '2007-08-06', 'email' => 'PRIVATE@example.invalid', 'mother_name' => 'PRIVATE PARENT'])->save();
        $this->cards = new MemberCardService;
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel() > 0) $this->db->rollBack();
        parent::tearDown();
    }

    public function test_qr_tokens_are_opaque_hashed_and_stable_until_explicit_rotation(): void
    {
        $first = $this->cards->verification($this->tenant, $this->member->id);
        $again = $this->cards->verification($this->tenant, $this->member->id);
        $this->assertSame($first['Url'], $again['Url']);
        $this->assertMatchesRegularExpression('~^https://stage\.synthetic\.invalid/member-card/[a-f0-9]{64}$~D', $first['Url']);
        $token = basename(parse_url($first['Url'], PHP_URL_PATH));
        $row = $this->db->table('clubora.member_cards')->where('tenant_id', $this->tenant->id)->first();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertNotSame($token, $row->token_encrypted);
        $public = $this->cards->resolve($token);
        $this->assertTrue($public['Valid']);
        $this->assertSame('Synthetic Card Holder', $public['Name']);
        $this->assertArrayNotHasKey('Geburtsdatum', $public); $this->assertArrayNotHasKey('member', $public);
        $this->assertStringNotContainsString('PRIVATE', json_encode($public));
        $version = $this->cards->snapshot($this->tenant, $this->member->id)['SourceVersion'];
        $this->cards->rotate($this->tenant, $this->member->id);
        $second = $this->cards->verification($this->tenant, $this->member->id);
        $this->assertNotSame($first['Url'], $second['Url']);
        $this->assertFalse($this->cards->resolve($token)['Valid']);
        $this->assertNotSame($version, $this->cards->snapshot($this->tenant, $this->member->id)['SourceVersion']);
        $this->assertSame(1, $this->db->table('clubora.member_cards')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_revocation_and_current_member_status_take_effect_without_reissuing_the_card(): void
    {
        $verification = $this->cards->verification($this->tenant, $this->member->id);
        $token = basename(parse_url($verification['Url'], PHP_URL_PATH));
        $id = $this->cards->ensureCard($this->tenant, $this->member->id)->id;
        $this->db->table('clubora.member_statuses')->where('id', $this->member->status_id)->update(['member_card_eligible' => false]);
        $this->assertFalse($this->cards->resolve($token)['Valid']);
        $this->assertNull($this->cards->resolve($token)['Name']);
        $this->db->table('clubora.member_statuses')->where('id', $this->member->status_id)->update(['member_card_eligible' => true]);
        $this->assertTrue($this->cards->resolve($token)['Valid']);
        $this->cards->revoke($this->tenant, $this->member->id);
        $this->assertSame($id, $this->cards->ensureCard($this->tenant, $this->member->id)->id);
        $this->assertSame($verification['Url'], $this->cards->verification($this->tenant, $this->member->id)['Url']);
        $this->assertFalse($this->cards->resolve($token)['Valid']);
        $this->member->forceFill(['archived_at' => now()])->save();
        $this->assertFalse($this->cards->resolve($token)['Valid']);
    }

    public function test_private_card_lookup_cannot_mix_members_between_clubs(): void
    {
        $other = Tenant::create(['slug' => 'card-qa-other-'.bin2hex(random_bytes(5)), 'name' => 'Other synthetic club']);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->cards->ensureCard($other, $this->member->id);
    }
}

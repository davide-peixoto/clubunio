<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Member, MemberChangeRequest, Tenant};
use App\ClubUnio\Services\{MemberChangeService, MemberService};
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class MobileMembersTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $admin;
    private Account $parent;
    private Account $trainer;
    private Member $child;
    private int $coach;
    private int $country;
    private int $recipient;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') { $this->markTestSkipped('Opt-in rolled-back PostgreSQL fixtures'); }
        config(['clubunio-mobile.enabled' => true, 'cache.default' => 'array']);
        Http::preventStrayRequests();
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->db->statement("SET LOCAL application_name = 'mobile-member-id-card-regression'");
        $this->db->statement('SET LOCAL search_path TO clubora');
        $this->tenant = Tenant::create(['slug' => 'mobile-dossier-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic dossier']);
        $this->admin = $this->account('admin'); $this->parent = $this->account('member'); $this->trainer = $this->account('trainer');
        $this->country = $this->db->table('countries')->insertGetId(['tenant_id' => $this->tenant->id, 'code' => 'CH', 'iso3' => 'CHE', 'name' => 'Schweiz']);
        $this->recipient = $this->db->table('billing_recipients')->insertGetId(['tenant_id' => $this->tenant->id, 'code' => 'MEMBER', 'name' => 'Mitglied', 'email_field' => 'email']);
        $this->child = new Member;
        $this->child->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Child', 'birth_date' => '2013-01-01',
            'email' => 'synthetic@example.invalid', 'country_id' => $this->country, 'nationality_id' => $this->country, 'billing_recipient_id' => $this->recipient,
            'mother_name' => 'PRIVATE-PARENT', 'ahv_encrypted' => encrypt('PRIVATE-AHV'), 'pass_number' => '123'])->save();
        $this->coach = $this->db->table('members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Coach']);
        foreach ([[$this->parent, $this->child->id, 'parent'], [$this->trainer, $this->coach, 'self']] as [$actor, $member, $kind]) {
            $this->db->table('account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'member_id' => $member, 'state' => 'approved', 'kind' => $kind, 'source' => 'test']);
        }
        $season = $this->db->table('seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Synthetic', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonth()->toDateString()]);
        $this->db->table('tenant_settings')->insert(['tenant_id' => $this->tenant->id, 'area' => 'general', 'key' => 'SaisonID', 'label' => 'Aktuelle Saison', 'value_type' => 'integer', 'encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString((string) $season)]);
        $team = $this->db->table('teams')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Synthetic']);
        $team = $this->db->table('team_seasons')->insertGetId(['tenant_id' => $this->tenant->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Synthetic']);
        $this->db->table('team_players')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $team, 'member_id' => $this->child->id]);
        $trainerFunction=$this->db->table('staff_functions')->insertGetId(['tenant_id'=>$this->tenant->id,'code'=>'TRAINER','name'=>'Trainer']);
        $this->db->table('team_staff')->insert(['tenant_id' => $this->tenant->id, 'team_season_id' => $team, 'member_id' => $this->coach,'staff_function_id'=>$trainerFunction]);
        foreach (['pending', 'approved', 'rejected', 'withdrawn'] as $code) {
            $this->db->table('member_change_statuses')->insert(['tenant_id' => $this->tenant->id, 'code' => $code, 'name' => $code, 'color' => '#164B8A']);
        }
    }

    protected function tearDown(): void { if (isset($this->db)) { $this->db->rollBack(); } parent::tearDown(); }
    private function account(string $role): Account
    {
        $a = new Account;
        $a->forceFill(['username' => 'dossier-'.bin2hex(random_bytes(8)), 'display_name' => 'Synthetic', 'password' => bcrypt('fixture'), 'active' => true, 'api_only' => false])->save();
        $this->db->table('tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $a->id, 'role' => $role, 'portal_enabled' => true]);
        return $a;
    }
    private function token(Account $actor): string { return $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'fixture', 'device_name' => 'Synthetic dossier'])->assertOk()->json('data.token'); }
    private function url(): string { return '/api/mobile/v1/clubs/'.$this->tenant->id.'/members/'.$this->child->id; }

    public function test_personal_dossier_preserves_contact_scope_and_excludes_private_metadata(): void
    {
        $this->withToken($this->token($this->parent))->getJson($this->url())->assertOk()
            ->assertJsonPath('data.Member.ChangeMode', 'request')->assertJsonPath('data.Member.Mutter', 'PRIVATE-PARENT')
            ->assertJsonPath('data.Member.Land', 'CH')->assertJsonPath('data.Member.Nationalitaet', 'CHE')->assertDontSee('PRIVATE-AHV')->assertJsonMissingPath('data.Member.ahv_encrypted');
        $this->getJson($this->url().'/contact-options')->assertOk()->assertJsonPath('data.Countries.0.ISO2', 'CH')
            ->assertJsonPath('data.BillingRecipients.0.value', (string) $this->recipient);
        $this->withToken($this->token($this->trainer))->getJson($this->url())->assertOk()->assertJsonPath('data.Member.CanEdit', false)
            ->assertJsonMissingPath('data.Member.Geburtsdatum')->assertJsonMissingPath('data.Member.Mutter')->assertJsonMissingPath('data.Member.Passnummer')
            ->assertJsonMissingPath('data.Member._version')->assertDontSee('PRIVATE-PARENT');
        $this->getJson($this->url().'/contact-options')->assertForbidden();
        $this->postJson($this->url().'/contact', ['Email' => 'forbidden@example.invalid', '_version' => MemberService::version($this->child)])->assertForbidden();
        $this->db->table('team_staff')->where('tenant_id', $this->tenant->id)->update(['active' => false]);
        $this->getJson($this->url())->assertNotFound();
    }

    public function test_personal_contact_edits_use_displayed_version_and_real_review_workflow(): void
    {
        $this->withToken($this->token($this->parent));
        $old = $this->getJson($this->url())->assertOk()->json('data.Member._version');
        $this->postJson($this->url().'/contact', ['Email' => 'new@example.invalid', '_version' => str_repeat('0', 64)])->assertUnprocessable();
        $this->postJson($this->url().'/contact', ['AHVNummer' => 'forbidden', '_version' => $old])->assertUnprocessable();
        $this->postJson($this->url().'/contact', ['Homepage' => 'https://example.invalid', '_version' => $old])->assertUnprocessable();
        $response = $this->postJson($this->url().'/contact', ['Email' => 'new@example.invalid', 'Land' => 'CH', '_version' => $old])->assertOk()
            ->assertJsonPath('data.action', 'member-contact-change-request')->assertJsonPath('data.state', 'pending');
        $this->assertSame('synthetic@example.invalid', $this->child->fresh()->email);
        $change = MemberChangeRequest::findOrFail($response->json('data.request_id'));
        $this->assertSame($old, $change->member_version);
        $this->postJson($this->url().'/contact', ['Mobile' => '123', '_version' => $old])->assertUnprocessable();
        $this->getJson($this->url().'/change-requests')->assertForbidden();
        $this->withToken($this->token($this->admin));
        $this->getJson($this->url().'/change-requests?status=OFFEN')->assertOk()->assertJsonPath('data.totalRecordCount', 1)
            ->assertJsonPath('data.requests.0.Revision', 1)->assertJsonPath('data.requests.0.CanApprove', true)
            ->assertJsonPath('data.requests.0.Aenderungen.0.new', 'new@example.invalid');
        $decision = $this->url().'/change-requests/'.$change->id.'/decide';
        $this->postJson($decision, ['Revision' => 99, 'approve' => true])->assertUnprocessable();
        $this->postJson(str_replace('/members/'.$this->child->id.'/', '/members/'.$this->coach.'/', $decision), ['Revision' => 1, 'approve' => true])->assertNotFound();
        $this->postJson($decision, ['Revision' => 1, 'approve' => true])->assertOk()->assertJsonPath('data.status', 'GENEHMIGT')->assertJsonPath('data.Revision', 2);
        $this->postJson($decision, ['Revision' => 1, 'approve' => true])->assertUnprocessable();
        $this->assertSame('new@example.invalid', $this->child->fresh()->email);
        $this->withToken($this->token($this->admin));
        $version = $this->getJson($this->url())->assertOk()->assertJsonPath('data.Member.ChangeMode', 'direct')->json('data.Member._version');
        $this->postJson($this->url().'/contact', ['Ort' => 'Updated', '_version' => $version])->assertOk()->assertJsonPath('data.action', 'member-contact-update');
        $this->assertSame('Updated', $this->child->fresh()->city);
        $this->postJson($this->url().'/contact', ['Ort' => 'Stale', '_version' => $version])->assertUnprocessable();
        $this->postJson($this->url().'/contact', ['Land' => 'ZZ', '_version' => MemberService::version($this->child->fresh())])->assertUnprocessable();
        $this->withToken($this->token($this->parent));
        $request = $this->postJson($this->url().'/contact', ['Mobile' => '123', '_version' => MemberService::version($this->child->fresh())])->assertOk()->json('data.request_id');
        $this->withToken($this->token($this->admin));
        $decision = $this->url().'/change-requests/'.$request.'/decide';
        $this->postJson($decision, ['Revision' => 1, 'approve' => false])->assertUnprocessable();
        $this->postJson($decision, ['Revision' => 1, 'approve' => false, 'Begruendung' => 'Synthetic rejection'])->assertOk()->assertJsonPath('data.status', 'ABGELEHNT');
        $this->assertNull($this->child->fresh()->mobile);
    }

    public function test_personal_dossier_rechecks_parent_tenant_domain_and_token_boundaries(): void
    {
        $token = $this->token($this->parent);
        $this->withToken($token)->getJson($this->url())->assertOk();
        $other = Tenant::create(['slug' => 'dossier-other-'.bin2hex(random_bytes(5)), 'name' => 'Other']);
        $this->getJson(str_replace('/clubs/'.$this->tenant->id.'/', '/clubs/'.$other->id.'/', $this->url()))->assertForbidden();
        config(['clubunio-domains.hosts' => ['dossier-other.example' => $other->slug]]);
        $this->getJson('https://dossier-other.example'.$this->url())->assertNotFound();
        $this->db->table('members')->where('id', $this->child->id)->update(['birth_date' => now('Europe/Zurich')->subYears(18)->toDateString()]);
        $this->getJson('https://localhost'.$this->url())->assertForbidden();
        $this->db->table('mobile_sessions')->where('account_id', $this->parent->id)->update(['revoked_at' => now()]);
        $this->getJson('https://localhost'.$this->url())->assertUnauthorized();
    }

    public function test_member_invoices_keep_financial_scope_separate_and_reauthorize_pdf_reads(): void
    {
        $status = $this->db->table('invoice_statuses')->insertGetId(['tenant_id' => $this->tenant->id, 'code' => '7', 'name' => 'Offen']);
        $lineStatus = $this->db->table('invoice_line_statuses')->insertGetId(['tenant_id' => $this->tenant->id, 'code' => 'O', 'name' => 'Offen']);
        $draft = $this->db->table('invoice_workflow_statuses')->insertGetId(['tenant_id' => $this->tenant->id, 'code' => 'draft', 'name' => 'Entwurf']);
        $invoice = $this->db->table('invoices')->insertGetId(['tenant_id' => $this->tenant->id, 'member_id' => $this->child->id, 'status_id' => $status, 'bexio_invoice_id' => '123']);
        $this->db->table('invoice_lines')->insert(['tenant_id' => $this->tenant->id, 'invoice_id' => $invoice, 'status_id' => $lineStatus, 'net_amount' => '50.00']);
        $this->db->table('invoices')->insert(['tenant_id' => $this->tenant->id, 'member_id' => $this->child->id, 'workflow_status_id' => $draft]);
        $other = $this->db->table('invoices')->insertGetId(['tenant_id' => $this->tenant->id, 'member_id' => $this->coach, 'status_id' => $status, 'bexio_invoice_id' => '456']);
        $this->withToken($this->token($this->parent))->getJson($this->url())->assertOk()->assertJsonPath('data.Member.CanReadInvoices', true);
        $this->getJson($this->url().'/invoices')->assertOk()->assertJsonPath('data.totalRecordCount', 1)
            ->assertJsonPath('data.Invoices.0.AmountCents', 5000)->assertJsonPath('data.Invoices.0.HasPdf', true);
        $this->getJson($this->url().'/invoices?start=1&recperpage=1')->assertOk()->assertJsonCount(0, 'data.Invoices')->assertJsonPath('data.totalRecordCount', 1);
        // Only bytes/provider are stubbed. The real personal middleware/financial scope runs first.
        $this->mock(\App\ClubUnio\Services\InvoicePdfService::class)->shouldReceive('open')->once()
            ->withArgs(fn ($actor, $tenant, $id, $bexio) => $actor->id === $this->parent->id && $tenant->id === $this->tenant->id && $id === $invoice && $bexio)
            ->andReturn(response('%PDF-1.7 synthetic', 200, ['Content-Type' => 'application/pdf']));
        $this->get('/api/mobile/v1/clubs/'.$this->tenant->id.'/invoices/'.$invoice.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson('/api/mobile/v1/clubs/'.$this->tenant->id.'/invoices/'.$other.'/pdf')->assertNotFound();
        $this->withToken($this->token($this->trainer))->getJson($this->url())->assertOk()->assertJsonPath('data.Member.CanReadInvoices', false);
        $this->getJson($this->url().'/invoices')->assertForbidden();
        $this->getJson('/api/mobile/v1/clubs/'.$this->tenant->id.'/invoices/'.$invoice.'/pdf')->assertNotFound();
    }
    public function test_personal_id_card_projects_only_real_fields_and_requires_full_member_access(): void
    {
        config(['clubunio-mobile.native_member_id_card_enabled' => true]);
        $status = $this->db->table('member_statuses')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Synthetic active', 'code' => 'CARD']);
        $type = $this->db->table('member_types')->insertGetId(['tenant_id' => $this->tenant->id, 'name' => 'Synthetic junior', 'code' => 'CARD']);
        $this->child->forceFill(['status_id' => $status, 'type_id' => $type, 'honorary' => true])->save();
        $this->withToken($this->token($this->parent));
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.Member.CanReadIdCard', false);
        $this->withHeader('X-ClubUnio-Mobile-Features', 'member-id-card-v1');
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.Member.CanReadIdCard', true);
        $response = $this->getJson($this->url().'/id-card')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.Card.TenantID', (int) $this->tenant->id)
            ->assertJsonPath('data.Card.TenantName', $this->tenant->name)
            ->assertJsonPath('data.Card.StatusText', 'Synthetic active')
            ->assertJsonPath('data.Card.MitgliederartText', 'Synthetic junior')
            ->assertJsonPath('data.Card.Ehrenmitglied', true)
            ->assertJsonMissingPath('data.Card.Email')->assertJsonMissingPath('data.Card.Mutter')
            ->assertJsonMissingPath('data.Card.Passnummer')->assertJsonMissingPath('data.Card.ahv_encrypted')
            ->assertDontSee('PRIVATE-PARENT')->assertDontSee('PRIVATE-AHV');
        $version = $response->json('data.Card.SourceVersion');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $version);
        $this->db->table('member_types')->where('id', $type)->update(['name' => 'Changed real type']);
        $this->assertNotSame($version, $this->getJson($this->url().'/id-card')->assertOk()->json('data.Card.SourceVersion'));
        $this->withToken($this->token($this->trainer))->getJson($this->url().'/id-card')->assertForbidden();
        $this->withToken($this->token($this->admin))->getJson($this->url().'/id-card')->assertOk();
    }

    public function test_personal_id_card_closes_for_readiness_and_revoked_or_adult_parent_links(): void
    {
        $this->withToken($this->token($this->parent));
        $this->getJson($this->url().'/id-card')->assertStatus(503);
        config(['clubunio-mobile.native_member_id_card_enabled' => true]);
        $this->getJson($this->url().'/id-card')->assertOk();
        $this->db->table('account_member_links')->where('tenant_id', $this->tenant->id)->where('account_id', $this->parent->id)->update(['state' => 'revoked']);
        $this->getJson($this->url().'/id-card')->assertForbidden();
        $this->db->table('account_member_links')->where('tenant_id', $this->tenant->id)->where('account_id', $this->parent->id)->update(['state' => 'approved']);
        $this->child->forceFill(['birth_date' => now()->subYears(18)->toDateString()])->save();
        $this->getJson($this->url().'/id-card')->assertForbidden();
    }

}

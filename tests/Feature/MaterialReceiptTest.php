<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\MaterialReceipt;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\MaterialReceiptResource\Pages\ListMaterialReceipts;
use App\ClubUnio\Services\AzureDocumentReader;
use App\ClubUnio\Services\LegacyTrainingPlanImporter;
use App\ClubUnio\Services\MaterialReceiptService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MaterialReceiptTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $office;

    private Account $player;

    private Account $parent;

    private Member $child;

    private MaterialReceiptService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Isolated transaction opt-in');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'receipt-'.Str::random(10), 'name' => 'Synthetic club']);
        foreach (['draft', 'issued', 'confirmed', 'cancelled'] as $code) {
            $this->insert('material_receipt_statuses', ['code' => $code, 'name' => $code]);
        }
        $this->office = $this->account('office');
        $this->player = $this->account('member');
        $this->parent = $this->account('member');
        $this->child = new Member;
        $this->child->forceFill(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => 'Child', 'birth_date' => '2014-01-02'])->save();
        foreach ([[$this->player, 'self'], [$this->parent, 'parent']] as [$actor, $kind]) {
            $this->insert('account_member_links', ['account_id' => $actor->id, 'member_id' => $this->child->id, 'kind' => $kind, 'state' => 'approved', 'source' => 'test']);
        }
        $this->service = app(MaterialReceiptService::class);
        Http::preventStrayRequests();
        Mail::fake();
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->office, 'clubunio');
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            $this->db->rollBack();
        }
        parent::tearDown();
    }

    private function insert(string $table, array $data): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId(['tenant_id' => $this->tenant->id] + $data);
    }

    private function account(string $role): Account
    {
        $a = new Account;
        $a->forceFill(['username' => Str::random(20), 'display_name' => 'Synthetic '.$role, 'password' => bcrypt('fixture'), 'active' => true])->save();
        $this->insert('tenant_accounts', ['account_id' => $a->id, 'role' => $role, 'portal_enabled' => true]);

        return $a;
    }

    private function draft(): MaterialReceipt
    {
        return $this->service->save($this->office, $this->tenant, null, ['revision' => 0, 'member_id' => $this->child->id,
            'title' => 'Synthetic dress', 'handover_on' => '2026-10-15', 'note' => '<script>unsafe</script>',
            'items' => ['repeater-uuid' => ['description' => 'Dress', 'quantity' => 2, 'reference' => 'M', 'condition' => 'Neu']]]);
    }

    private function issue(): MaterialReceipt
    {
        $r = $this->draft();

        return $this->service->transition($this->office, $this->tenant, $r->id, 1, 'issue');
    }

    public function test_web_lifecycle_parent_confirmation_and_private_pdf(): void
    {
        $r = $this->draft();
        $this->actingAs($this->player, 'portal')->get(route('clubunio.portal.material.show', [$this->tenant->slug, $r->id]))->assertNotFound();
        $this->service->transition($this->office, $this->tenant, $r->id, 1, 'issue');
        $this->actingAs($this->parent, 'portal')->get(route('clubunio.portal.material.show', [$this->tenant->slug, $r->id]))->assertOk()->assertSee('Dress')->assertDontSee('<script>unsafe</script>', false);
        $this->post(route('clubunio.portal.material.confirm', [$this->tenant->slug, $r->id]), ['revision' => 2, 'accepted' => 1])->assertRedirect();
        $r = $r->fresh();
        $this->assertSame('confirmed', $r->state);
        $this->assertSame('parent', $r->confirmation_kind);
        $this->assertSame($this->parent->id, $r->confirmed_by);
        $pdf = $this->get(route('clubunio.portal.material.pdf', [$this->tenant->slug, $r->id]));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('no-store', $pdf->headers->get('Cache-Control'));
        $this->assertSame(3, $this->db->table('clubora.object_changes')->where('tenant_id', $this->tenant->id)->where('object_type', 'material_receipts')->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_own_confirmation_and_repeat_or_stale_requests_rejected(): void
    {
        $r = $this->issue();
        $this->actingAs($this->player, 'portal');
        $url = route('clubunio.portal.material.confirm', [$this->tenant->slug, $r->id]);
        $this->post($url, ['revision' => 1, 'accepted' => 1])->assertSessionHasErrors('revision');
        $this->post($url, ['revision' => 2])->assertSessionHasErrors('accepted');
        $this->assertSame('issued', $r->fresh()->state);
        $this->post($url, ['revision' => 2, 'accepted' => 1])->assertRedirect();
        $this->assertSame('self', $r->fresh()->confirmation_kind);
        $this->post($url, ['revision' => 3, 'accepted' => 1])->assertStatus(409);
    }

    public function test_revoked_parent_adult_child_departure_and_foreign_users_denied(): void
    {
        $r = $this->issue();
        $other = $this->account('member');
        $url = route('clubunio.portal.material.show', [$this->tenant->slug, $r->id]);
        $this->actingAs($other, 'portal')->get($url)->assertForbidden();
        $this->child->forceFill(['birth_date' => '2000-01-02'])->save();
        $this->actingAs($this->parent, 'portal')->get($url)->assertForbidden();
        $this->child->forceFill(['birth_date' => '2014-01-02'])->save();
        $this->db->table('clubora.account_member_links')->where('account_id', $this->parent->id)->update(['state' => 'revoked']);
        $this->get($url)->assertForbidden();
        $this->child->forceFill(['exit_date' => '2020-01-02'])->save();
        $this->actingAs($this->player, 'portal')->get($url)->assertForbidden();
    }

    public function test_office_cannot_confirm_on_behalf_and_confirmed_receipt_is_immutable(): void
    {
        $r = $this->issue();
        $this->actingAs($this->office, 'portal')->post(route('clubunio.portal.material.confirm', [$this->tenant->slug, $r->id]), ['revision' => 2, 'accepted' => 1])->assertNotFound();
        $this->service->transition($this->player, $this->tenant, $r->id, 2, 'confirm', ['accepted' => 1]);
        try {
            $this->service->transition($this->office, $this->tenant, $r->id, 3, 'cancel', ['reason' => 'test']);
            $this->fail('Immutable');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        try {
            $this->service->save($this->office, $this->tenant, $r->id, ['revision' => 3, 'member_id' => $this->child->id, 'title' => 'Changed', 'handover_on' => '2026-10-15', 'items' => [['description' => 'x', 'quantity' => 1]]]);
            $this->fail('Immutable');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_cancelled_handover_cannot_be_confirmed_and_hash_tampering_blocks_pdf(): void
    {
        $r = $this->issue();
        $this->service->transition($this->office, $this->tenant, $r->id, 2, 'cancel', ['reason' => 'Falsche Ausgabe']);
        $this->actingAs($this->player, 'portal')->post(route('clubunio.portal.material.confirm', [$this->tenant->slug, $r->id]), ['revision' => 3, 'accepted' => 1])->assertStatus(409);
        $r->fresh()->forceFill(['snapshot_hash' => str_repeat('0', 64)])->save();
        $this->get(route('clubunio.portal.material.pdf', [$this->tenant->slug, $r->id]))->assertStatus(409);
    }

    public function test_management_list_and_forms_render_but_trainer_cannot_create(): void
    {
        $r = $this->draft();
        $this->actingAs($this->office, 'clubunio');
        Livewire::test(ListMaterialReceipts::class)->assertSuccessful()->assertCanSeeTableRecords([$r])->callTableAction('editReceipt', $r)->assertHasNoErrors();
        $this->actingAs($this->account('trainer'), 'clubunio')->get('/material-receipts')->assertForbidden();
    }

    public function test_historical_matching_requires_single_authorized_team_in_source_season(): void
    {
        $service = app(LegacyTrainingPlanImporter::class);
        $this->assertSame(2, $service->historicalCandidate('B-Junioren', '2026-09-01 12:00:00', [1 => 'Ba-Junioren / 2025/2026', 2 => 'Ba-Junioren / 2026/2027']));
        $this->assertNull($service->historicalCandidate('B-Junioren', '2026-09-01', [1 => 'Ba-Junioren / 2026/2027', 2 => 'Bb-Junioren / 2026/2027']));
        $this->assertNull($service->historicalCandidate('Aktive Herren', '2026-09-01', [1 => 'Ba-Junioren / 2026/2027']));
        $this->assertNull($service->historicalCandidate('unknown', '2026-09-01', [1 => 'Ba-Junioren / 2026/2027']));
        $this->assertSame(1, $service->historicalCandidate('G-Junioren', '2026-06-30', [1 => 'G Junioren / 2025/2026', 2 => 'G Junioren / 2026/2027']));
    }

    public function test_legacy_signed_pdf_is_preserved_scoped_and_hash_checked(): void
    {
        $bytes = "%PDF-1.4\nSynthetic historical receipt";
        $type = $this->insert('document_types', ['name' => 'Synthetic receipt', 'code' => 'synthetic']);
        $document = $this->insert('member_documents', ['member_id' => $this->child->id, 'document_type_id' => $type, 'filename' => 'synthetic.pdf', 'storage_path' => 'synthetic/', 'active' => true]);
        $history = $this->insert('legacy_material_receipts', ['member_id' => $this->child->id, 'source_id' => 123, 'source_hash' => str_repeat('1', 64),
            'title' => 'Synthetic historical receipt', 'source_state' => 'UNTERSCHRIEBEN', 'original_document_id' => $document, 'signed_document_id' => $document,
            'original_sha256' => hash('sha256', $bytes), 'signed_sha256' => hash('sha256', $bytes), 'imported_by' => $this->office->id]);
        $this->mock(AzureDocumentReader::class)->shouldReceive('read')->twice()->andReturn($bytes, '%PDF-corrupted');
        $url = route('clubunio.portal.material.history.pdf', [$this->tenant->slug, $history]);
        $this->actingAs($this->player, 'portal')->get($url)->assertOk()->assertContent($bytes);
        $this->get($url)->assertStatus(409);
        $this->db->table('clubora.account_member_links')->where('account_id', $this->parent->id)->update(['state' => 'revoked']);
        $this->actingAs($this->parent, 'portal')->get($url)->assertForbidden();
        $this->assertSame(0, MaterialReceipt::where('tenant_id', $this->tenant->id)->count());
    }
}

<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\MaterialReceipt;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\DocumentSeries;
use App\ClubUnio\Services\AzureDocumentReader;
use App\ClubUnio\Services\DocumentSeriesService;
use App\ClubUnio\Services\DocumentUploadService;
use App\ClubUnio\Services\SyncWorkService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class DocumentSeriesTest extends TestCase
{
    private $db;

    private Tenant $tenant;

    private Account $actor;

    private DocumentSeriesService $service;

    private int $member;

    private int $otherMember;

    private int $team;

    private int $otherTeam;

    private int $type;

    private int $template;

    private array $bytes = [];

    private int $failUploads = 0;

    private int $baselineLevel;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') {
            $this->markTestSkipped('Outer-rollback opt-in required');
        }
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        if (! Schema::connection('clubunio')->hasTable('clubora.document_series')) {
            $this->markTestSkipped('Parent must review/apply migration73; this test never migrates.');
        }
        $this->db->beginTransaction();
        $this->baselineLevel = $this->db->transactionLevel();
        Http::preventStrayRequests();
        Mail::fake();
        $this->tenant = Tenant::create(['slug' => 'series-'.Str::random(14), 'name' => 'Synthetic series club']);
        $this->actor = $this->account('office');
        foreach (['draft', 'issued', 'confirmed', 'cancelled'] as $state) {
            $this->insert('material_receipt_statuses', ['name' => $state, 'code' => $state]);
        }
        $this->type = $this->insert('document_types', ['name' => 'Synthetic receipt', 'code' => 'MATERIALQUITTUNG']);
        $this->insert('document_types', ['name' => 'Synthetic key', 'code' => 'SCHLUESSELQUITTUNG']);
        $season = $this->insert('seasons', ['name' => '2030/2031']);
        $team = $this->insert('teams', ['name' => 'Synthetic A']);
        $otherTeam = $this->insert('teams', ['name' => 'Synthetic B']);
        $this->team = $this->insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Synthetic A']);
        $this->otherTeam = $this->insert('team_seasons', ['team_id' => $otherTeam, 'season_id' => $season, 'label' => 'Synthetic B']);
        $this->member = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Child', 'birth_date' => '2015-01-02', 'street' => 'Teststrasse', 'house_number' => '1', 'postal_code' => '4450', 'city' => 'Testort']);
        $this->otherMember = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Second', 'birth_date' => '2014-01-02']);
        foreach ([[$this->member, $this->team], [$this->member, $this->otherTeam], [$this->otherMember, $this->team]] as [$member, $team]) {
            $this->insert('team_players', ['member_id' => $member, 'team_season_id' => $team]);
        }
        $this->insert('team_staff', ['member_id' => $this->member, 'team_season_id' => $this->team]);
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $this->actingAs($this->actor, 'clubunio');
        Filament::setTenant($this->tenant);
        $this->service = app(DocumentSeriesService::class);
        $this->template = $this->service->saveTemplate($this->actor, $this->tenant, $this->definition())->id;
        $this->fakeStorage();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->transactionLevel()) {
            while ($this->db->transactionLevel()) {
                $this->db->rollBack();
            }
        }
        parent::tearDown();
    }

    private function insert(string $table, array $values): int
    {
        return $this->db->table('clubora.'.$table)->insertGetId(['tenant_id' => $this->tenant->id] + $values);
    }

    private function account(string $role = 'member', array $permissions = []): Account
    {
        $actor = new Account;
        $actor->forceFill(['username' => Str::random(24), 'display_name' => 'Synthetic '.$role, 'password' => bcrypt('synthetic'), 'active' => true])->save();
        $link = ['account_id' => $actor->id, 'role' => $role, 'portal_enabled' => $role === 'member'];
        if ($role === 'profile') {
            $link['access_profile_id'] = $this->insert('access_profiles', ['name' => Str::random(20), 'permissions' => json_encode($permissions), 'active' => true]);
        }
        $this->insert('tenant_accounts', $link);

        return $actor;
    }

    private function definition(): array
    {
        return ['name' => 'Synthetic handover', 'title' => 'Set {{Mitglied.Vorname}}', 'text' => 'Synthetic conditions {{Team.Name}}',
            'type_id' => $this->type, 'signature_required' => true,
            'items' => [['description' => 'Synthetic shirt', 'reference' => 'S-1', 'quantity' => 2, 'price_cents' => 3550]]];
    }

    private function input(): array
    {
        return ['template_id' => $this->template, 'teams' => [$this->team, $this->otherTeam], 'target' => 'both', 'date' => '2030-10-25',
            'signature_required' => true, 'portal_visible' => true, 'common' => [], 'individual' => []];
    }

    private function create(?array $input = null): \App\ClubUnio\Models\DocumentSeries
    {
        $input ??= $this->input();
        $preview = $this->service->preview($this->actor, $this->tenant, $input);

        return $this->service->create($this->actor, $this->tenant, $input, $preview['hash'], (string) Str::uuid());
    }

    private function assertHttpStatus(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected HTTP '.$status);
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    /** Durable metadata is real synthetic data; all bytes/provider work stay in this test double. */
    private function fakeStorage(): void
    {
        $this->mock(DocumentUploadService::class, function ($mock) {
            $mock->shouldReceive('table')->andReturnUsing(fn () => $this->db->table('clubora.document_uploads'));
            $mock->shouldReceive('stage')->andReturnUsing(function ($actor, $tenant, $kind, $member, $metadata, $file) {
                $this->assertGreaterThan($this->baselineLevel, $this->db->transactionLevel());
                $id = $this->insert('document_uploads', ['kind' => $kind, 'object_id' => $member, 'deduplication_key' => hash('sha256', Str::uuid()),
                    'sha256' => hash('sha256', $file->get()), 'byte_count' => strlen($file->get()), 'mime_type' => 'application/pdf',
                    'folder' => 'synthetic/', 'filename' => Str::uuid().'.pdf', 'encrypted_metadata' => Crypt::encryptString(json_encode($metadata)), 'created_by' => $actor->id]);
                $this->bytes[$id] = $file->get();
                app(SyncWorkService::class)->rememberDocument($tenant, $id);

                return $id;
            });
            $mock->shouldReceive('process')->andReturnUsing(function ($actor, $tenant, $id) {
                // Only the test's outer rollback remains during the would-be network call.
                $this->assertSame($this->baselineLevel, $this->db->transactionLevel());
                if ($this->failUploads > 0) {
                    $this->failUploads--;
                    throw new \RuntimeException('Synthetic storage failure');
                }
                $upload = $this->db->table('clubora.document_uploads')->find($id);
                if ($upload->state === 'published') {
                    return $upload->result_id;
                }
                $this->assertSame((int) $upload->created_by, (int) $actor->id);
                $metadata = json_decode(Crypt::decryptString($upload->encrypted_metadata), true);
                $document = $this->insert('member_documents', ['member_id' => $upload->object_id, 'document_type_id' => $metadata['type'],
                    'filename' => $upload->filename, 'storage_path' => 'synthetic/', 'sha256' => $upload->sha256,
                    'created_by' => $upload->created_by, 'portal_visible' => $metadata['portal_visible'], 'revision' => 1, 'content_type' => 'application/pdf']);
                $this->db->table('clubora.document_uploads')->where('id', $id)->update(['state' => 'published', 'result_id' => $document]);

                return $document;
            });
        });
        $this->mock(AzureDocumentReader::class)->shouldReceive('read')->andReturnUsing(function ($folder, $name) {
            $upload = $this->db->table('clubora.document_uploads')->where('filename', $name)->first();

            return $this->bytes[$upload->id];
        });
        config(['clubunio-integrations.azure.tenant_slug' => $this->tenant->slug]);
    }

    public function test_defaults_versions_dedup_rosters_and_individual_fields(): void
    {
        $this->service->installDefaults($this->actor, $this->tenant);
        $this->service->installDefaults($this->actor, $this->tenant);
        $this->assertCount(5, $this->service->templates($this->actor, $this->tenant));
        $new = $this->service->saveTemplate($this->actor, $this->tenant, $this->definition(), $this->template);
        $this->assertSame(2, $new->version);
        $preview = $this->service->preview($this->actor, $this->tenant, $this->input());
        $this->assertCount(2, $preview['snapshot']['recipients']);
        $this->assertSame('Synthetic A / 2030/2031, Synthetic B / 2030/2031', $preview['snapshot']['recipients'][0]['values']['Team.Name']);
        $staff = $this->input();
        $staff['target'] = 'staff';
        $this->assertCount(1, $this->service->preview($this->actor, $this->tenant, $staff)['snapshot']['recipients']);
        $key = $this->service->templates($this->actor, $this->tenant)->firstWhere('family_key', 'legacy-key');
        $input = $this->input();
        $input['template_id'] = $key->id;
        $input['members'] = [$this->member];
        $this->assertFalse($this->service->preview($this->actor, $this->tenant, $input)['can_create']);
        $input['common'] = ['Schluessel.Grundnummer' => 'TEST'];
        $input['individual'] = [$this->member => ['Schluessel.Nummer' => 'S-001']];
        $this->assertTrue($this->service->preview($this->actor, $this->tenant, $input)['can_create']);
        $input['individual'][$this->member]['Mitglied.Vorname'] = 'Spoof';
        $this->assertHttpStatus(422, fn () => $this->service->preview($this->actor, $this->tenant, $input));
    }

    public function test_fresh_hash_request_idempotence_and_conflict(): void
    {
        $input = $this->input();
        $preview = $this->service->preview($this->actor, $this->tenant, $input);
        $this->db->table('clubora.members')->where('id', $this->member)->update(['first_name' => 'Changed']);
        $this->assertHttpStatus(409, fn () => $this->service->create($this->actor, $this->tenant, $input, $preview['hash'], (string) Str::uuid()));
        $preview = $this->service->preview($this->actor, $this->tenant, $input);
        $key = (string) Str::uuid();
        $series = $this->service->create($this->actor, $this->tenant, $input, $preview['hash'], $key);
        $this->db->table('clubora.members')->where('id', $this->member)->update(['first_name' => 'Changed again']);
        $this->assertSame($series->id, $this->service->create($this->actor, $this->tenant, $input, $preview['hash'], $key)->id);
        $input['date'] = '2030-10-26';
        $this->assertHttpStatus(409, fn () => $this->service->create($this->actor, $this->tenant, $input, $preview['hash'], $key));
        $cipher = $this->db->table('clubora.document_series_recipients')->where('series_id', $series->id)->value('snapshot');
        $this->assertStringNotContainsString('Synthetic', $cipher);
    }

    public function test_storage_failure_resume_one_receipt_per_call_and_frozen_identity(): void
    {
        $series = $this->create();
        $this->db->table('clubora.members')->where('id', $this->member)->update(['first_name' => 'Changed after approval']);
        $this->failUploads = 1;
        try {
            $this->service->process($this->actor, $this->tenant, $series->id);
            $this->fail('Storage failure expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic storage failure', $e->getMessage());
        }
        $row = $this->service->recipients($this->actor, $this->tenant, $series->id)->first();
        $this->assertNotNull($row->upload_id);
        $this->assertNull($row->receipt_id);
        $this->assertNull($row->lease_until);
        $result = $this->service->process($this->actor, $this->tenant, $series->id);
        $this->assertFalse($result['done']);
        $receipt = MaterialReceipt::findOrFail($result['receipt_id']);
        $this->assertStringNotContainsString('Changed after approval', $receipt->snapshot['member']);
        $this->assertSame(1, MaterialReceipt::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, $this->db->table('clubora.document_uploads')->where('tenant_id', $this->tenant->id)->count());
        $this->assertTrue($this->service->process($this->actor, $this->tenant, $series->id)['done']);
        $this->assertTrue($this->service->process($this->actor, $this->tenant, $series->id)['done']);
        $this->assertSame(2, MaterialReceipt::where('tenant_id', $this->tenant->id)->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_live_lease_blocks_parallel_processing_and_expired_lease_resumes(): void
    {
        $series = $this->create();
        $row = $this->service->recipients($this->actor, $this->tenant, $series->id)->first();
        $row->forceFill(['lease_token' => Str::uuid(), 'lease_until' => now()->addMinutes(5)])->save();
        $this->assertTrue($this->service->process($this->actor, $this->tenant, $series->id)['busy']);
        $row->forceFill(['lease_until' => now()->subMinute()])->save();
        $this->assertArrayHasKey('receipt_id', $this->service->process($this->actor, $this->tenant, $series->id));
    }

    public function test_private_only_dossier_does_not_leak_an_issued_portal_receipt(): void
    {
        $input = $this->input();
        $input['portal_visible'] = false;
        $input['members'] = [$this->member];
        $series = $this->create($input);
        $this->assertTrue($this->service->process($this->actor, $this->tenant, $series->id)['done']);
        $row = $this->service->recipients($this->actor, $this->tenant, $series->id)->first();
        $this->assertNotNull($row->document_id);
        $this->assertNull($row->receipt_id);
        $this->assertSame(0, MaterialReceipt::where('tenant_id', $this->tenant->id)->count());
        $parent = $this->account();
        $this->insert('account_member_links', ['account_id' => $parent->id, 'member_id' => $this->member, 'kind' => 'parent', 'state' => 'approved', 'source' => 'test']);
        $this->assertHttpStatus(404, fn () => $this->service->download($parent, $this->tenant, $series->id, $row->id, false, true));
    }

    public function test_full_conditions_survive_existing_material_note_input_limit(): void
    {
        $definition = $this->definition();
        $definition['text'] = str_repeat('Synthetic full conditions. ', 160).'Final condition.';
        $this->template = $this->service->saveTemplate($this->actor, $this->tenant, $definition)->id;
        $series = $this->create();
        $result = $this->service->process($this->actor, $this->tenant, $series->id);
        $receipt = MaterialReceipt::findOrFail($result['receipt_id']);
        $this->assertSame($definition['text'], $receipt->note);
        $this->assertSame($definition['text'], $receipt->snapshot['note']);
    }

    public function test_checked_return_separate_original_idempotence_provenance_and_retry(): void
    {
        $series = $this->create();
        $this->service->process($this->actor, $this->tenant, $series->id);
        $row = $this->service->recipients($this->actor, $this->tenant, $series->id)->first();
        $file = UploadedFile::fake()->createWithContent('checked.pdf', "%PDF-1.4\nSynthetic checked return\n%%EOF");
        $this->failUploads = 1;
        try {
            $this->service->signedReturn($this->actor, $this->tenant, $series->id, $row->id, $file, true, $row->original_sha256);
            $this->fail('Storage failure expected');
        } catch (\RuntimeException) {
            $this->assertNotNull($row->fresh()->signed_upload_id);
        }
        $result = $this->service->finishSignedReturn($this->actor, $this->tenant, $series->id, $row->id);
        $this->assertSame($row->document_id, $result->document_id);
        $this->assertNotEquals($row->document_id, $result->signed_document_id);
        $this->assertSame($this->actor->id, (int) $result->checked_by);
        $this->assertSame('issued', $result->receipt->state);
        $repeat = $this->service->signedReturn($this->actor, $this->tenant, $series->id, $row->id, $file, true, $row->original_sha256);
        $this->assertSame($result->signed_document_id, $repeat->signed_document_id);
        $other = UploadedFile::fake()->createWithContent('other.pdf', "%PDF-1.4\nOther return\n%%EOF");
        $this->assertHttpStatus(409, fn () => $this->service->signedReturn($this->actor, $this->tenant, $series->id, $row->id, $other, true, $row->original_sha256));
        $original = UploadedFile::fake()->createWithContent('original.pdf', $this->bytes[$row->upload_id]);
        $this->assertHttpStatus(422, fn () => $this->service->signedReturn($this->actor, $this->tenant, $series->id, $row->id, $original, true, $row->original_sha256));
        $response = $this->service->download($this->actor, $this->tenant, $series->id, $row->id, true);
        $this->assertSame($file->get(), $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_cross_parent_portal_scope_revocation_and_existing_digital_confirmation(): void
    {
        $series = $this->create();
        $this->service->process($this->actor, $this->tenant, $series->id);
        $row = $this->service->recipients($this->actor, $this->tenant, $series->id)->first();
        $parent = $this->account();
        $otherParent = $this->account();
        $this->insert('account_member_links', ['account_id' => $parent->id, 'member_id' => $this->member, 'kind' => 'parent', 'state' => 'approved', 'source' => 'test']);
        $this->insert('account_member_links', ['account_id' => $otherParent->id, 'member_id' => $this->otherMember, 'kind' => 'parent', 'state' => 'approved', 'source' => 'test']);
        $response = $this->service->download($parent, $this->tenant, $series->id, $row->id, false, true);
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        try {
            $this->service->download($otherParent, $this->tenant, $series->id, $row->id, false, true);
            $this->fail('Foreign child exposed');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        $url = route('clubunio.portal.material.confirm', [$this->tenant->slug, $row->receipt_id]);
        $this->actingAs($otherParent, 'portal')->post($url, ['revision' => 2, 'accepted' => 1])->assertNotFound();
        $this->actingAs($parent, 'portal')->post($url, ['revision' => 2, 'accepted' => 1])->assertRedirect();
        $receipt = MaterialReceipt::findOrFail($row->receipt_id);
        $this->assertSame('parent', $receipt->confirmation_kind);
        $this->assertSame('confirmed', $receipt->state);
        $this->assertNull($row->fresh()->signed_document_id, 'Digital acceptance is not a handwritten PDF signature');
        $this->db->table('clubora.account_member_links')->where('account_id', $parent->id)->update(['state' => 'revoked']);
        $this->assertHttpStatus(403, fn () => $this->service->download($parent, $this->tenant, $series->id, $row->id, false, true));
    }

    public function test_permissions_are_independent_and_never_granted_by_the_series(): void
    {
        $read = $this->account('profile', ['documents.view']);
        $this->assertNotEmpty($this->service->templates($read, $this->tenant));
        $this->assertHttpStatus(403, fn () => $this->service->saveTemplate($read, $this->tenant, $this->definition()));
        $input = $this->input();
        $preview = $this->service->preview($read, $this->tenant, $input);
        foreach (['create', 'upload', 'send'] as $missing) {
            $permissions = array_map(fn ($p) => 'documents.'.$p, array_diff(['view', 'create', 'upload', 'send'], [$missing]));
            $profile = $this->account('profile', $permissions);
            $this->assertHttpStatus(403, fn () => $this->service->create($profile, $this->tenant, $input, $preview['hash'], (string) Str::uuid()));
        }
        $full = $this->account('profile', ['documents.view', 'documents.create', 'documents.upload', 'documents.send']);
        $series = $this->service->create($full, $this->tenant, $input, $preview['hash'], (string) Str::uuid());
        $this->assertHttpStatus(403, fn () => $this->service->download($full, $this->tenant, $series->id, 1));
        $this->assertHttpStatus(403, fn () => $this->service->finishSignedReturn($full, $this->tenant, $series->id, 1));
        $trainer = $this->account('trainer');
        $this->assertHttpStatus(403, fn () => $this->service->templates($trainer, $this->tenant));
    }

    public function test_tenant_scope_foreign_member_rejection_and_database_immutability(): void
    {
        $foreign = Tenant::create(['slug' => 'foreign-'.Str::random(12), 'name' => 'Synthetic foreign']);
        $this->assertHttpStatus(403, fn () => $this->service->templates($this->actor, $foreign));
        $input = $this->input();
        $input['members'] = [$this->insert('members', ['first_name' => 'Unassigned'])];
        $this->assertHttpStatus(422, fn () => $this->service->preview($this->actor, $this->tenant, $input));
        $series = $this->create();
        foreach (['document_series_templates' => $this->template, 'document_series' => $series->id] as $table => $id) {
            try {
                $this->db->transaction(fn () => $this->db->table('clubora.'.$table)->where('id', $id)->update(['created_by' => $this->actor->id]));
                $this->fail('Immutable evidence changed');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
        $row = $this->service->recipients($this->actor, $this->tenant, $series->id)->first();
        try {
            $this->db->transaction(fn () => $this->db->table('clubora.document_series_recipients')->where('id', $row->id)->update(['snapshot_hash' => str_repeat('0', 64)]));
            $this->fail('Recipient snapshot changed');
        } catch (QueryException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }
    }

    public function test_livewire_preview_create_history_and_template_editor_render(): void
    {
        Livewire::test(DocumentSeries::class)->assertSuccessful()->set('templateId', $this->template)->set('teams', [$this->team])
            ->call('checkPreview')->assertSet('review.can_create', true)->assertSee('Synthetic Child')
            ->set('acknowledge', true)->call('createSeries')->assertHasNoErrors()->call('processNext')->assertHasNoErrors()
            ->call('editTemplate')->assertSet('editing', true)->assertSee('Preis (Rappen)')->assertHasNoErrors();
    }
}

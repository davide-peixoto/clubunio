<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\{MalwareScanner, MobileExpenseReceipts, MobileExpenseRoutes, MobileMiddleware};
use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Services\ExpenseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Crypt, DB, Http, Mail, Route};
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileExpensesTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $trainer;
    private Account $lead;
    private Account $other;
    private int $member;
    private int $team;
    private int $bank;
    private int $round;
    private int $type;
    private string $url;
    protected function setUp(): void {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Opt-in rolled-back PostgreSQL fixtures required');
        config(['clubunio-mobile.enabled' => true, 'clubunio-mobile.native_expenses_enabled' => true, 'clubunio-mobile.uploads_enabled' => true,
            'cache.default' => 'array']);
        Http::preventStrayRequests(); Mail::fake();
        $this->app->instance(\App\ClubUnio\Services\RegistrationBlobStore::class, new class extends \App\ClubUnio\Services\RegistrationBlobStore {
            public function put(string $folder, string $filename, string $bytes, string $mime): void { throw new \RuntimeException('Synthetic provider unavailable'); }
        });
        $this->app->instance(\App\ClubUnio\Services\AzureDocumentReader::class, new class extends \App\ClubUnio\Services\AzureDocumentReader {
            public function read(string $folder, string $filename): string { throw new \RuntimeException('Synthetic provider object absent', 404); }
        });
        Route::prefix('api/mobile/v1/clubs/{tenant}')->middleware(MobileMiddleware::class)->group(fn () => MobileExpenseRoutes::register());
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'mobile-expense-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic expense fixture']);
        config(['clubunio-integrations.azure.tenant_slug' => $this->tenant->slug]);
        $this->trainer = $this->account('trainer'); $this->lead = $this->account('technical_lead'); $this->other = $this->account('member');
        $this->member = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Trainer']);
        $leadMember = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Lead']);
        $otherMember = $this->insert('members', ['first_name' => 'Synthetic', 'last_name' => 'Other']);
        foreach ([[$this->trainer, $this->member],[$this->lead, $leadMember],[$this->other,$otherMember]] as [$actor,$member]) {
            $this->insert('account_member_links', ['account_id' => $actor->id, 'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        }
        $season = $this->insert('seasons', ['name' => 'Fixture season']);
        $this->db->table('clubora.tenant_settings')->insert(['tenant_id' => $this->tenant->id, 'area' => 'general', 'key' => 'SaisonID', 'label' => 'Synthetic season', 'value_type' => 'integer', 'encrypted_value' => Crypt::encryptString((string) $season)]);
        $team = $this->insert('teams', ['name' => 'Fixture team', 'laundry_allowance' => '25']);
        $this->team = $this->insert('team_seasons', ['team_id' => $team, 'season_id' => $season, 'label' => 'Fixture team / season']);
        $this->insert('team_staff', ['team_season_id' => $this->team, 'member_id' => $this->member]);
        $this->insert('technical_leads', ['team_season_id' => $this->team, 'member_id' => $leadMember]);
        $this->bank = $this->insert('banks', ['name' => 'Fixture bank']);
        $this->round = $this->insert('expense_rounds', ['name' => 'Vorrunde', 'code' => 'V']);
        $this->type = $this->insert('expense_types', ['name' => 'Manual fixture', 'receipt_required' => false]);
        foreach (['O','F','Z','R','B'] as $code) $this->insert('expense_statuses', ['name' => $code, 'code' => $code]);
        $this->url = '/api/mobile/v1/clubs/'.$this->tenant->id.'/expenses';
        // Exercise migration88 on empty private temporary tables, never shared DEV DDL.
        $this->db->statement('CREATE TEMP TABLE expense_draft_tenants (id bigint PRIMARY KEY) ON COMMIT DROP');
        $this->db->statement('CREATE TEMP TABLE expense_draft_accounts (id bigint PRIMARY KEY) ON COMMIT DROP');
        $this->db->table('pg_temp.expense_draft_tenants')->insert(['id' => $this->tenant->id]);
        foreach ([$this->trainer,$this->lead,$this->other] as $actor) $this->db->table('pg_temp.expense_draft_accounts')->insert(['id' => $actor->id]);
        $this->db->statement('CREATE TEMP TABLE expenses (LIKE clubora.expenses INCLUDING ALL) ON COMMIT DROP');
        $this->db->statement('CREATE TEMP TABLE expense_events (LIKE clubora.expense_events INCLUDING ALL) ON COMMIT DROP');
        $this->db->statement('CREATE TEMP TABLE notifications (LIKE clubora.notifications INCLUDING ALL) ON COMMIT DROP');
        $this->db->statement('CREATE TEMP TABLE expense_lines (LIKE clubora.expense_lines INCLUDING ALL) ON COMMIT DROP');
        $this->db->statement('CREATE TEMP TABLE document_uploads (LIKE clubora.document_uploads INCLUDING ALL) ON COMMIT DROP');
        $ddl = $this->db->pretend(fn () => (require database_path('clubunio/2026_11_03_000088_mobile_expense_receipts.php'))->up());
        $sql = str_replace(['CREATE TABLE clubora.mobile_expense_receipts', 'CREATE TABLE clubora.mobile_expense_commands', 'clubora.mobile_expense_receipts', 'clubora.mobile_expense_commands', 'REFERENCES clubora.tenants', 'REFERENCES clubora.accounts', 'REFERENCES clubora.expense_lines', 'REFERENCES clubora.document_uploads', 'REFERENCES clubora.expenses'],
            ['CREATE TEMP TABLE mobile_expense_receipts', 'CREATE TEMP TABLE mobile_expense_commands', 'pg_temp.mobile_expense_receipts', 'pg_temp.mobile_expense_commands', 'REFERENCES pg_temp.expense_draft_tenants', 'REFERENCES pg_temp.expense_draft_accounts', 'REFERENCES pg_temp.expense_lines', 'REFERENCES pg_temp.document_uploads', 'REFERENCES pg_temp.expenses'], $ddl[0]['query']);
        $sql = str_replace(");\nCREATE INDEX", ") ON COMMIT DROP;\nCREATE INDEX", $sql);
        $sql = str_replace(");\nREVOKE ALL ON pg_temp.mobile_expense_commands", ") ON COMMIT DROP;\nREVOKE ALL ON pg_temp.mobile_expense_commands", $sql);
        $this->db->unprepared($sql);
        $this->db->setQueryGrammar(new class($this->db) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function wrapTable($table, $prefix = null) { return parent::wrapTable(str_replace(['clubora.mobile_expense_receipts','clubora.mobile_expense_commands','clubora.expense_lines','clubora.document_uploads','clubora.expenses','clubora.expense_events','clubora.notifications'], ['pg_temp.mobile_expense_receipts','pg_temp.mobile_expense_commands','pg_temp.expense_lines','pg_temp.document_uploads','pg_temp.expenses','pg_temp.expense_events','pg_temp.notifications'], $table), $prefix); }
            public function wrap($value, $prefixAlias = false) { return parent::wrap(is_string($value) ? str_replace(['clubora.mobile_expense_receipts.','clubora.mobile_expense_commands.','clubora.expense_lines.','clubora.document_uploads.','clubora.expenses.','clubora.expense_events.','clubora.notifications.'], ['pg_temp.mobile_expense_receipts.','pg_temp.mobile_expense_commands.','pg_temp.expense_lines.','pg_temp.document_uploads.','pg_temp.expenses.','pg_temp.expense_events.','pg_temp.notifications.'], $value) : $value, $prefixAlias); }
        });
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner { public function scan(string $bytes): array { return ['clean' => !str_contains($bytes, 'MALWARE-FIXTURE'), 'version' => 'synthetic-scanner-v1']; } });
    }
    protected function tearDown(): void {
        if (isset($this->db) && $this->db->transactionLevel()) $this->db->rollBack();
        parent::tearDown();
    }
    private function insert(string $table, array $data): int { return $this->db->table('clubora.'.$table)->insertGetId($data + ['tenant_id' => $this->tenant->id]); }
    private function account(string $role): Account {
        $actor = new Account; $actor->forceFill(['username' => 'expense-fixture-'.bin2hex(random_bytes(8)), 'display_name' => 'Fixture', 'password' => bcrypt('fixture'), 'active' => true, 'api_only' => false])->save();
        $this->insert('tenant_accounts', ['account_id' => $actor->id, 'role' => $role, 'portal_enabled' => true]); return $actor;
    }
    private function token(Account $actor): string { return $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'fixture', 'device_name' => 'Expense fixture'])->assertOk()->json('data.token'); }
    private function expense(): int {
        $bank = $this->withToken($this->token($this->trainer))->postJson($this->url.'/banks', ['member_id' => $this->member, 'bank_id' => $this->bank, 'iban' => 'CH9300762011623852957'])->assertOk()->json('data.bank.BankkontoStaffID');
        $input = ['command_key' => (string) Str::uuid(), 'member_id' => $this->member, 'team_season_id' => $this->team, 'bank_account_id' => $bank, 'round_id' => $this->round, 'notes' => 'Fixture'];
        $response = $this->postJson($this->url, $input);
        $id = $response->assertOk()->json('data.receipt.SpesenID');
        $this->postJson($this->url, $input)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.receipt.SpesenID', $id);
        $this->postJson($this->url, array_replace($input, ['notes' => 'Different fixture']))->assertConflict();
        return $id;
    }
    private function pdf(string $marker = 'Synthetic'): UploadedFile { return UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\n".$marker."\n%%EOF\n"); }
    public function test_personal_expense_core_preserves_visibility_versions_bank_privacy_and_workflow(): void {
        $expense = $this->expense();
        $this->getJson($this->url.'/options')->assertOk()->assertJsonPath('data.teams.0.id', $this->team)->assertJsonPath('data.capabilities.ai', false);
        $lineInput = ['command_key' => (string) Str::uuid(), 'expense_revision' => 1, 'expense_type_id' => $this->type, 'amount' => '12.50', 'notes' => 'Synthetic position'];
        $line = $this->postJson($this->url.'/'.$expense.'/lines', $lineInput)->assertOk()->assertJsonPath('data.receipt.Revision', 2)->json('data.line_id');
        $this->postJson($this->url.'/'.$expense.'/lines', array_replace($lineInput, ['expense_revision' => '1', 'amount' => '12.5000']))->assertOk()->assertJsonPath('data.line_id', $line)->assertJsonPath('data.replayed', true);
        $this->postJson($this->url.'/'.$expense.'/lines', array_replace($lineInput, ['amount' => '99']))->assertConflict();
        $headerKey = $this->db->table('clubora.mobile_expense_commands')->where('expense_id', $expense)->where('operation', 'create_receipt')->value('command_key');
        $this->postJson($this->url.'/'.$expense.'/lines', array_replace($lineInput, ['command_key' => $headerKey]))->assertConflict();
        $this->patchJson($this->url.'/'.$expense.'/lines/'.$line, ['expense_revision' => 1, 'revision' => 1, 'expense_type_id' => $this->type, 'amount' => '99'])->assertUnprocessable();
        $this->withToken($this->token($this->other))->getJson($this->url.'/'.$expense)->assertNotFound();
        $leadToken = $this->token($this->lead);
        $this->withToken($leadToken)->getJson($this->url.'/'.$expense)->assertOk()->assertDontSee('CH9300762011623852957')->assertJsonPath('data.receipt.AllowedStatusCodes', []);
        $this->getJson($this->url.'/banks')->assertOk()->assertJsonCount(0, 'data.rows');
        $this->withToken($this->token($this->trainer))->postJson($this->url.'/'.$expense.'/transition', ['revision' => 2, 'status' => 'F', 'reason' => 'Synthetic release reason'])->assertOk()->assertJsonPath('data.receipt.Revision', 3);
        $this->postJson($this->url.'/'.$expense.'/transition', ['revision' => 3, 'status' => 'Z', 'reason' => 'Synthetic approval reason'])->assertForbidden();
        $this->withToken($leadToken)->postJson($this->url.'/'.$expense.'/transition', ['revision' => 3, 'status' => 'Z', 'reason' => 'Synthetic approval reason'])->assertOk()->assertJsonPath('data.receipt.Status', 'Z');
        $this->postJson($this->url.'/'.$expense.'/transition', ['revision' => 4, 'status' => 'B', 'reason' => 'No real payment in tests'])->assertUnprocessable();
        $this->deleteJson($this->url.'/'.$expense.'/lines/'.$line, ['expense_revision' => 4, 'revision' => 1])->assertForbidden();
        config(['clubunio-mobile.native_expenses_enabled' => false]);
        $this->getJson($this->url)->assertStatus(503);
    }
    public function test_scanned_receipt_binding_is_scoped_atomic_idempotent_and_removes_duplicate_cipherbytes(): void {
        $expense = $this->expense(); $client = (string) Str::uuid();
        $upload = $this->post($this->url.'/receipts', ['client_id' => $client, 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertOk();
        $token = $upload->json('data.receiptToken');
        $this->post($this->url.'/receipts', ['client_id' => $client, 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.receiptToken', $token);
        $this->post($this->url.'/receipts', ['client_id' => $client, 'file' => $this->pdf('different')], ['Accept' => 'application/json'])->assertConflict();
        $this->withToken($this->token($this->other))->deleteJson($this->url.'/receipts/'.$token)->assertNotFound();
        $this->withToken($this->token($this->trainer));
        $data = ['command_key' => (string) Str::uuid(), 'expense_revision' => 1, 'expense_type_id' => $this->type, 'amount' => '12.50', 'notes' => 'Synthetic position', 'receipt_token' => $token, 'receipt_revision' => 1];
        $result = $this->postJson($this->url.'/'.$expense.'/lines', $data)->assertOk()->assertJsonPath('data.upload_state', 'pending');
        $line = $result->json('data.line_id');
        $this->postJson($this->url.'/'.$expense.'/lines', $data)->assertOk()->assertJsonPath('data.line_id', $line)->assertJsonPath('data.replayed', true);
        $this->postJson($this->url.'/'.$expense.'/lines', array_replace($data, ['amount' => '99']))->assertConflict();
        $this->assertSame(1, $this->db->table('clubora.expense_lines')->where('expense_id', $expense)->count());
        $row = $this->db->table('clubora.mobile_expense_receipts')->where('id', $token)->first();
        $this->assertSame('bound', $row->state); $this->assertNull($row->bytes_encrypted);
        $this->assertSame($line, (int) $row->bound_line_id);
        $stored = $this->db->table('clubora.document_uploads')->where('id', $row->bound_upload_id)->first();
        $this->assertSame('pending', $stored->state); $this->assertNotNull($stored->encrypted_bytes);
        $this->assertSame($row->sha256, hash('sha256', Crypt::decryptString($stored->encrypted_bytes)));
        $this->deleteJson($this->url.'/receipts/'.$token)->assertConflict();
        $this->postJson($this->url.'/'.$expense.'/lines', ['command_key' => (string) Str::uuid(), 'expense_revision' => 2, 'expense_type_id' => $this->type, 'amount' => '2'])->assertConflict();
        $this->postJson($this->url.'/'.$expense.'/uploads/'.$row->bound_upload_id.'/retry')->assertOk()->assertJsonPath('data.upload_state', 'pending');
        $this->postJson($this->url.'/'.$expense.'/transition', ['revision' => 2, 'status' => 'F', 'reason' => 'Synthetic release reason'])->assertUnprocessable();
        $this->postJson($this->url.'/'.$expense.'/transition', ['revision' => 2, 'status' => 'R', 'reason' => 'Synthetic rejection reason'])->assertConflict();
        $this->postJson($this->url.'/receipts/'.$token.'/suggest', ['request_key'=>(string) Str::uuid(), 'draft_revision'=>1, 'consent'=>true])->assertConflict(); // Bound drafts cannot be analysed.
        $this->post($this->url.'/receipts', ['client_id' => (string) Str::uuid(), 'file' => $this->pdf('MALWARE-FIXTURE')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(1, $this->db->table('clubora.mobile_expense_receipts')->count());
        // Exercise the real private document authorization with synthetic provider bytes only.
        $this->app->instance(\App\ClubUnio\Services\RegistrationBlobStore::class, new class extends \App\ClubUnio\Services\RegistrationBlobStore {
            public function put(string $folder, string $filename, string $bytes, string $mime): void { /* Synthetic provider boundary; no external write. */ }
        });
        $this->postJson($this->url.'/'.$expense.'/uploads/'.$row->bound_upload_id.'/retry')->assertOk()->assertJsonPath('data.upload_state', 'published')->assertJsonPath('data.receipt.PendingUploadCount', 0);
        $this->assertNull($this->db->table('clubora.document_uploads')->where('id', $stored->id)->value('encrypted_bytes'));
        $this->app->instance(\App\ClubUnio\Services\AzureDocumentReader::class, new class extends \App\ClubUnio\Services\AzureDocumentReader {
            public function read(string $folder, string $filename): string { $this->blobPath($folder, $filename); return "%PDF-1.4\nSynthetic private receipt\n%%EOF\n"; }
        });
        $fileUrl = $this->url.'/lines/'.$line.'/file';
        $this->get($fileUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->get($fileUrl.'?index=1')->assertNotFound();
        $this->withToken($this->token($this->other))->get($fileUrl)->assertNotFound();
        $this->withToken($this->token($this->trainer))->getJson($this->url.'/'.$expense)->assertOk()->assertDontSee('azure.blob')->assertDontSee('clubunio/expenses/');
        $this->db->table('clubora.expense_lines')->where('id', $line)->update(['storage_reference' => 'clubunio/expenses/999999/'.$line.'/'.Str::uuid().'.pdf']);
        $this->get($fileUrl)->assertNotFound();
    }
    public function test_receipt_expiry_discard_scanner_failure_and_size_limits_never_release_bytes(): void {
        $this->withToken($this->token($this->trainer));
        $client = (string) Str::uuid();
        $token = $this->post($this->url.'/receipts', ['client_id' => $client, 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertOk()->json('data.receiptToken');
        $row = $this->db->table('clubora.mobile_expense_receipts')->where('id', $token)->first();
        $this->assertSame('clean', $row->state);
        $this->assertStringNotContainsString('%PDF-', $row->bytes_encrypted);
        $this->deleteJson($this->url.'/receipts/'.$token)->assertOk()->assertJsonPath('data.discarded', true);
        $this->deleteJson($this->url.'/receipts/'.$token)->assertOk();
        $this->assertNull($this->db->table('clubora.mobile_expense_receipts')->where('id', $token)->value('bytes_encrypted'));
        $this->post($this->url.'/receipts', ['client_id' => $client, 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertConflict();
        $fresh = $this->post($this->url.'/receipts', ['client_id' => (string) Str::uuid(), 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertOk()->json('data.receiptToken');
        $this->travel(25)->hours();
        try {
            $this->assertSame(1, app(MobileExpenseReceipts::class)->expire($this->tenant));
            $expired = $this->db->table('clubora.mobile_expense_receipts')->where('id', $fresh)->first();
            $this->assertSame('expired', $expired->state); $this->assertNull($expired->bytes_encrypted);
        } finally { $this->travelBack(); }
        $this->post($this->url.'/receipts', ['client_id' => (string) Str::uuid(), 'file' => UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->app->instance(MalwareScanner::class, new class implements MalwareScanner { public function scan(string $bytes): array { abort(503); } });
        $this->post($this->url.'/receipts', ['client_id' => (string) Str::uuid(), 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertStatus(503);
        $this->assertSame(2, $this->db->table('clubora.mobile_expense_receipts')->count());
        config(['clubunio-integrations.azure.tenant_slug' => 'not-this-synthetic-tenant']);
        $this->getJson($this->url.'/options')->assertOk()->assertJsonPath('data.capabilities.upload', false);
        $scanner = new class implements MalwareScanner { public bool $called = false; public function scan(string $bytes): array { $this->called = true; abort(500); } };
        $this->app->instance(MalwareScanner::class, $scanner);
        $this->post($this->url.'/receipts', ['client_id' => (string) Str::uuid(), 'file' => $this->pdf()], ['Accept' => 'application/json'])->assertStatus(503);
        $this->assertFalse($scanner->called);
        $this->withHeader('Authorization', '')->getJson($this->url)->assertUnauthorized();
    }
}

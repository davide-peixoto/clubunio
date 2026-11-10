<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\{MobileExpenseOcrController, MobileMiddleware};
use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{Crypt, DB, Http, Route};
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileExpenseOcrTest extends TestCase
{
    private $db;
    private Tenant $tenant;
    private Account $actor;
    private Account $other;
    private string $receipt;
    private string $url;
    private string $bytes = "%PDF-1.4\nSynthetic CHF receipt\n%%EOF\n";
    private string $origin = 'https://clubunio-ocr-fixture.cognitiveservices.azure.com';
    private string $job = '11111111-2222-4333-8444-555555555555';
    protected function setUp(): void {
        parent::setUp();
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Opt-in rolled-back PostgreSQL fixture required');
        config(['clubunio-mobile.enabled' => true, 'clubunio-mobile.native_expenses_enabled' => true, 'cache.default' => 'array']);
        Http::preventStrayRequests();
        Route::post('api/mobile/v1/clubs/{tenant}/expenses/receipts/{receipt}/suggest', [MobileExpenseOcrController::class, 'suggest'])
            ->middleware(MobileMiddleware::class)->whereNumber('tenant')->whereUuid('receipt');
        $this->db = DB::connection('clubunio');
        $this->assertSame('db_acdf8d_dclubora', $this->db->getDatabaseName());
        $this->db->beginTransaction();
        $this->tenant = Tenant::create(['slug' => 'expense-ocr-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic OCR fixture']);
        $this->actor = $this->actor('Owner'); $this->other = $this->actor('Other');
        // Only this fixture draft table is redirected. Actor/session/rights and the shared cost ledger remain real services.
        $this->db->statement('CREATE TEMP TABLE expense_ocr_receipts (id uuid PRIMARY KEY, tenant_id bigint NOT NULL, account_id bigint NOT NULL,
            state text NOT NULL, revision integer NOT NULL, bytes_encrypted text, filename_encrypted text NOT NULL, sha256 char(64) NOT NULL,
            byte_count integer NOT NULL, mime_type text NOT NULL, scanned_at timestamptz NOT NULL, scanner_version text NOT NULL, expires_at timestamptz NOT NULL) ON COMMIT DROP');
        $this->db->setQueryGrammar(new class($this->db) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
            public function wrapTable($table, $prefix = null) { return parent::wrapTable($table === 'clubunio.mobile_expense_receipts' ? 'pg_temp.expense_ocr_receipts' : $table, $prefix); }
        });
        $this->receipt = $this->draft();
        $this->url = '/api/mobile/v1/clubs/'.$this->tenant->id.'/expenses/receipts/'.$this->receipt.'/suggest';
        config(['clubunio-expense-ocr.enabled' => true, 'clubunio-expense-ocr.tenant_slugs' => [$this->tenant->slug],
            'clubunio-expense-ocr.cost_reviewed' => true, 'clubunio-expense-ocr.reservation_microusd' => 1000,
            'clubunio-expense-ocr.monthly_limit_microusd' => 10000, 'clubunio-expense-ocr.endpoint' => $this->origin,
            'clubunio-expense-ocr.key' => 'synthetic-ocr-key-not-a-secret', 'clubunio-expense-ocr.daily_requests_per_account' => 10]);
    }
    protected function tearDown(): void {
        if (isset($this->db) && $this->db->transactionLevel()) $this->db->rollBack();
        parent::tearDown();
    }
    public function test_options_require_both_compatible_client_and_explicit_provider_configuration(): void {
        $data = app(\App\ClubUnio\Mobile\MobileExpenseData::class);
        $this->assertFalse($data->options($this->actor, $this->tenant)['capabilities']['ai']);
        request()->headers->set('X-ClubUnio-Mobile-Features', 'expense-ocr-v1');
        $this->assertTrue($data->options($this->actor, $this->tenant)['capabilities']['ai']);
        config(['clubunio-expense-ocr.cost_reviewed' => false]);
        $this->assertFalse($data->options($this->actor, $this->tenant)['capabilities']['ai']);
        config(['clubunio-expense-ocr.cost_reviewed' => true, 'clubunio-expense-ocr.tenant_slugs' => []]);
        $this->assertFalse($data->options($this->actor, $this->tenant)['capabilities']['ai']);
    }
    private function actor(string $name): Account {
        $actor = new Account;
        $actor->forceFill(['username' => 'ocr-'.bin2hex(random_bytes(8)), 'display_name' => $name, 'password' => bcrypt('fixture'), 'active' => true, 'api_only' => false])->save();
        $this->db->table('clubunio.tenant_accounts')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'role' => 'trainer', 'portal_enabled' => true]);
        $member = $this->db->table('clubunio.members')->insertGetId(['tenant_id' => $this->tenant->id, 'first_name' => 'Synthetic', 'last_name' => $name]);
        $this->db->table('clubunio.account_member_links')->insert(['tenant_id' => $this->tenant->id, 'account_id' => $actor->id, 'member_id' => $member, 'kind' => 'self', 'state' => 'approved', 'source' => 'test']);
        return $actor;
    }
    private function draft(): string {
        $id = (string) Str::uuid();
        $this->db->table('pg_temp.expense_ocr_receipts')->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id,
            'state' => 'clean', 'revision' => 1, 'bytes_encrypted' => Crypt::encryptString($this->bytes), 'filename_encrypted' => Crypt::encryptString('synthetic.pdf'),
            'sha256' => hash('sha256', $this->bytes), 'byte_count' => strlen($this->bytes), 'mime_type' => 'application/pdf',
            'scanned_at' => now()->toIso8601String(), 'scanner_version' => 'synthetic-scanner-v1', 'expires_at' => now()->addDay()->toIso8601String()]);
        return $id;
    }
    private function token(?Account $actor = null): string {
        $actor ??= $this->actor;
        return $this->postJson('/api/mobile/v1/session', ['username' => $actor->username, 'password' => 'fixture', 'device_name' => 'OCR fixture'])->assertOk()->json('data.token');
    }
    private function input(?string $key = null): array { return ['request_key' => $key ?? (string) Str::uuid(), 'draft_revision' => 1, 'consent' => true]; }
    private function providerResult(array $overrides = []): array {
        return array_replace_recursive(['status' => 'succeeded', 'analyzeResult' => ['apiVersion' => '2024-11-30', 'modelId' => 'prebuilt-receipt',
            'pages' => [['pageNumber' => 1]], 'documents' => [['fields' => [
                'Total' => ['type' => 'currency', 'confidence' => 0.98, 'valueCurrency' => ['amount' => 12.50, 'currencyCode' => 'CHF']],
                'MerchantName' => ['type' => 'string', 'confidence' => 0.95, 'valueString' => 'Synthetic Merchant'],
                'TransactionDate' => ['type' => 'date', 'confidence' => 0.97, 'valueDate' => '2026-11-01'],
            ]]]]], $overrides);
    }
    private function fakeProvider(array $result): void {
        $this->fakeHttp(function ($request) use ($result) {
            if ($request->method() === 'POST') return Http::response('', 202, ['Operation-Location' => $this->origin.'/documentintelligence/documentModels/prebuilt-receipt/analyzeResults/'.$this->job.'?api-version=2024-11-30']);
            return Http::response($result, 200);
        });
    }
    private function fakeHttp(?callable $callback = null): void {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests(); Http::fake($callback);
    }
    public function test_verified_chf_receipt_suggestion_is_personal_versioned_cached_and_cost_bounded(): void {
        $this->fakeProvider($this->providerResult()); $input = $this->input();
        $this->withToken($this->token())->postJson($this->url, $input)->assertOk()->assertJsonPath('data.state', 'succeeded')
            ->assertJsonPath('data.suggestion.Betrag', 12.5)->assertJsonPath('data.suggestion.BemerkungenPos', 'Synthetic Merchant · 01.11.2026')
            ->assertJsonPath('data.suggestion.Gegner', null)->assertJsonPath('data.suggestion.Spieltyp', null)->assertJsonPath('data.suggestion.SpesenTypID', null);
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_contains($r->url(), 'pages=1') && $r['base64Source'] === base64_encode($this->bytes)
            && ! isset($r['urlSource']) && $r->hasHeader('Ocp-Apim-Subscription-Key', 'synthetic-ocr-key-not-a-secret'));
        $this->postJson($this->url, $input)->assertOk()->assertJsonPath('data.cached', true);
        $this->postJson($this->url, $this->input())->assertOk()->assertJsonPath('data.cached', true);
        Http::assertSentCount(2);
        $rows = $this->db->table('clubunio.mobile_ai_work')->where('tenant_id', $this->tenant->id)->orderBy('reserved_microusd')->get();
        $this->assertCount(2, $rows); $this->assertSame([0,1000], $rows->pluck('reserved_microusd')->map(fn ($v) => (int) $v)->all());
        $this->assertStringNotContainsString('Synthetic Merchant', $rows[1]->result_encrypted);
        $this->assertSame('expense_ocr', $rows[1]->operation);
        $this->assertSame(1, (int) $rows[1]->revision);
        $otherDraft = $this->draft();
        $otherUrl = str_replace($this->receipt, $otherDraft, $this->url);
        $this->postJson($otherUrl, $input)->assertConflict(); Http::assertSentCount(2);
        $this->withToken($this->token($this->other))->postJson($this->url, $input)->assertNotFound(); Http::assertSentCount(2);
    }
    public function test_known_pending_job_resumes_by_get_and_never_posts_analysis_twice(): void {
        $pending = true;
        $this->fakeHttp(function ($request) use (&$pending) {
            if ($request->method() === 'POST') return Http::response('', 202, ['Operation-Location' => $this->origin.'/documentintelligence/documentModels/prebuilt-receipt/analyzeResults/'.$this->job.'?api-version=2024-11-30']);
            return Http::response($pending ? ['status' => 'running'] : $this->providerResult(), 200);
        });
        $input = $this->input();
        $this->withToken($this->token())->postJson($this->url, $input)->assertStatus(202)->assertJsonPath('data.state', 'pending');
        $this->postJson($this->url, $input)->assertStatus(202); Http::assertSentCount(2);
        $this->travel(4)->seconds();
        try { $pending = false; $this->postJson($this->url, $input)->assertOk()->assertJsonPath('data.state', 'succeeded'); }
        finally { $this->travelBack(); }
        Http::assertSentCount(3);
        $this->assertSame(1, Http::recorded()->filter(fn ($pair) => $pair[0]->method() === 'POST')->count());
    }
    public function test_consent_provider_configuration_and_monthly_budget_fail_before_a_paid_request(): void {
        $this->fakeHttp(); $this->withToken($this->token());
        $this->postJson($this->url, array_replace($this->input(), ['consent' => false]))->assertUnprocessable();
        config(['clubunio-expense-ocr.enabled' => false]); $this->postJson($this->url, $this->input())->assertStatus(503);
        config(['clubunio-expense-ocr.enabled' => true, 'clubunio-expense-ocr.endpoint' => 'https://untrusted.example']); $this->postJson($this->url, $this->input())->assertStatus(503);
        config(['clubunio-expense-ocr.endpoint' => $this->origin, 'clubunio-expense-ocr.reservation_microusd' => 0]); $this->postJson($this->url, $this->input())->assertStatus(503);
        config(['clubunio-expense-ocr.reservation_microusd' => 1000]);
        $this->db->table('clubunio.mobile_ai_work')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'account_id' => $this->actor->id,
            'request_key' => (string) Str::uuid(), 'fingerprint' => str_repeat('a', 64), 'provider' => 'synthetic-budget', 'operation' => 'correct', 'state' => 'attention', 'reserved_microusd' => 9500]);
        $this->postJson($this->url, $this->input())->assertStatus(429); Http::assertNothingSent();
        $this->assertSame(1, $this->db->table('clubunio.mobile_ai_work')->where('tenant_id', $this->tenant->id)->count());
    }
    public function test_untrusted_operation_location_and_uncertain_post_are_never_retried_or_logged_as_results(): void {
        $this->fakeHttp(fn () => Http::response('', 202, ['Operation-Location' => 'https://untrusted.example/analyzeResults/'.$this->job]));
        $input = $this->input();
        $this->withToken($this->token())->postJson($this->url, $input)->assertStatus(502)->assertDontSee('untrusted.example');
        $this->postJson($this->url, $input)->assertConflict();
        $this->postJson($this->url, $this->input())->assertConflict();
        Http::assertSentCount(1);
        $row = $this->db->table('clubunio.mobile_ai_work')->where('tenant_id', $this->tenant->id)->sole();
        $this->assertSame('attention', $row->state); $this->assertNull($row->result_encrypted); $this->assertSame(1000, (int) $row->reserved_microusd);
    }
    public function test_expiry_revision_corruption_and_authority_change_never_release_provider_fields(): void {
        $input = $this->input();
        $this->withToken($this->token()); $this->fakeHttp();
        $this->postJson($this->url, array_replace($input, ['draft_revision' => 2]))->assertConflict();
        $this->db->table('pg_temp.expense_ocr_receipts')->where('id', $this->receipt)->update(['sha256' => str_repeat('f', 64)]);
        $this->postJson($this->url, $input)->assertConflict(); Http::assertNothingSent();
        $this->db->table('pg_temp.expense_ocr_receipts')->where('id', $this->receipt)->update(['sha256' => hash('sha256', $this->bytes)]);
        $this->travel(25)->hours();
        try { $this->postJson($this->url, $input)->assertConflict(); } finally { $this->travelBack(); }
        $this->fakeHttp(function ($request) {
            if ($request->method() === 'POST') return Http::response('', 202, ['Operation-Location' => $this->origin.'/documentintelligence/documentModels/prebuilt-receipt/analyzeResults/'.$this->job.'?api-version=2024-11-30']);
            $this->db->table('pg_temp.expense_ocr_receipts')->where('id', $this->receipt)->update(['state' => 'discarded', 'bytes_encrypted' => null]);
            return Http::response($this->providerResult(), 200);
        });
        $this->postJson($this->url, $input)->assertConflict()->assertDontSee('Synthetic Merchant');
        $this->assertSame('attention', $this->db->table('clubunio.mobile_ai_work')->where('tenant_id', $this->tenant->id)->value('state'));
    }
    public function test_unknown_currency_low_confidence_and_extra_documents_never_guess_financial_values(): void {
        $result = $this->providerResult(['analyzeResult' => ['documents' => [['fields' => ['Total' => ['valueCurrency' => ['currencyCode' => 'EUR']],
            'MerchantName' => ['confidence' => 0.2], 'TransactionDate' => ['confidence' => 0.2]]]]]]);
        $this->fakeProvider($result);
        $this->withToken($this->token())->postJson($this->url, $this->input())->assertOk()->assertJsonPath('data.suggestion.Betrag', null)
            ->assertJsonPath('data.suggestion.BemerkungenPos', null)->assertJsonPath('data.confidence', 0);
        $draft = $this->draft(); $url = str_replace($this->receipt, $draft, $this->url);
        $malformed = $this->providerResult(); $malformed['analyzeResult']['documents'][] = $malformed['analyzeResult']['documents'][0];
        $this->fakeProvider($malformed);
        $this->postJson($url, $this->input())->assertStatus(502)->assertDontSee('Synthetic Merchant');
    }
    public function test_parallel_start_and_daily_limit_cannot_submit_duplicate_paid_analysis(): void {
        config(['clubunio-expense-ocr.daily_requests_per_account' => 1]);
        $input = $this->input(); $duplicateRejected = false;
        $this->fakeHttp(function ($request) use ($input, &$duplicateRejected) {
            if ($request->method() === 'POST') {
                try { app(\App\ClubUnio\Mobile\MobileExpenseOcr::class)->execute($this->actor, $this->tenant, $this->receipt, $input); }
                catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $duplicateRejected = $error->getStatusCode() === 409; }
                return Http::response('', 202, ['Operation-Location' => $this->origin.'/documentintelligence/documentModels/prebuilt-receipt/analyzeResults/'.$this->job.'?api-version=2024-11-30']);
            }
            return Http::response($this->providerResult(), 200);
        });
        $this->withToken($this->token())->postJson($this->url, $input)->assertOk();
        $this->assertTrue($duplicateRejected); Http::assertSentCount(2);
        $next = str_replace($this->receipt, $this->draft(), $this->url);
        $this->postJson($next, $this->input())->assertStatus(429); Http::assertSentCount(2);
        $this->assertSame(1, $this->db->table('clubunio.mobile_ai_work')->where('tenant_id', $this->tenant->id)->count());
    }
    public function test_provider_response_limit_and_revoked_membership_never_expose_a_result(): void {
        $input = $this->input();
        $this->fakeHttp(function ($request) {
            if ($request->method() === 'POST') return Http::response('', 202, ['Operation-Location' => $this->origin.'/documentintelligence/documentModels/prebuilt-receipt/analyzeResults/'.$this->job.'?api-version=2024-11-30']);
            return Http::response('{}', 200, ['Content-Length' => (2 * 1024 * 1024) + 1]);
        });
        $this->withToken($this->token())->postJson($this->url, $input)->assertStatus(202)->assertJsonMissingPath('data.suggestion');
        Http::assertSentCount(2);
        $this->fakeHttp(function ($request) {
            $this->assertSame('GET', $request->method());
            $this->db->table('clubunio.tenant_accounts')->where('tenant_id', $this->tenant->id)->where('account_id', $this->actor->id)->update(['portal_enabled' => false]);
            return Http::response($this->providerResult(), 200);
        });
        $this->travel(4)->seconds();
        try { $this->postJson($this->url, $input)->assertConflict()->assertDontSee('Synthetic Merchant'); }
        finally { $this->travelBack(); }
        Http::assertSentCount(1);
        $this->assertSame('attention', $this->db->table('clubunio.mobile_ai_work')->where('tenant_id', $this->tenant->id)->value('state'));
    }
}

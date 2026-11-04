<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\MobileExpenseOcrProvenance;
use App\ClubUnio\Models\{ExpenseLine, Tenant};
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Support\Str;
use Tests\TestCase;

/** PostgreSQL projections only: isolated TEMP tables, no real business rows/FKs. */
final class MobileProjectionPerformanceTest extends TestCase
{
    public function test_postgres_chat_bulk_membership_join_preserves_active_watermarks_and_tenant_foreign_keys(): void
    {
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Opt-in rollback PostgreSQL projection fixture');
        Http::preventStrayRequests();
        $db = DB::connection('clubunio'); self::assertSame('db_acdf8d_dclubora', $db->getDatabaseName());
        $grammar = $db->getQueryGrammar(); $db->beginTransaction();
        try {
            $db->statement("SET LOCAL application_name TO 'clubunio-performance-chat-list-test'");
            $db->statement("SET LOCAL lock_timeout TO '2s'"); $db->statement("SET LOCAL statement_timeout TO '15s'");
            $tenant = Tenant::create(['slug' => 'perf-chat-'.bin2hex(random_bytes(6)), 'name' => 'Synthetic chat projection']);
            $actor = new \App\ClubUnio\Models\Account;
            $actor->forceFill(['username' => 'perf-chat-'.bin2hex(random_bytes(6)),
                'display_name' => 'Synthetic chat actor', 'password' => 'not-an-authentication-fixture', 'active' => true, 'api_only' => false])->save();
            $db->table('clubora.tenant_accounts')->insert(['tenant_id' => $tenant->id, 'account_id' => $actor->id, 'role' => 'admin', 'portal_enabled' => true]);
            $season = $db->table('clubora.seasons')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Synthetic season', 'active' => true]);
            $db->table('clubora.tenant_settings')->insert(['tenant_id' => $tenant->id, 'area' => 'general', 'key' => 'SaisonID',
                'label' => 'Synthetic current season', 'value_type' => 'integer', 'encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString((string) $season)]);
            $team = $db->table('clubora.teams')->insertGetId(['tenant_id' => $tenant->id, 'name' => 'Synthetic team', 'active' => true]);
            $db->table('clubora.team_seasons')->insert(['tenant_id' => $tenant->id, 'team_id' => $team, 'season_id' => $season, 'label' => 'Synthetic team season', 'active' => true]);
            // LIKE INCLUDING ALL copies indexes/checks, never FKs. All added FK parents are TEMP.
            $db->unprepared(<<<'SQL'
CREATE TEMP TABLE performance_chat_tenants (id bigint PRIMARY KEY) ON COMMIT DROP;
CREATE TEMP TABLE performance_chat_accounts (id bigint PRIMARY KEY) ON COMMIT DROP;
CREATE TEMP TABLE performance_chats (LIKE clubora.chat_conversations INCLUDING ALL) ON COMMIT DROP;
CREATE TEMP TABLE performance_memberships (LIKE clubora.chat_memberships INCLUDING ALL) ON COMMIT DROP;
CREATE TEMP TABLE performance_messages (LIKE clubora.chat_messages INCLUDING ALL) ON COMMIT DROP;
ALTER TABLE pg_temp.performance_chats ADD FOREIGN KEY(tenant_id) REFERENCES pg_temp.performance_chat_tenants(id);
ALTER TABLE pg_temp.performance_memberships ADD FOREIGN KEY(tenant_id,conversation_id) REFERENCES pg_temp.performance_chats(tenant_id,id),
 ADD FOREIGN KEY(account_id) REFERENCES pg_temp.performance_chat_accounts(id);
ALTER TABLE pg_temp.performance_messages ADD FOREIGN KEY(tenant_id,conversation_id) REFERENCES pg_temp.performance_chats(tenant_id,id),
 ADD FOREIGN KEY(account_id) REFERENCES pg_temp.performance_chat_accounts(id);
SQL);
            $db->table('pg_temp.performance_chat_tenants')->insert(['id' => $tenant->id]);
            $db->table('pg_temp.performance_chat_accounts')->insert([['id' => $actor->id], ['id' => -1]]);
            $db->setQueryGrammar(new class($db) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
                private function redirect($value) { return is_string($value) ? str_replace(
                    ['clubora.chat_conversations','clubora.chat_memberships','clubora.chat_messages'],
                    ['pg_temp.performance_chats','pg_temp.performance_memberships','pg_temp.performance_messages'], $value) : $value; }
                public function wrapTable($table, $prefix = null) { return parent::wrapTable($this->redirect($table), $prefix); }
                public function wrap($value, $prefixAlias = false) { return parent::wrap($this->redirect($value), $prefixAlias); }
            });
            $service = app(\App\ClubUnio\Mobile\ChatService::class);
            $first = $service->conversations($actor, $tenant); self::assertCount(3, $first);
            $chat = collect($first)->firstWhere('kind', 'team')['id'];
            $memberships = $db->table('pg_temp.performance_memberships')->where('conversation_id', $chat)->where('account_id', $actor->id);
            $memberships->update(['joined_after' => 7, 'read_message_id' => 9, 'muted' => true]);
            foreach ([8,10] as $id) $db->table('pg_temp.performance_messages')->insert(['id' => $id, 'tenant_id' => $tenant->id,
                'conversation_id' => $chat, 'account_id' => -1, 'client_id' => (string) Str::uuid(), 'kind' => 'text',
                'body_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('Synthetic'), 'content_hash' => str_repeat('a',64)]);
            $fresh = collect($service->conversations($actor, $tenant))->firstWhere('id', $chat);
            self::assertSame(1, $fresh['unread']); self::assertTrue($fresh['muted']);
            $kept = (clone $memberships)->first(); self::assertSame(7, (int) $kept->joined_after); self::assertSame(9, (int) $kept->read_message_id);
            $memberships->update(['left_at' => now()->toIso8601String()]);
            $rejoin = collect($service->conversations($actor, $tenant))->firstWhere('id', $chat);
            self::assertSame(0, $rejoin['unread']); self::assertFalse($rejoin['muted']);
            self::assertSame(10, (int) (clone $memberships)->value('joined_after'));
            self::assertSame(10, (int) (clone $memberships)->value('read_message_id'));
            $memberships->update(['left_at' => now()->toIso8601String()]);
            $race = true;
            $db->beforeExecuting(function ($query) use (&$race, $memberships) {
                if ($race && str_starts_with(strtolower($query), 'insert') && str_contains($query, 'performance_memberships')) {
                    $race = false;
                    // Deterministic interleaving: another GET rejoins and advances its read position.
                    (clone $memberships)->update(['left_at' => null, 'joined_after' => 7, 'read_message_id' => 20, 'muted' => true]);
                }
            });
            $raced = collect($service->conversations($actor, $tenant))->firstWhere('id', $chat);
            self::assertFalse($race); self::assertTrue($raced['muted']);
            self::assertSame(20, (int) (clone $memberships)->value('read_message_id'));
            self::assertSame(7, (int) (clone $memberships)->value('joined_after'));
            try {
                $db->transaction(fn () => $db->table('pg_temp.performance_memberships')->insert(['tenant_id' => $tenant->id + 1,
                    'conversation_id' => $chat, 'account_id' => -1]));
                self::fail('Cross-tenant membership passed composite FK');
            } catch (\Illuminate\Database\QueryException $e) { self::assertSame('23503', $e->errorInfo[0]); }
            $db->table('clubora.tenant_accounts')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->delete();
            try { $service->conversations($actor, $tenant); self::fail('Revoked admin entered chat list'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { self::assertSame(403, $e->getStatusCode()); }
            Http::assertNothingSent();
        } finally { $db->setQueryGrammar($grammar); $db->rollBack(); }
    }

    public function test_provenance_batches_have_constant_query_count_boolean_payload_presence_and_exact_source_checks(): void
    {
        if (getenv('CLUBUNIO_TEST_DB') !== '1') $this->markTestSkipped('Opt-in rollback PostgreSQL projection fixture');
        Http::preventStrayRequests();
        $db = DB::connection('clubunio');
        self::assertSame('pgsql', $db->getDriverName());
        self::assertSame('db_acdf8d_dclubora', $db->getDatabaseName());
        $grammar = $db->getQueryGrammar();
        $db->beginTransaction();
        try {
            $db->statement("SET LOCAL application_name TO 'clubunio-performance-projection-test'");
            $db->statement("SET LOCAL lock_timeout TO '2s'");
            $db->statement("SET LOCAL statement_timeout TO '15s'");
            $db->unprepared(<<<'SQL'
CREATE TEMP TABLE performance_provenance (tenant_id bigint, line_id bigint, line_revision integer, draft_id uuid,
 ocr_account_id bigint, draft_revision integer, source_sha256 text, source_replaced boolean, suggested_amount numeric(19,4),
 PRIMARY KEY(tenant_id,line_id,line_revision)) ON COMMIT DROP;
CREATE TEMP TABLE performance_drafts (id uuid PRIMARY KEY, tenant_id bigint, account_id bigint, state text,
 bound_line_id bigint, sha256 text, bound_upload_id bigint, revision integer) ON COMMIT DROP;
CREATE TEMP TABLE performance_uploads (id bigint PRIMARY KEY, tenant_id bigint, object_id bigint, kind text,
 state text, sha256 text, folder text, filename text, encrypted_bytes text) ON COMMIT DROP;
SQL);
            $db->setQueryGrammar(new class($db) extends \Illuminate\Database\Query\Grammars\PostgresGrammar {
                private function redirect($value) { return is_string($value) ? str_replace(
                    ['clubora.mobile_expense_ocr_provenance','clubora.mobile_expense_receipts','clubora.document_uploads'],
                    ['pg_temp.performance_provenance','pg_temp.performance_drafts','pg_temp.performance_uploads'], $value) : $value; }
                public function wrapTable($table, $prefix = null) { return parent::wrapTable($this->redirect($table), $prefix); }
                public function wrap($value, $prefixAlias = false) { return parent::wrap($this->redirect($value), $prefixAlias); }
            });
            config(['clubunio-expense-provenance.enabled' => true]);
            $tenant = new Tenant; $tenant->forceFill(['id' => 100]);
            $lines = collect(); $sha = str_repeat('a', 64);
            for ($id = 1; $id <= 40; $id++) {
                $line = new ExpenseLine; $line->forceFill(['id' => $id, 'tenant_id' => 100, 'amount' => $id === 8 ? '13.0000' : '12.5000',
                    'storage_reference' => 'private/synthetic.pdf']); $lines->push($line);
                if ($id === 13) continue;
                $draft = (string) Str::uuid();
                $db->table('pg_temp.performance_provenance')->insert(['tenant_id' => 100, 'line_id' => $id, 'line_revision' => 1,
                    'draft_id' => $draft, 'ocr_account_id' => 200, 'draft_revision' => 1, 'source_sha256' => $sha,
                    'source_replaced' => $id === 7, 'suggested_amount' => '12.5000']);
                $db->table('pg_temp.performance_drafts')->insert(['id' => $draft, 'tenant_id' => $id === 12 ? 101 : 100,
                    'account_id' => $id === 5 ? 201 : 200, 'state' => 'bound', 'bound_line_id' => $id,
                    'sha256' => $id === 10 ? str_repeat('b',64) : $sha, 'bound_upload_id' => $id, 'revision' => $id === 4 ? 3 : 2]);
                $db->table('pg_temp.performance_uploads')->insert(['id' => $id, 'tenant_id' => 100, 'object_id' => $id === 6 ? 99 : $id,
                    'kind' => 'expense', 'state' => $id === 2 ? 'published' : ($id === 11 ? 'failed' : 'pending'),
                    'sha256' => $id === 9 ? str_repeat('b',64) : $sha, 'folder' => 'private/', 'filename' => 'synthetic.pdf',
                    'encrypted_bytes' => in_array($id,[2,3],true) ? null : str_repeat('private-cipher-marker', 10000)]);
                if ($id === 14) {
                    $record = (array) $db->table('pg_temp.performance_provenance')->where('line_id', $id)->first();
                    $db->table('pg_temp.performance_provenance')->insert(array_replace($record, ['line_revision' => 2, 'source_replaced' => true]));
                }
            }
            // A newer ledger belonging to another tenant cannot hide this tenant's valid proof.
            $record = (array) $db->table('pg_temp.performance_provenance')->where('line_id', 1)->first();
            $db->table('pg_temp.performance_provenance')->insert(array_replace($record, ['tenant_id' => 101, 'line_revision' => 999, 'source_replaced' => true]));
            self::assertTrue($db->selectOne('SELECT encrypted_bytes IS NOT NULL AS has_bytes FROM pg_temp.performance_uploads WHERE id=1')->has_bytes);
            $service = app(MobileExpenseOcrProvenance::class);
            $db->enableQueryLog(); $db->flushQueryLog();
            $one = $service->fieldsForLines($tenant, [$lines->first()]);
            $singleCount = count($db->getQueryLog()); $db->flushQueryLog();
            $many = $service->fieldsForLines($tenant, $lines);
            $queries = $db->getQueryLog(); $db->disableQueryLog();
            self::assertSame(3, $singleCount); self::assertCount($singleCount, $queries);
            self::assertCount(40, $many); self::assertSame($one[1], $many[1]);
            foreach ([1,2,15,40] as $id) self::assertTrue($many[$id]['AiBetragUebernommen']);
            foreach ([3,4,5,6,7,8,9,10,11,12,14] as $id) self::assertFalse($many[$id]['AiBetragUebernommen']);
            self::assertNull($many[13]['AiBetragUebernommen']);
            self::assertSame('12.5000', $many[8]['AiVorschlagBetrag']);
            foreach ($queries as $query) if (str_contains($query['query'], 'performance_uploads')) {
                self::assertStringContainsString('encrypted_bytes IS NOT NULL AS has_bytes', $query['query']);
                self::assertStringNotContainsString('select *', strtolower($query['query']));
            }
            Http::assertNothingSent();
        } finally {
            $db->disableQueryLog(); $db->setQueryGrammar($grammar); $db->rollBack();
        }
    }
}

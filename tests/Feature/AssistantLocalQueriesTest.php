<?php

namespace Tests\Feature;

use App\ClubUnio\Assistant\{AssistantAccess, AssistantProvider, AssistantReadTools, BackofficeAssistantService};
use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{Crypt, DB, Http};
use Tests\TestCase;

final class AssistantLocalQueriesTest extends TestCase
{
    public function test_local_queries_append_encrypted_chat_turns_without_provider_costs(): void
    {
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('clubunio');
        Http::preventStrayRequests();
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.assistant_conversations (
 id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER, transcript_encrypted TEXT,
 busy_run TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE clubora.assistant_actions (
 id INTEGER PRIMARY KEY, tenant_id INTEGER, account_id INTEGER, conversation_id INTEGER);
CREATE TABLE clubora.assistant_runs (
 tenant_id INTEGER, created_at TEXT, actual_microusd INTEGER, reserved_microusd INTEGER);
SQL);
        $actor = (new Account)->forceFill(['id' => 5, 'active' => true]);
        $tenant = (new Tenant)->forceFill(['id' => 7]);
        $this->mock(AssistantAccess::class, fn ($guard) => $guard->shouldReceive('authorize')->with($actor, $tenant)->andReturnNull());
        $this->mock(AssistantProvider::class, function ($provider) {
            $provider->shouldReceive('enabled')->andReturnFalse();
            $provider->shouldNotReceive('respond');
        });
        $this->mock(AssistantReadTools::class, function ($reads) use ($actor, $tenant) {
            $reads->shouldReceive('execute')->once()->with($actor, $tenant, 'read_invoices', ['status' => 'open'])
                ->andReturn(['title' => 'Synthetic invoice overview', 'rows' => []]);
            $reads->shouldReceive('execute')->once()->with($actor, $tenant, 'read_reservations', ['kind' => 'all', 'date' => null])
                ->andReturn(['title' => 'Synthetic reservation overview', 'rows' => []]);
        });
        $service = app(BackofficeAssistantService::class);
        $state = $service->localQuery($actor, $tenant, null, 'invoices');
        $this->assertFalse($state['provider_enabled']);
        $this->assertSame(['user', 'assistant'], array_column($state['messages'], 'role'));
        $this->assertSame('Synthetic invoice overview', $state['messages'][1]['results'][0]['title']);
        $state = $service->localQuery($actor, $tenant, $state['conversation_id'], 'reservations');
        $this->assertCount(4, $state['messages']);
        $this->assertSame(0, $db->table('clubora.assistant_runs')->count());
        $ciphertext = $db->table('clubora.assistant_conversations')->value('transcript_encrypted');
        $this->assertStringNotContainsString('Synthetic', $ciphertext);
        $this->assertCount(4, json_decode(Crypt::decryptString($ciphertext), true, flags: JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }
}

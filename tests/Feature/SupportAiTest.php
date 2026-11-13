<?php

namespace Tests\Feature;

use App\ClubUnio\Jobs\TriageSupportTicket;
use App\ClubUnio\Services\SupportGuide;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

final class SupportAiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['clubunio-support.enabled' => true, 'clubunio-support.triage_enabled' => true,
            'clubunio-support.openai_key' => 'synthetic', 'clubunio-support.openai_project' => 'proj_synthetic',
            'clubunio-support.model' => 'synthetic']);
        Http::preventStrayRequests();
    }

    public function test_conversation_only_reuses_allowlisted_context_and_falls_back(): void
    {
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([], 503)]);
        $guide = app(SupportGuide::class);
        $this->assertSame('login', $guide->answer('How exactly?', 'en', 'login')['source']);
        $this->assertNull($guide->answer('How exactly?', 'en', 'secret')['source']);
        $this->assertNull($guide->answer('My email is private@example.invalid', 'en', 'login')['source']);
        Http::assertSentCount(1);
    }

    public function test_triage_checks_scope_and_only_transmits_curated_public_text(): void
    {
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('clubunio');
        DB::connection('clubunio')->unprepared(<<<'SQL'
ATTACH DATABASE ':memory:' AS clubora;
CREATE TABLE clubora.support_tickets (id text, tenant_id integer, audience text, status text, subject text);
CREATE TABLE clubora.support_triage (ticket_id text PRIMARY KEY, topic text, draft text, provider_generated boolean);
INSERT INTO clubora.support_tickets VALUES
('a',1,'club','open','Passkey private-marker-club'),
('b',1,'platform','open','Expense private-marker-platform'),
('c',1,'club','open','private-marker-unmatched');
SQL);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['output' => [
            ['content' => [['type' => 'output_text', 'text' => 'Curated response']]],
        ]])]);
        $guide = app(SupportGuide::class);
        foreach ([['a', 2, 'club'], ['b', 1, 'club'], ['a', 1, 'club'], ['a', 1, 'club'],
            ['b', 1, 'platform'], ['c', 1, 'club']] as [$id, $tenant, $audience]) {
            (new TriageSupportTicket($id, $tenant, $audience, 'en'))->handle($guide);
        }
        $this->assertSame(['login', 'expense', 'manual_review'], DB::connection('clubunio')
            ->table('clubora.support_triage')->orderBy('ticket_id')->pluck('topic')->all());
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString('private-marker', $request->body());
            $this->assertFalse($request['store']);
        }
    }
}

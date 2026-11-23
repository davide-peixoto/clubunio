<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Services\MemberAccessService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ClubSupportWorkspaceTest extends TestCase
{
    private const OWN = '119d4e80-9c70-484d-b1fb-1b6a41ad4e14';
    private const OTHER = '58c31320-51fe-4555-b2a0-054e580da003';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost', 'clubunio-support.enabled' => true,
            'clubunio-support.triage_enabled' => false, 'clubunio-support.openai_key' => null,
            'clubunio-security.enabled' => false,
            'database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('clubunio');
        DB::connection('clubunio')->unprepared(<<<'SQL'
ATTACH DATABASE ':memory:' AS clubora;
CREATE TABLE clubora.accounts (id integer PRIMARY KEY, display_name text, active boolean, api_only boolean, preferred_locale text);
CREATE TABLE clubora.tenants (id integer PRIMARY KEY, name text);
CREATE TABLE clubora.tenant_accounts (account_id integer, tenant_id integer, role text);
CREATE TABLE clubora.support_tickets (id text PRIMARY KEY, tenant_id integer, requester_id integer, subject text, status text, audience text, assignee_id integer, created_at text);
CREATE TABLE clubora.support_posts (id integer PRIMARY KEY, ticket_id text, body text, internal boolean, created_at text);
CREATE TABLE clubora.support_attachments (id text, post_id integer, filename text, mime text);
CREATE TABLE clubora.support_articles (id integer PRIMARY KEY, tenant_id integer, audience text, published boolean, locale text, title text, body text, video_url text);
CREATE TABLE clubora.support_public_requests (id text, tenant_id integer, payload text, created_at text);
INSERT INTO clubora.accounts VALUES (1,'Synthetic office',1,0,'de'),(2,'Synthetic member',1,0,'en');
INSERT INTO clubora.tenants VALUES (1,'Synthetic club'),(2,'Other club');
INSERT INTO clubora.tenant_accounts VALUES (1,1,'office'),(2,1,'member');
INSERT INTO clubora.support_articles VALUES (1,1,'club_staff',1,'de','Private staff article','Staff-only guidance',NULL);
SQL);
        DB::connection('clubunio')->table('clubora.support_tickets')->insert([
            ['id' => self::OWN, 'tenant_id' => 1, 'requester_id' => 2, 'subject' => 'Synthetic own ticket',
                'status' => 'open', 'audience' => 'club', 'created_at' => now()],
            ['id' => self::OTHER, 'tenant_id' => 2, 'requester_id' => 2, 'subject' => 'Foreign ticket',
                'status' => 'closed', 'audience' => 'club', 'created_at' => now()],
        ]);
        $this->mock(MemberAccessService::class)->shouldReceive('canEnter')
            ->andReturnUsing(fn ($actor, $tenant) => (int) $tenant->id === 1);
    }

    public function test_tickets_open_first_and_filters_keep_tenant_scope(): void
    {
        $this->actingAs(Account::findOrFail(1), 'clubunio');
        $this->get('/support/club/1')->assertOk()->assertSee('cs-tabs')->assertSee('cs-status-open')
            ->assertSee('Synthetic own ticket')->assertDontSee('Foreign ticket')
            ->assertDontSee('data-support-guide')->assertDontSee('Staff-only guidance');
        $this->get('/support/club/1?tab=tickets&status=closed')->assertOk()
            ->assertDontSee('Synthetic own ticket')->assertDontSee('Foreign ticket');
        $this->get('/support/club/1?ticket='.self::OTHER)->assertNotFound();
        $this->get('/support/club/1?q=missing')->assertOk()->assertDontSee('Synthetic own ticket');
    }

    public function test_member_can_use_chat_and_creation_but_not_editorial(): void
    {
        $this->actingAs(Account::findOrFail(2), 'portal');
        $this->get('/support/club/1?tab=assistant')->assertOk()->assertSee('data-support-chat')
            ->assertSee('role="log"', false)->assertDontSee('Synthetic own ticket');
        $this->get('/support/club/1?tab=create')->assertOk()->assertSee('name="subject"', false)
            ->assertDontSee('Staff-only guidance');
        $this->get('/support/club/1?tab=editorial')->assertForbidden();
        $this->get('/support/club/1?tab=knowledge')->assertOk()->assertDontSee('Private staff article');
        $this->get('/support/club/2')->assertForbidden();
    }

    public function test_login_help_is_not_embedded_and_request_inbox_remains_restricted(): void
    {
        $this->actingAs(Account::findOrFail(1), 'clubunio');
        $response = $this->get('/support/club/1')->assertOk();
        $this->assertMatchesRegularExpression('~href="[^"]*/help/sign-in" target="_blank" rel="noopener noreferrer"~', $response->getContent());
        $this->get('/help/sign-in')->assertOk()->assertSee('name="email"', false)
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; form-action 'self'");
        $this->get('/support/club/1/sign-in')->assertOk()->assertSee('Keine offenen Anmeldeanfragen.');
        $this->actingAs(Account::findOrFail(2), 'clubunio')->get('/support/club/1/sign-in')->assertForbidden();
    }

    public function test_public_help_has_only_knowledge_and_assistant(): void
    {
        $this->get('/help?embed=1')->assertOk()->assertSee('cs-tabs')
            ->assertDontSee('name="subject"', false)->assertDontSee('Synthetic own ticket');
        $this->get('/help?embed=1&tab=assistant')->assertOk()->assertSee('data-support-chat');
        $this->get('/help?tab=tickets')->assertNotFound();
    }

    public function test_mobile_json_contract_still_returns_ticket_collection(): void
    {
        $this->actingAs(Account::findOrFail(2), 'portal')->getJson('/support/club/1')
            ->assertOk()->assertJsonPath('data.rows.0.id', self::OWN)->assertJsonCount(1, 'data.rows');
    }
}

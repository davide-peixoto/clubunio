<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PlatformSupportWorkspaceTest extends TestCase
{
    private const PLATFORM = '119d4e80-9c70-484d-b1fb-1b6a41ad4e14';
    private const CLUB = '58c31320-51fe-4555-b2a0-054e580da003';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost', 'clubunio-support.enabled' => true,
            'clubunio-support.zammad.enabled' => true, 'clubunio-support.triage_enabled' => true,
            'clubunio-security.enabled' => false,
            'database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubunio");
        $db->unprepared(<<<'SQL'
CREATE TABLE clubunio.accounts (id integer PRIMARY KEY, display_name text, active boolean, api_only boolean, preferred_locale text);
CREATE TABLE clubunio.tenants (id integer PRIMARY KEY, name text);
CREATE TABLE clubunio.platform_admins (account_id integer, active boolean);
CREATE TABLE clubunio.support_tickets (id text PRIMARY KEY, tenant_id integer, subject text, status text, audience text, assignee_id integer, created_at text);
CREATE TABLE clubunio.support_posts (id integer PRIMARY KEY, ticket_id text, body text, internal boolean, created_at text);
CREATE TABLE clubunio.support_attachments (id text, post_id integer, filename text, mime text);
CREATE TABLE clubunio.support_triage (ticket_id text, topic text, draft text, provider_generated boolean);
CREATE TABLE clubunio.support_zammad_exports (ticket_id text);
CREATE TABLE clubunio.support_articles (id integer PRIMARY KEY, tenant_id integer, audience text, published boolean, locale text, title text, body text, video_url text);
CREATE TABLE clubunio.support_public_requests (id text, tenant_id integer, payload text, created_at text);
INSERT INTO clubunio.accounts VALUES (1,'Synthetic operator',1,0,'de'),(2,'Synthetic other',1,0,'en');
INSERT INTO clubunio.tenants VALUES (1,'Synthetic club');
INSERT INTO clubunio.platform_admins VALUES (1,1);
INSERT INTO clubunio.support_articles VALUES
 (1,NULL,'platform',1,'de','Synthetic knowledge','Platform article',NULL),
 (2,1,'club_staff',1,'de','Synthetic club secret','Internal club article',NULL);
SQL);
        $db->table('clubunio.support_tickets')->insert([
            ['id' => self::PLATFORM, 'tenant_id' => 1, 'subject' => 'Synthetic escalation', 'status' => 'waiting',
                'audience' => 'platform', 'assignee_id' => 1, 'created_at' => now()],
            ['id' => self::CLUB, 'tenant_id' => 1, 'subject' => 'Synthetic club-only ticket', 'status' => 'open',
                'audience' => 'club', 'assignee_id' => null, 'created_at' => now()],
        ]);
        $db->table('clubunio.support_posts')->insert(['ticket_id' => self::PLATFORM, 'body' => 'Synthetic technical details',
            'internal' => true, 'created_at' => now()]);
    }

    public function test_tickets_open_first_and_filters_and_detail_remain_platform_scoped(): void
    {
        $this->actingAs(Account::findOrFail(1), 'clubunio');
        $this->get('/platform/support')->assertOk()->assertSee('Plattform-Inbox')
            ->assertSee('Synthetic escalation')->assertSee('Synthetic club')
            ->assertDontSee('Synthetic club-only ticket')->assertDontSee('Passkey auf einem neuen Gerät')
            ->assertDontSee('data-support-guide', false);
        $this->get('/platform/support?status=open')->assertOk()->assertSee('Keine Tickets für diese Filter.')
            ->assertDontSee('Synthetic escalation');
        $this->get('/platform/support?status=waiting&assignee=1&q=Synthetic+club&ticket='.self::PLATFORM)
            ->assertOk()->assertSee('aria-current="true"', false)->assertSee('Synthetic escalation')
            ->assertSee('Synthetic technical details')->assertSee('Interne Notiz')->assertSee('Zammad-Übergabe');
        $this->get('/platform/support?ticket='.self::CLUB)->assertNotFound();
    }

    public function test_knowledge_and_assistant_are_separate_and_access_is_unchanged(): void
    {
        $this->get('/platform/support')->assertRedirect(route('clubunio.platform.login'));
        $this->actingAs(Account::findOrFail(2), 'clubunio')->get('/platform/support')->assertForbidden();
        $this->actingAs(Account::findOrFail(1), 'clubunio');
        $this->get('/platform/support?tab=knowledge')->assertOk()->assertSee('Synthetic knowledge')
            ->assertDontSee('Synthetic club secret')->assertDontSee('Synthetic escalation')->assertDontSee('data-support-guide', false);
        $this->get('/platform/support?tab=assistant')->assertOk()->assertSee('data-support-guide', false)
            ->assertDontSee('Synthetic escalation')->assertDontSee('Synthetic knowledge');
        $this->get('/platform/support/sign-in')->assertOk()->assertSee('Keine offenen Anmeldeanfragen.');
    }
}

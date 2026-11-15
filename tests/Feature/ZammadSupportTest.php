<?php

namespace Tests\Feature;

use App\ClubUnio\Services\ZammadSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class ZammadSupportTest extends TestCase
{
    private const CLUB = '58c31320-51fe-4555-b2a0-054e580da003';
    private const PLATFORM = '119d4e80-9c70-484d-b1fb-1b6a41ad4e14';
    private const ORIGIN = 'https://desk.example.test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'clubunio-support.zammad' => ['enabled' => true, 'url' => self::ORIGIN, 'token' => 'synthetic_test_token',
                'group_id' => 4, 'customer_id' => 7]]);
        DB::purge('clubunio');
        $db = DB::connection('clubunio');
        $db->statement("ATTACH DATABASE ':memory:' AS clubora");
        $db->unprepared(<<<'SQL'
CREATE TABLE clubora.support_tickets (id text PRIMARY KEY, audience text, source_id text, subject text, status text);
CREATE TABLE clubora.support_posts (id integer PRIMARY KEY, ticket_id text, body text, internal boolean);
CREATE TABLE clubora.support_zammad_exports (ticket_id text PRIMARY KEY, origin text, group_id integer, customer_id integer,
 status text, remote_id integer, created_at text DEFAULT CURRENT_TIMESTAMP, UNIQUE (origin, remote_id));
SQL);
        $db->table('clubora.support_tickets')->insert([
            ['id' => self::CLUB, 'audience' => 'club', 'source_id' => null, 'subject' => 'Club original', 'status' => 'open'],
            ['id' => self::PLATFORM, 'audience' => 'platform', 'source_id' => self::CLUB, 'subject' => 'Approved subject', 'status' => 'open'],
        ]);
        $db->table('clubora.support_posts')->insert([
            ['ticket_id' => self::CLUB, 'body' => 'Original club content', 'internal' => false],
            ['ticket_id' => self::PLATFORM, 'body' => 'Internal note', 'internal' => true],
            ['ticket_id' => self::PLATFORM, 'body' => 'Approved <summary>', 'internal' => false],
            ['ticket_id' => self::PLATFORM, 'body' => 'Later platform reply', 'internal' => false],
        ]);
        Http::preventStrayRequests();
    }

    public function test_exports_only_reviewed_summary_and_links_once(): void
    {
        Http::fake([self::ORIGIN.'/api/v1/tickets' => Http::response($this->receipt(), 201)]);
        $service = app(ZammadSupport::class);
        $this->denies(404, fn () => $service->export(self::CLUB));
        $this->assertTrue($service->export(self::PLATFORM));
        $this->assertSame(self::ORIGIN.'/#ticket/zoom/23', $service->detail(self::PLATFORM)['url']);
        $this->denies(409, fn () => $service->export(self::PLATFORM));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Token token=synthetic_test_token')
            && $request->hasHeader('X-Zammad-Suppress-Notifications', 'true')
            && $request->data() === [
                'title' => $this->receipt()['title'], 'group_id' => 4, 'customer_id' => 7,
                'article' => ['subject' => 'Approved subject', 'body' => 'Approved &lt;summary&gt;',
                    'content_type' => 'text/html', 'type' => 'note', 'internal' => true],
            ]);
    }

    public function test_uncertain_delivery_does_not_retry_and_requires_matching_remote_reference(): void
    {
        Http::fake([
            self::ORIGIN.'/api/v1/tickets/88' => Http::response(['id' => 88] + $this->receipt(6), 200),
            self::ORIGIN.'/api/v1/tickets/23' => Http::response($this->receipt(), 200),
            self::ORIGIN.'/api/v1/tickets' => Http::response([], 502),
        ]);
        $service = app(ZammadSupport::class);
        $this->assertFalse($service->export(self::PLATFORM));
        $this->assertSame('attention', $service->detail(self::PLATFORM)['status']);
        $this->denies(409, fn () => $service->export(self::PLATFORM));
        $this->denies(409, fn () => $service->reconcile(self::PLATFORM, 23));
        DB::connection('clubunio')->table('clubora.support_zammad_exports')->update(['created_at' => now()->subMinutes(2)]);
        $this->assertFalse($service->reconcile(self::PLATFORM, 88));
        $this->assertTrue($service->reconcile(self::PLATFORM, 23));
        Http::assertSentCount(3);
    }

    public function test_incomplete_or_insecure_configuration_cannot_send(): void
    {
        Http::fake();
        $service = app(ZammadSupport::class);
        config(['clubunio-support.zammad.url' => 'http://desk.example.test']);
        $this->denies(503, fn () => $service->export(self::PLATFORM));
        Http::assertNothingSent();
    }

    private function receipt(int $group = 4): array
    {
        return ['id' => 23, 'title' => '[ClubUnio DEV '.self::PLATFORM.'] Approved subject', 'group_id' => $group, 'customer_id' => 7];
    }

    private function denies(int $status, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected refusal');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }
}

<?php

namespace Tests\Feature;

use App\ClubUnio\Services\{SupportAccess, SupportGuide};
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

final class SupportCenterTest extends TestCase
{
    public function test_articles_and_tickets_are_scoped_before_reading(): void
    {
        config(['database.connections.clubunio' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('clubunio');
        DB::connection('clubunio')->unprepared(<<<'SQL'
ATTACH DATABASE ':memory:' AS clubora;
CREATE TABLE clubora.support_articles (title text, tenant_id integer, audience text, published integer, locale text);
INSERT INTO clubora.support_articles VALUES
('public',null,'public',1,'en'),('club',1,'club',1,'en'),('staff',1,'club_staff',1,'en'),
('foreign',2,'club',1,'en'),('platform',null,'platform',1,'en'),('draft',1,'club',0,'en');
CREATE TABLE clubora.support_tickets (id integer, tenant_id integer, requester_id integer, audience text);
INSERT INTO clubora.support_tickets VALUES (1,1,1,'club'),(2,1,2,'club'),(3,2,1,'club'),(4,1,1,'platform');
SQL);
        $access = app(SupportAccess::class);
        $this->assertSame(['public'], $access->articles(null, false, false, 'en')->pluck('title')->all());
        $this->assertSame(['club','public'], $access->articles(1, false, false, 'en')->orderBy('title')->pluck('title')->all());
        $this->assertSame(['club','public','staff'], $access->articles(1, true, false, 'en')->orderBy('title')->pluck('title')->all());
        $this->assertSame(['platform','public'], $access->articles(null, false, true, 'en')->orderBy('title')->pluck('title')->all());
        $this->assertSame([1], $access->tickets(1, 1, false)->pluck('id')->all());
        $this->assertSame([1,2], $access->tickets(1, 1, true)->orderBy('id')->pluck('id')->all());
    }

    public function test_provider_never_receives_the_raw_question(): void
    {
        config(['clubunio-support.openai_key' => 'synthetic', 'clubunio-support.openai_project' => 'proj_synthetic',
            'clubunio-support.model' => 'synthetic']);
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['output' => [
            ['content' => [['type' => 'output_text', 'text' => 'Public guide summary']]],
        ]])]);
        $guide = app(SupportGuide::class);
        $this->assertTrue($guide->answer('Passkey my-private-marker@example.invalid', 'en')['ai']);
        $this->assertNull($guide->answer('Unmatched question', 'en')['source']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => !str_contains($request->body(), 'my-private-marker')
            && $request['store'] === false && !isset($request['tools']) && $request->hasHeader('OpenAI-Project', 'proj_synthetic'));
    }
}

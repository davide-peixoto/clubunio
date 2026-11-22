<?php

namespace Tests\Feature;

use App\ClubUnio\Mobile\EventCheckinThrottle;
use App\ClubUnio\Models\Account;
use Illuminate\Http\Request;
use Tests\TestCase;

class EventCheckinThrottleTest extends TestCase
{
    public function test_members_sharing_one_venue_ip_do_not_use_each_others_limit(): void
    {
        config(['cache.default' => 'array']);
        $request = Request::create('https://stage.clubunio.com/api/mobile/v1/clubs/1/event-check-ins/'.str_repeat('a', 64), 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.1']);
        $request->attributes->set('mobile.actor', (new Account)->forceFill(['id' => 101]));
        $throttle = new EventCheckinThrottle;
        for ($i = 0; $i < 30; $i++) $this->assertSame(204, $throttle->handle($request, fn () => response('', 204))->getStatusCode());
        $request->attributes->set('mobile.actor', (new Account)->forceFill(['id' => 102]));
        $this->assertSame(204, $throttle->handle($request, fn () => response('', 204))->getStatusCode());
        $request->attributes->set('mobile.actor', (new Account)->forceFill(['id' => 101]));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $throttle->handle($request, fn () => response('', 204));
    }
}

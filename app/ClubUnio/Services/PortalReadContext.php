<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Mobile\MobileReadContext;
use App\ClubUnio\Models\Account;
use Closure;

/** Reuse access facts within one authenticated portal read, never on writes or across requests. */
final class PortalReadContext
{
    public function accessFact(Account $actor, string $key, Closure $read): mixed
    {
        return $this->remember($actor, $key, fn () => app(MobileReadContext::class)->remember($actor, $key, $read));
    }

    private function remember(Account $actor, string $key, Closure $read): mixed
    {
        $request = request();
        if (! $request->isMethod('GET') || $request->attributes->get('clubunioWorkspace')
            || $request->attributes->has('clubunio_impersonation')
            || ! $request->routeIs('clubunio.portal.*', 'clubunio.planning.*', 'clubunio.training.*', 'clubunio.bus.*')
            || ! $actor->active || $actor->api_only || (string) auth('portal')->id() !== (string) $actor->id) {
            return $read();
        }
        $key = self::class.':'.$actor->id.':'.$key;
        if ($request->attributes->has($key)) {
            return $request->attributes->get($key);
        }
        $value = $read();
        $request->attributes->set($key, $value);

        return $value;
    }

    public function freshActor(Account $actor): ?Account
    {
        return $this->remember($actor, 'fresh-actor', fn () => $actor->fresh());
    }

    public function freshAccessActor(Account $actor): ?Account
    {
        return $this->accessFact($actor, 'fresh-actor', fn () => $actor->fresh());
    }
}

<?php
namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Account;
use Closure;
use Illuminate\Http\Request;

/** Fresh facts for exactly one authenticated mobile GET, never a write or a TTL cache. */
final class MobileReadContext
{
    public function during(Request $request, Account $actor, Closure $next): mixed
    {
        $session=$request->attributes->get('mobile.session');
        if (!$request->isMethod('GET') || !$session || (int)$session->account_id !== (int)$actor->id
            || (int)$request->attributes->get('mobile.actor')?->id !== (int)$actor->id) return $next();
        $previous=$request->attributes->get(self::class);
        $request->attributes->set(self::class,['request'=>spl_object_id($request),'account'=>(int)$actor->id,
            'session'=>$session->id,'scope'=>new \stdClass,'values'=>[]]);
        try { return $next(); }
        finally {
            if ($previous===null) $request->attributes->remove(self::class);
            else $request->attributes->set(self::class,$previous);
        }
    }

    public function remember(Account $actor, string $key, Closure $read): mixed
    {
        if (!app()->bound('request')) return $read();
        $request=request(); $frame=$request->attributes->get(self::class);
        $session=$request->attributes->get('mobile.session');
        if (!$request->isMethod('GET') || !$frame || !$session || !$actor->active || $actor->api_only
            || $frame['request']!==spl_object_id($request) || $frame['account']!==(int)$actor->id
            || (int)$request->attributes->get('mobile.actor')?->id!==(int)$actor->id
            || (int)$session->account_id!==(int)$actor->id || $frame['session']!==$session->id) return $read();
        if (array_key_exists($key,$frame['values'])) return $frame['values'][$key];
        $value=$read(); // Do not remember failures.
        $current=$request->attributes->get(self::class);
        if ($current && $current['scope']===$frame['scope']) {
            $current['values'][$key]=$value;
            $request->attributes->set(self::class,$current);
        }
        return $value;
    }

    public function freshActor(Account $actor): ?Account
    {
        return $this->remember($actor,'fresh-actor',fn ()=>$actor->fresh());
    }
}

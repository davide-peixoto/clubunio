<?php
namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Fresh GET facts, or explicit locked notification authorization; never a general POST/TTL cache. */
final class MobileReadContext
{
    /** Only notification authorization projection; exit before any read-marker writes. */
    public function duringLockedProjection(Request $request,Account $actor,Tenant $tenant,Closure $next): mixed
    {
        $db=DB::connection('clubunio'); $session=$request->attributes->get('mobile.session');
        if(!$request->isMethod('POST') || $db->transactionLevel()<1 || !$session
            || (int)$session->account_id!==(int)$actor->id
            || (int)$request->attributes->get('mobile.actor')?->id!==(int)$actor->id
            || (int)$request->attributes->get('mobile.tenant')?->id!==(int)$tenant->id) return $next();
        Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
        $freshSession=$db->table('clubunio.mobile_sessions')->where('id',$session->id)->where('account_id',$actor->id)
            ->whereNull('revoked_at')->where('expires_at','>',now()->toIso8601String())->sharedLock()->first();
        abort_unless($freshSession,401);
        $fresh=Account::whereKey($actor->id)->where('active',true)->where('api_only',false)->sharedLock()->first();
        abort_unless($fresh,401);
        $previous=$request->attributes->get(self::class);
        $clearSeason=function()use($request,$tenant){
            foreach(array_keys($request->attributes->all()) as $key) if(str_starts_with($key,'mobile.current_season.'.$tenant->id.'.')) $request->attributes->remove($key);
        };
        $clearSeason();
        $request->attributes->set(self::class,['request'=>spl_object_id($request),'account'=>(int)$actor->id,
            'session'=>$session->id,'scope'=>new \stdClass,'locked_tenant'=>(int)$tenant->id,
            'transaction'=>$db->transactionLevel(),'values'=>['fresh-actor'=>$fresh]]);
        try { return $next(); }
        finally {
            $clearSeason();
            if($previous===null) $request->attributes->remove(self::class);
            else $request->attributes->set(self::class,$previous);
        }
    }

    public function lockedFor(Account $actor,Tenant $tenant): bool
    {
        if(!app()->bound('request')) return false;
        $request=request(); $frame=$request->attributes->get(self::class); $session=$request->attributes->get('mobile.session');
        return $request->isMethod('POST') && $frame && $session
            && ($frame['locked_tenant']??null)===(int)$tenant->id
            && $frame['request']===spl_object_id($request) && $frame['account']===(int)$actor->id
            && (int)$request->attributes->get('mobile.actor')?->id===(int)$actor->id
            && (int)$request->attributes->get('mobile.tenant')?->id===(int)$tenant->id
            && (int)$session->account_id===(int)$actor->id && $frame['session']===$session->id
            && DB::connection('clubunio')->transactionLevel()>=($frame['transaction']??PHP_INT_MAX);
    }
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
        $locked=$frame && isset($frame['locked_tenant']) && $this->lockedFor($actor,(new Tenant)->forceFill(['id'=>$frame['locked_tenant']]));
        if ((!$request->isMethod('GET') && !$locked) || !$frame || !$session || !$actor->active || $actor->api_only
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

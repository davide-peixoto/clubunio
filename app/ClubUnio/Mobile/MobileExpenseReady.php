<?php
namespace App\ClubUnio\Mobile;
use Closure;
use Illuminate\Http\Request;
final class MobileExpenseReady {
    public function handle(Request $request, Closure $next) {
        abort_unless(config('clubunio-mobile.native_expenses_enabled', false), 503, 'Spesen werden noch migriert.');
        return $next($request);
    }
}

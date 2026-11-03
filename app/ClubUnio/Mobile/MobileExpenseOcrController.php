<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Http\Request;

final class MobileExpenseOcrController implements \Illuminate\Routing\Controllers\HasMiddleware
{
    public static function middleware(): array { return [MobileExpenseReady::class]; }
    public function suggest(Request $request) {
        $result = app(MobileExpenseOcr::class)->execute($request->attributes->get('mobile.actor'), $request->attributes->get('mobile.tenant'),
            (string) $request->route('receipt'), $request->all());
        abort_if($result['state'] === 'stale', 409, 'Beleg oder persoenlicher Zugriff wurde waehrend der Analyse geaendert.');
        abort_if($result['state'] === 'failed', 502, 'Beleganalyse hat kein verlaessliches Ergebnis geliefert.');
        return response()->json(['data' => $result], $result['state'] === 'pending' ? 202 : 200);
    }
}

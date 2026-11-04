<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\LanguagePreferences;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LanguageController
{
    public function __invoke(Request $request)
    {
        $languages = app(LanguagePreferences::class);
        $data = $request->validate(['locale' => ['required', 'string', Rule::in(array_keys($languages->enabled()))]]);
        $actor = $languages->actor($request);
        if ($actor) {
            abort_unless($actor->active, 403);
            // Only the current account's display preference; never roles or other accounts.
            $actor->forceFill(['preferred_locale' => $data['locale']])->save();
        }
        $request->session()->put('clubunio.locale', $data['locale']);
        return redirect($languages->returnPath($request->header('referer'), $request->getHost()));
    }
}

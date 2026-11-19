<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Services\CampaignOperations;
use Illuminate\Http\Request;

final class CampaignFeedbackController
{
    public function __invoke(Request $request, CampaignOperations $operations)
    {
        $secret = (string) config('clubunio-campaign-mail.feedback_secret');
        $timestamp = (string) $request->header('X-Campaign-Timestamp', '');
        abort_unless($operations->enabled() && strlen($secret) >= 32 && ctype_digit($timestamp)
            && abs(time() - (int) $timestamp) <= 300 && strlen($request->getContent()) <= 4096
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret),
                (string) $request->header('X-Campaign-Signature', '')), 403);
        $operations->feedback($request->json()->all());
        return response()->json(['accepted' => true])->header('Cache-Control', 'no-store');
    }
}

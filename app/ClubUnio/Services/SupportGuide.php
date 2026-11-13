<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\Http;

final class SupportGuide
{
    public function answer(string $question, string $locale): array
    {
        $topic = match (true) {
            (bool) preg_match('/passkey|authenticator|login|anmeld(en|ung)|sign.in/i', $question) => 'login',
            (bool) preg_match('/spesen|expense|receipt/i', $question) => 'expense',
            default => null,
        };
        if ($topic === null) return ['text' => trans('support.handoff', locale: $locale), 'source' => null, 'ai' => false];
        $article = trans('support.faq.'.$topic, locale: $locale);
        $fallback = ['text' => $article['body'], 'source' => $topic, 'ai' => false];
        $config = config('clubunio-support');
        if (!$config['openai_key'] || !$config['openai_project'] || !$config['model']) return $fallback;
        try {
            // Only curated public text and an allowlisted topic leave ClubUnio.
            $result = Http::withToken($config['openai_key'])->withHeaders(['OpenAI-Project' => $config['openai_project']])
                ->withoutRedirecting()->connectTimeout(3)->timeout(10)->post('https://api.openai.com/v1/responses', [
                    'model' => $config['model'], 'store' => false, 'max_output_tokens' => 300,
                    'instructions' => 'Summarize the provided public support article in its own language. Do not invent steps or request personal data. You cannot take any action.',
                    'input' => $article['title']."\n".$article['body'],
                ]);
            if ($result->successful()) {
                foreach ((array) $result->json('output') as $item) {
                    foreach ($item['content'] ?? [] as $part) {
                        if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                            return ['text' => $part['text'], 'source' => $topic, 'ai' => true];
                        }
                    }
                }
            }
        } catch (\Illuminate\Http\Client\ConnectionException) {
            // The public article remains available when the model cannot be reached.
        }
        return $fallback;
    }
}

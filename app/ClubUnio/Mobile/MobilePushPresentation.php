<?php

namespace App\ClubUnio\Mobile;

use Illuminate\Support\Facades\Crypt;

/** Ephemeral presentation only: never stored in the routing envelope or outbox. */
final class MobilePushPresentation
{
    public function notification(string $locale, ?array $source = null): array
    {
        $fallback = ['title' => trans('mobile_push.title', [], $locale), 'body' => trans('mobile_push.open', [], $locale)];
        if ($source === null) return $fallback;
        try {
            $text = $this->short(Crypt::decryptString($source['message']->body_encrypted), 180);
        } catch (\Throwable) {
            return $fallback;
        }
        $title = $this->short($source['actor']->display_name, 80);
        $body = $text !== '' ? $text : trans('mobile_push.shared', [], $locale);
        return ['title' => $title !== '' ? $title : $fallback['title'], 'body' => $body];
    }

    private function short(string $text, int $limit): string
    {
        $text = preg_replace('/[\p{Cc}\p{Z}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', ' ', $text) ?? '';
        $text = trim($text);
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    }
}

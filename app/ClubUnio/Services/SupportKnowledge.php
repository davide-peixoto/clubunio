<?php

namespace App\ClubUnio\Services;

/** Public, versioned instructions. This catalog never grants access to a module. */
final class SupportKnowledge
{
    public function articles(string $locale, string $surface, ?string $search = null): array
    {
        $locale = in_array($locale, array_keys(config('clubunio-languages.enabled', [])), true) ? $locale : 'de';
        $surface = $surface === 'app' ? 'app' : 'web';
        $guides = trans('support-'.$surface.'-guides', locale: $locale);
        $guides = is_array($guides) ? $guides : [];
        if (is_file(lang_path($locale.'/support-'.$surface.'-extra.php'))) {
            $extra = trans('support-'.$surface.'-extra', locale: $locale);
            if (is_array($extra)) $guides += $extra;
        }
        $fallback = app(SupportGuide::class)->faq($locale);
        $articles = [];
        foreach ($guides as $key => $guide) {
            $article = [
                'title' => $guide['title'],
                'body' => isset($fallback[$key]) ? $fallback[$key]['body']."\n\n".$guide['intro'] : $guide['intro'],
                'surface' => $surface,
                'steps' => $guide['steps'],
                'notes' => $guide['notes'] ?? [],
            ];
            $prefix = 'support-media/'.$locale.'/'.$surface.'/'.$key;
            // Current release contains illustrated instructions only; video publishing is paused.
            foreach (['image_url' => 'png'] as $field => $extension) {
                if (is_file(public_path($prefix.'.'.$extension))) {
                    $article[$field] = rtrim(config('app.url'), '/').'/'.$prefix.'.'.$extension;
                }
            }
            if (is_file(public_path($prefix.'-screen.png'))) {
                $article['screenshot_url'] = rtrim(config('app.url'), '/').'/'.$prefix.'-screen.png';
                $article['screenshot_caption'] = trans('support-wiki.example', locale: $locale);
            }
            $articles[$key] = $article;
        }
        // Remain compatible during rolling catalog deployment.
        $articles += $fallback;
        $search = mb_strtolower(trim($search ?? ''));
        return $search === '' ? $articles : array_filter($articles, function ($article) use ($search) {
            $text = $article['title'].' '.$article['body'];
            foreach ($article['steps'] ?? [] as $step) $text .= ' '.$step['title'].' '.$step['body'];
            $text .= ' '.implode(' ', $article['notes'] ?? []);
            return mb_strpos(mb_strtolower($text), $search) !== false;
        });
    }
}

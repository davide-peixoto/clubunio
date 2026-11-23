<?php

namespace Tests\Feature;

use App\ClubUnio\Services\{SupportGuide, SupportKnowledge};
use Tests\TestCase;

class SupportKnowledgeTest extends TestCase
{
    public function test_every_surface_has_complete_matching_localized_actionable_guides(): void
    {
        $knowledge = app(SupportKnowledge::class);
        foreach (['app', 'web'] as $surface) {
            $de = $knowledge->articles('de', $surface);
            $en = $knowledge->articles('en', $surface);
            $this->assertSame(array_keys($de), array_keys($en));
            $this->assertEmpty(array_diff(SupportGuide::TOPICS, array_keys($en)));
            foreach (['de' => $de, 'en' => $en] as $locale => $articles) {
                foreach ($articles as $topic => $article) {
                    $this->assertSame($surface, $article['surface'], "$locale/$topic");
                    $this->assertNotEmpty($article['title']);
                    $this->assertNotEmpty($article['body']);
                    $this->assertGreaterThanOrEqual(3, count($article['steps']));
                    $this->assertLessThanOrEqual(6, count($article['steps']));
                    foreach ($article['steps'] as $step) {
                        $this->assertNotEmpty($step['title']);
                        $this->assertNotEmpty($step['body']);
                        $this->assertStringNotContainsString('<script', $step['body']);
                    }
                    $this->assertNotEmpty($article['notes']);
                    foreach (['image_url', 'video_url', 'captions_url'] as $field) {
                        if (isset($article[$field])) {
                            $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/support-media/', $article[$field]);
                        }
                    }
                }
            }
        }
    }

    public function test_search_includes_step_content_and_surface_cannot_inject_catalog_paths(): void
    {
        $knowledge = app(SupportKnowledge::class);
        $app = $knowledge->articles('en', 'app');
        $step = $app['expense']['steps'][1]['body'];
        $this->assertArrayHasKey('expense', $knowledge->articles('en', 'app', $step));
        $this->assertSame([], $knowledge->articles('de', 'app', 'synthetic-impossible-search-90217'));
        $this->assertSame($knowledge->articles('en', 'web'), $knowledge->articles('en', '../../private'));
        $this->assertSame(array_keys($knowledge->articles('de', 'app')), array_keys($knowledge->articles('zz', 'app')));
        $this->assertNotSame($app['expense']['steps'], $knowledge->articles('en', 'web')['expense']['steps']);
    }

    public function test_video_publication_is_paused_even_when_generated_files_exist(): void
    {
        foreach (['de', 'en'] as $locale) foreach (['app', 'web'] as $surface) {
            foreach (app(SupportKnowledge::class)->articles($locale, $surface) as $article) {
                foreach (['video_url', 'recording_url', 'captions_url', 'player_url'] as $field) {
                    $this->assertArrayNotHasKey($field, $article);
                }
            }
        }
    }

    public function test_public_guide_markup_escapes_content_and_does_not_autoplay_video(): void
    {
        $item = ['title'=>'Example', 'body'=>'<script>unsafe</script>', 'steps'=>[['title'=>'One','body'=>'<img onerror=x>']], 'notes'=>['Note'], 'video_url'=>'https://example.invalid/support-media/test.mp4', 'recording_url'=>'https://example.invalid/support-media/sample.mp4'];
        $html = view('clubunio.support.guide', compact('item'))->render();
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<video', $html);
        $this->assertStringNotContainsString('.mp4', $html);
    }
}

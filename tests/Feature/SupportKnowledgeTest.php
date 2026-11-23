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

    public function test_public_guide_markup_escapes_content_and_does_not_autoplay_video(): void
    {
        $item = ['title'=>'Example', 'body'=>'<script>unsafe</script>', 'steps'=>[['title'=>'One','body'=>'<img onerror=x>']], 'notes'=>['Note']];
        $html = view('clubunio.support.guide', compact('item'))->render();
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('autoplay', $html);
    }
}

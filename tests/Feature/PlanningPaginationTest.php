<?php

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class PlanningPaginationTest extends TestCase
{
    public function test_pagination_preserves_filters_and_marks_boundaries(): void
    {
        foreach ([1, 2, 3] as $page) {
            $paginator = new LengthAwarePaginator(range(1, 25), 63, 25, $page, [
                'path' => '/planning/sv-sissach/sfv',
                'query' => ['from' => '2026-10-12', 'to' => '2026-11-12'],
            ]);
            $html = $paginator->links('clubunio.planning.pagination')->toHtml();
            $this->assertStringContainsString('aria-label="Seitennavigation"', $html);
            $this->assertStringContainsString('aria-current="page" aria-label="Seite '.$page.'"', $html);
            $this->assertStringContainsString('from=2026-10-12', $html);
            $this->assertStringContainsString('to=2026-11-12', $html);
            $this->assertSame($page === 2 ? 0 : 1, substr_count($html, 'aria-disabled="true"'));
            $this->assertStringNotContainsString('Pagination Navigation', $html);
        }
        $single = new LengthAwarePaginator([], 0, 25, 1);
        $this->assertStringNotContainsString('<nav', $single->links('clubunio.planning.pagination')->toHtml());
    }
}

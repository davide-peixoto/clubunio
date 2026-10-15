<?php

namespace Tests\Concerns;

trait WorkspaceForm
{
    private function workspaceForm(): array
    {
        $page = $this->get('/bus/options')->assertOk();
        preg_match('/name="_workspace" value="([^"]+)"/', $page->getContent(), $match);
        $this->assertNotEmpty($match);

        return ['_workspace' => html_entity_decode($match[1])];
    }
}

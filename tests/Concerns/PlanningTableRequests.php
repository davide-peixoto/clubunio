<?php

namespace Tests\Concerns;

use Filament\Facades\Filament;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

trait PlanningTableRequests
{
    private function tableSnapshot(string $html): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        foreach ($matches[1] as $encoded) {
            $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5);
            $data = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
            if (isset($data['data']['workspaceContext'])) {
                return $snapshot;
            }
        }
        $this->fail('Missing planning table snapshot');
    }

    private function updatePlanningTable(string $snapshot, array $updates = [], array $calls = [], string $host = '')
    {
        // Model a new HTTP worker, not the Filament state left over from the GET.
        Filament::setTenant(null);
        Filament::setCurrentPanel(null);
        app('livewire')->flushState();

        return $this->postJson($host.app(HandleRequests::class)->getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => $calls]],
        ], ['X-Livewire' => '']);
    }
}

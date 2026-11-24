<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Services\{ActiveTenant, WorkspaceAccess};
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

abstract class WorkspacePage extends Page
{
    #[Locked]
    public array $workspaceSnapshot = [];

    public static function canAccess(): bool
    {
        return app(WorkspaceAccess::class)->allowed(Filament::auth()->user(), Filament::getTenant());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        app(WorkspaceAccess::class)->context();
        $this->workspaceSnapshot = request()->session()->get(app(ActiveTenant::class)->key(request()));
    }

    protected function workspaceContext(): array
    {
        [$actor, $tenant] = app(WorkspaceAccess::class)->context();
        app(ActiveTenant::class)->assertSnapshot(request(), $this->workspaceSnapshot, $tenant);
        abort_unless(static::canAccess(), 403);
        return [$actor, $tenant];
    }

    public static function getNavigationGroup(): ?string { return trans('workspace.group'); }
}

<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Services\{WorkspaceLinks, WorkspacePreferences, ApprovalQueue, PlanningCalendarService};
use App\ClubUnio\Models\SavedView;

class MyClub extends WorkspacePage
{
    protected string $view = 'clubunio.workspace.my-club';
    protected static ?string $slug = 'my-club';
    public int $viewsPage = 1;
    public static function getNavigationLabel(): string { return trans('workspace.home'); }
    public function getTitle(): string { return static::getNavigationLabel(); }
    public static function getNavigationIcon(): string { return 'heroicon-o-home'; }
    public static function getNavigationSort(): ?int { return -20; }

    public function toggleFavorite(string $key): void
    {
        [$actor, $tenant] = $this->workspaceContext();
        app(WorkspacePreferences::class)->toggle($actor, $tenant, $key);
    }

    public function clearRecent(): void
    {
        [$actor, $tenant] = $this->workspaceContext();
        app(WorkspacePreferences::class)->clear($actor, $tenant);
    }

    protected function getViewData(): array
    {
        [$actor, $tenant] = $this->workspaceContext();
        $links = app(WorkspaceLinks::class)->available();
        $preferences = app(WorkspacePreferences::class);
        $state = $preferences->read($actor, $tenant);
        $views = [];
        $viewsQuery = SavedView::where('tenant_id', $tenant->id)->where('account_id', $actor->id)->whereIn('list_key', array_intersect(array_keys($links),array_keys(WorkspaceLinks::LISTS)));
        $viewsCount = (clone $viewsQuery)->count();
        $this->viewsPage = max(1,min($this->viewsPage,max(1,(int)ceil($viewsCount/20))));
        foreach ($viewsQuery->orderBy('list_key')->orderBy('name')->offset(($this->viewsPage-1)*20)->limit(20)->get() as $saved) {
            if (isset($links[$saved->list_key])) $views[] = ['label' => $saved->name, 'list' => $links[$saved->list_key]['label'], 'url' => $links[$saved->list_key]['url'].'?savedView='.$saved->id];
        }
        $upcoming = collect();
        if (TeamPlanning::canAccess()) $upcoming = app(PlanningCalendarService::class)->visible($actor, $tenant)
            ->where('status', 'AKTIV')->where('starts_at', '>=', now()->toIso8601String())->where('starts_at', '<', now()->addDays(7)->toIso8601String())->orderBy('starts_at')->limit(8)->get(['id','title','starts_at']);
        return ['links' => $links, 'favorites' => $state['favorites'], 'recent' => $preferences->recent($actor, $tenant), 'views' => $views, 'viewsCount'=>$viewsCount,
            'quick' => app(WorkspaceLinks::class)->quickActions(), 'queues' => app(ApprovalQueue::class)->counts($actor, $tenant), 'upcoming' => $upcoming, 'tenant' => $tenant];
    }
}

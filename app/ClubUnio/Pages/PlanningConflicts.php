<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Services\WorkspaceConflicts;
use Livewire\Attributes\Url;

class PlanningConflicts extends WorkspacePage
{
    protected string $view = 'clubunio.workspace.conflicts';
    protected static ?string $slug = 'planning-conflicts';
    #[Url] public string $date = '';
    public int $conflictPage = 1;
    public function updatedDate(): void { $this->conflictPage = 1; }
    public static function getNavigationLabel(): string { return trans('workspace.conflicts'); }
    public function getTitle(): string { return static::getNavigationLabel(); }
    public static function getNavigationIcon(): string { return 'heroicon-o-exclamation-triangle'; }
    public static function canAccess(): bool { return parent::canAccess() && (TeamPlanning::canAccess() || BusReservations::canAccess() || PitchPlanning::canAccess()); }
    protected function getViewData(): array
    {
        [$actor,$tenant]=$this->workspaceContext();
        if ($this->date==='') $this->date=now('Europe/Zurich')->toDateString();
        $report=app(WorkspaceConflicts::class)->report($actor,$tenant,$this->date);
        $report['count']=count($report['rows']);
        $this->conflictPage=max(1,min($this->conflictPage,max(1,(int)ceil($report['count']/24))));
        $report['rows']=array_slice($report['rows'],($this->conflictPage-1)*24,24);
        return compact('report');
    }
}

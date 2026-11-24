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
    #[\Livewire\Attributes\Locked] public array $solverSource=[];
    #[\Livewire\Attributes\Locked] public array $solverItems=[];
    public int $proposalIndex=0;
    public function solve(string $key,string $date):void
    {
        [$actor,$tenant]=$this->workspaceContext();$data=app(\App\ClubUnio\Services\WorkConflictSolver::class)->suggestions($actor,$tenant,$key,$date);
        $this->solverSource=$data['source'];$this->solverItems=$data['items'];$this->proposalIndex=0;
    }
    public function applyProposalAction():\Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('applyProposal')->label(trans('work_hub.apply_proposal'))->requiresConfirmation()->modalHeading(trans('work_hub.proposal_confirm'))
            ->modalDescription(fn()=> $this->solverItems[$this->proposalIndex]['label']??'')
            ->visible(fn()=>isset($this->solverItems[$this->proposalIndex]))
            ->action(function(){[$actor,$tenant]=$this->workspaceContext();abort_unless(isset($this->solverItems[$this->proposalIndex]),422);
                app(\App\ClubUnio\Services\WorkConflictSolver::class)->apply($actor,$tenant,$this->solverSource,$this->solverItems[$this->proposalIndex]);
                $this->solverSource=[];$this->solverItems=[];\Filament\Notifications\Notification::make()->title(trans('work_hub.moved'))->success()->send();});
    }
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

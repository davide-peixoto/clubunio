<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Services\ApprovalQueue;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Livewire\Attributes\{Locked, Url};

class ApprovalCenter extends WorkspacePage
{
    protected string $view = 'clubunio.workspace.approvals';
    protected static ?string $slug = 'approval-center';
    #[Url] public string $kind = '';
    public int $queuePage = 1;
    #[Locked] public ?int $selectedId = null;
    #[Locked] public string $selectedKind = '';
    #[Locked] public string $selectedRevision = '';
    public string $decision = 'approve';
    public string $note = '';
    public static function getNavigationLabel(): string { return trans('workspace.approvals'); }
    public function getTitle(): string { return static::getNavigationLabel(); }
    public static function getNavigationIcon(): string { return 'heroicon-o-check-badge'; }
    public static function canAccess(): bool
    {
        return parent::canAccess() && app(ApprovalQueue::class)->kinds(Filament::auth()->user(), Filament::getTenant()) !== [];
    }
    public function updatedKind(): void { $this->queuePage = 1; $this->closePreview(); }
    public function closePreview(): void { $this->selectedId = null; $this->selectedRevision = ''; $this->note = ''; $this->resetValidation(); }
    public function select(int $id): void
    {
        [$actor,$tenant] = $this->workspaceContext();
        $data = app(ApprovalQueue::class)->preview($actor,$tenant,$this->kind,$id);
        $this->selectedId = $id; $this->selectedKind = $this->kind; $this->selectedRevision = $data['revision'];
        $this->note = ''; $this->decision = $data['decisions'][0] ?? ''; $this->resetValidation();
    }
    public function decideAction(): Action
    {
        return Action::make('decide')->label(trans('workspace.apply_decision'))->icon('heroicon-o-check-circle')->requiresConfirmation()
            ->modalHeading(trans('workspace.confirm_decision'))->modalDescription(fn () => trans('workspace.confirm_details', ['kind' => trans('workspace.kind_'.$this->selectedKind), 'id' => $this->selectedId, 'decision' => trans('workspace.'.$this->decision)]))
            ->visible(fn () => $this->selectedId !== null)
            ->action(function () {
                [$actor,$tenant] = $this->workspaceContext();
                abort_unless($this->selectedId && $this->kind === $this->selectedKind,403);
                app(ApprovalQueue::class)->decide($actor,$tenant,$this->selectedKind,$this->selectedId,$this->selectedRevision,$this->decision,$this->note);
                $this->closePreview();
                \Filament\Notifications\Notification::make()->title(trans('workspace.saved'))->success()->send();
            });
    }
    protected function getViewData(): array
    {
        [$actor,$tenant] = $this->workspaceContext();
        $queue = app(ApprovalQueue::class); $kinds = $queue->kinds($actor,$tenant);
        if ($this->kind === '') $this->kind = array_key_first($kinds);
        abort_unless(isset($kinds[$this->kind]),403);
        $list = $queue->rows($actor,$tenant,$this->kind,$this->queuePage);
        $this->queuePage = $list['page'];
        $preview = null;
        if ($this->selectedId && $this->selectedKind === $this->kind) {
            try { $preview = $queue->preview($actor,$tenant,$this->kind,$this->selectedId); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { if ($e->getStatusCode() !== 404) throw $e; }
        }
        return compact('kinds','list','preview');
    }
}

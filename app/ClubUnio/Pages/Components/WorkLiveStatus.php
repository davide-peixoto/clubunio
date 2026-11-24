<?php
namespace App\ClubUnio\Pages\Components;

use App\ClubUnio\Services\{ActiveTenant,WorkspaceAccess,WorkNotificationHub,PlanningChangeSignal};
use Livewire\Attributes\Locked;
use Livewire\Component;

class WorkLiveStatus extends Component
{
    #[Locked] public array $snapshot=[];
    #[Locked] public string $initialVersion='';
    public function mount():void
    {
        [$actor,$tenant]=app(WorkspaceAccess::class)->context();
        $this->snapshot=session()->get(app(ActiveTenant::class)->key(request()));
        $this->initialVersion=app(PlanningChangeSignal::class)->version($tenant);
    }
    #[\Livewire\Attributes\On('daily-notifications-changed')]
    public function refreshCount():void{}
    public function render()
    {
        [$actor,$tenant]=app(WorkspaceAccess::class)->context();
        app(ActiveTenant::class)->assertSnapshot(request(),$this->snapshot,$tenant);
        return view('clubunio.work-hub.live-status',[
            'unread'=>app(WorkNotificationHub::class)->unreadCount($actor,$tenant),
            'planningChanged'=>$this->initialVersion!==app(PlanningChangeSignal::class)->version($tenant),
        ]);
    }
}

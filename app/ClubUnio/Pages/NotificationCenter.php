<?php
namespace App\ClubUnio\Pages;
use App\ClubUnio\Services\WorkNotificationHub;
class NotificationCenter extends WorkspacePage
{
    protected string $view='clubunio.work-hub.notifications';protected static ?string $slug='notification-center';
    #[\Livewire\Attributes\Locked]public array $noticeDetails=[];
    public function loadDetails(int $id):void{[$a,$t]=$this->workspaceContext();$this->noticeDetails[$id]=app(WorkNotificationHub::class)->details($a,$t,$id);}
    public bool $unreadOnly=true;public int $noticePage=1;
    public static function getNavigationLabel():string{return trans('work_hub.notifications');}
    public function getTitle():string{return static::getNavigationLabel();}
    public static function getNavigationIcon():string{return 'heroicon-o-bell';}
    public function updatedUnreadOnly():void{$this->noticePage=1;}
    public function markRead(int $id):void{[$actor,$tenant]=$this->workspaceContext();app(WorkNotificationHub::class)->readGroup($actor,$tenant,$id);$this->dispatch('daily-notifications-changed');}
    protected function getViewData():array{[$actor,$tenant]=$this->workspaceContext();$notices=app(WorkNotificationHub::class)->page($actor,$tenant,$this->noticePage,$this->unreadOnly);$this->noticePage=$notices['page'];return compact('notices');}
}

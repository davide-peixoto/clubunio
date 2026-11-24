<?php
namespace App\ClubUnio\Pages;
use App\ClubUnio\Services\WorkObjectSearch;
use Livewire\Attributes\Url;
class ObjectSearch extends WorkspacePage
{
    protected string $view='clubunio.work-hub.search';protected static ?string $slug='object-search';
    #[Url]public string $q='';
    public static function getNavigationLabel():string{return trans('work_hub.search');}
    public function getTitle():string{return static::getNavigationLabel();}
    public static function getNavigationIcon():string{return 'heroicon-o-magnifying-glass';}
    public function search():void{$this->workspaceContext();$this->validate(['q'=>'nullable|string|max:100']);}
    protected function getViewData():array{[$actor,$tenant]=$this->workspaceContext();return ['results'=>app(WorkObjectSearch::class)->results($actor,$tenant,$this->q)];}
}

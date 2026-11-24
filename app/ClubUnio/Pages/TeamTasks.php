<?php
namespace App\ClubUnio\Pages;
use App\ClubUnio\Services\WorkTaskService;
use Filament\Facades\Filament;
use Filament\Actions\Action;
use Filament\Forms\Components\{TextInput,Textarea,Select,DatePicker,Hidden};
use Livewire\Attributes\Url;
class TeamTasks extends WorkspacePage
{
    protected string $view='clubunio.work-hub.tasks';protected static ?string $slug='team-tasks';
    #[Url]public ?int $task=null;
    public string $state='open';public bool $mineOnly=false;public int $taskPage=1;
    public static function getNavigationLabel():string{return trans('work_hub.tasks');}
    public function getTitle():string{return static::getNavigationLabel();}
    public static function getNavigationIcon():string{return 'heroicon-o-clipboard-document-check';}
    public static function canAccess():bool{return parent::canAccess()&&app(WorkTaskService::class)->allows(Filament::auth()->user(),Filament::getTenant(),'view');}
    public function updatedState():void{$this->taskPage=1;$this->task=null;}
    public function updatedMineOnly():void{$this->taskPage=1;$this->task=null;}
    private function schema():array
    {
        return [Hidden::make('revision'),TextInput::make('title')->label(trans('work_hub.title'))->required()->maxLength(160),
            Textarea::make('body')->label(trans('work_hub.description'))->maxLength(4000),
            Select::make('assigned_to')->label(trans('work_hub.assignee'))->required()->searchable()->options(fn()=>app(WorkTaskService::class)->assignees(...$this->workspaceContext())),
            DatePicker::make('due_on')->label(trans('work_hub.due'))];
    }
    public function createAction():Action
    {
        return Action::make('create')->label(trans('work_hub.new_task'))->icon('heroicon-o-plus')->schema($this->schema())->fillForm(fn()=>['assigned_to'=>Filament::auth()->id()])
            ->visible(fn()=>app(WorkTaskService::class)->allows(...[...$this->workspaceContext(),'create']))
            ->action(function(array $data){unset($data['revision']);[$actor,$tenant]=$this->workspaceContext();$this->task=app(WorkTaskService::class)->save($actor,$tenant,null,$data);});
    }
    public function editAction():Action
    {
        return Action::make('edit')->label(trans('work_hub.edit'))->schema($this->schema())
            ->fillForm(function(array $arguments){[$a,$t]=$this->workspaceContext();return (array)app(WorkTaskService::class)->get($a,$t,(int)$arguments['id']);})
            ->visible(fn()=>app(WorkTaskService::class)->allows(...[...$this->workspaceContext(),'update']))
            ->action(function(array $arguments,array $data){[$a,$t]=$this->workspaceContext();app(WorkTaskService::class)->save($a,$t,(int)$arguments['id'],array_intersect_key($data,array_flip(['title','body','assigned_to','due_on','revision'])));});
    }
    public function completeAction():Action
    {
        return Action::make('complete')->label(trans('work_hub.change_state'))->requiresConfirmation()
            ->schema([Hidden::make('revision'),Select::make('state')->label(trans('work_hub.state'))->options(collect(['open','doing','done'])->mapWithKeys(fn($s)=>[$s=>trans('work_hub.state_'.$s)])->all())->required()])
            ->fillForm(function(array $arguments){[$a,$t]=$this->workspaceContext();$row=app(WorkTaskService::class)->get($a,$t,(int)$arguments['id']);return ['revision'=>$row->revision,'state'=>$row->state];})
            ->visible(fn()=>app(WorkTaskService::class)->allows(...[...$this->workspaceContext(),'complete']))
            ->action(function(array $arguments,array $data){[$a,$t]=$this->workspaceContext();app(WorkTaskService::class)->transition($a,$t,(int)$arguments['id'],(int)$data['revision'],$data['state']);});
    }
    public function archiveAction():Action{return $this->archiveTransition('archive','archived','deactivate');}
    public function restoreAction():Action{return $this->archiveTransition('restore','open','restore');}
    private function archiveTransition(string $name,string $state,string $right):Action
    {
        return Action::make($name)->label(trans('work_hub.'.$name))->requiresConfirmation()->schema([Hidden::make('revision')])
            ->fillForm(function(array $arguments){[$a,$t]=$this->workspaceContext();return ['revision'=>app(WorkTaskService::class)->get($a,$t,(int)$arguments['id'])->revision];})
            ->visible(fn()=>app(WorkTaskService::class)->allows(...[...$this->workspaceContext(),$right]))
            ->action(function(array $arguments,array $data)use($state){[$a,$t]=$this->workspaceContext();app(WorkTaskService::class)->transition($a,$t,(int)$arguments['id'],(int)$data['revision'],$state);});
    }
    protected function getViewData():array
    {
        [$actor,$tenant]=$this->workspaceContext();$this->validate(['state'=>'in:open,doing,done,archived,all','task'=>'nullable|integer|min:1']);
        $service=app(WorkTaskService::class);$query=$service->visible($actor,$tenant)->when($this->task,fn($q)=>$q->where('id',$this->task))
            ->when(!$this->task&&$this->state!=='all',fn($q)=>$q->where('state',$this->state))->when($this->mineOnly,fn($q)=>$q->where('assigned_to',$actor->id));
        $count=(clone $query)->count();$this->taskPage=max(1,min($this->taskPage,max(1,(int)ceil($count/20))));
        $rows=$query->orderByRaw('due_on IS NULL')->orderBy('due_on')->orderByDesc('id')->offset(($this->taskPage-1)*20)->limit(20)->get();
        $names=\App\ClubUnio\Models\Account::whereIn('id',$rows->pluck('assigned_to'))->pluck('display_name','id');
        foreach($rows as $row){$row->title=\Illuminate\Support\Facades\Crypt::decryptString($row->title_encrypted);$row->body=\Illuminate\Support\Facades\Crypt::decryptString($row->body_encrypted);$row->assignee=$names[$row->assigned_to]??'–';}
        return compact('rows','count');
    }
}

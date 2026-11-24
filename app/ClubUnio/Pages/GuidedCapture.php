<?php
namespace App\ClubUnio\Pages;
use App\ClubUnio\Services\{WorkGuidedCapture,TeamPlanningService};
use App\ClubUnio\Resources\{MemberResource,ExpenseFields};
use Filament\Schemas\{Schema};
use Filament\Schemas\Components\{Wizard,View};
use Filament\Schemas\Components\Wizard\Step;
use Filament\Forms\Components\{TextInput,DatePicker,DateTimePicker,Select,Textarea};
use Filament\Facades\Filament;
use Livewire\Attributes\{Url,Locked};
class GuidedCapture extends WorkspacePage
{
    protected string $view='clubunio.work-hub.capture';protected static ?string $slug='guided-capture';
    #[Url]public string $kind='';public array $data=[];#[Locked]public string $captureCommand='';
    public static function getNavigationLabel():string{return trans('work_hub.capture');}
    public function getTitle():string{return static::getNavigationLabel();}
    public static function getNavigationIcon():string{return 'heroicon-o-sparkles';}
    public static function canAccess():bool{return parent::canAccess()&&app(WorkGuidedCapture::class)->kinds()!==[];}
    public function mount():void
    {
        parent::mount();$kinds=app(WorkGuidedCapture::class)->kinds();if($this->kind==='')$this->kind=array_key_first($kinds);
        abort_unless(isset($kinds[$this->kind]),403);$this->resetCapture();
    }
    public function updatedKind():void{$this->workspaceContext();abort_unless(isset(app(WorkGuidedCapture::class)->kinds()[$this->kind]),403);$this->resetCapture();}
    private function resetCapture():void
    {
        $this->captureCommand=(string)\Illuminate\Support\Str::uuid();
        $start=now('Europe/Zurich')->addDay()->setTime(18,0);
        $this->data=['kind'=>'TRAINING','mode'=>'ANMELDEN','first_name'=>'','last_name'=>'','email'=>'','city'=>'','birth_date'=>null,'starts_at'=>$start->format('Y-m-d\TH:i'),
            'ends_at'=>$start->copy()->addMinutes(90)->format('Y-m-d\TH:i'),'deadline_at'=>now('Europe/Zurich')->format('Y-m-d\TH:i'),'title'=>'','location'=>'','notes'=>''];
        if($this->kind==='member')foreach(['status_id'=>\App\ClubUnio\Models\MemberStatus::class,'type_id'=>\App\ClubUnio\Models\MemberType::class,'billing_recipient_id'=>\App\ClubUnio\Models\BillingRecipient::class] as $key=>$model){
            $q=$model::where('tenant_id',Filament::getTenant()->id)->where('active',true);
            if($key==='status_id')$q->where('ends_membership',false);
            if($key==='billing_recipient_id')$q->where('email_field','email');
            $this->data[$key]=$q->orderBy('id')->value('id');
        }
        $this->form->fill($this->data);$this->resetValidation();
    }
    public function form(Schema $schema):Schema
    {
        [$actor,$tenant]=$this->workspaceContext();
        $first=[];$second=[];
        if($this->kind==='member'){
            $first=[TextInput::make('first_name')->label(trans('work_hub.first_name'))->required()->maxLength(255),TextInput::make('last_name')->label(trans('work_hub.last_name'))->required()->maxLength(255)];
            $second=[Select::make('status_id')->label(trans('work_hub.member_status'))->options(fn()=>$this->memberOptions(\App\ClubUnio\Models\MemberStatus::class))->required(),Select::make('type_id')->label(trans('work_hub.member_type'))->options(fn()=>$this->memberOptions(\App\ClubUnio\Models\MemberType::class))->required(),Select::make('billing_recipient_id')->label(trans('work_hub.billing_recipient'))->options(fn()=>$this->memberOptions(\App\ClubUnio\Models\BillingRecipient::class))->required()->live(),TextInput::make('email')->label(trans('work_hub.email'))->email()->maxLength(254)->required(fn(\Filament\Schemas\Components\Utilities\Get $get)=>$this->recipientField($get('billing_recipient_id'))==='email'),TextInput::make('mother_email')->label(trans('work_hub.mother_email'))->email()->maxLength(254)->required(fn(\Filament\Schemas\Components\Utilities\Get $get)=>$this->recipientField($get('billing_recipient_id'))==='mother_email'),TextInput::make('father_email')->label(trans('work_hub.father_email'))->email()->maxLength(254)->required(fn(\Filament\Schemas\Components\Utilities\Get $get)=>$this->recipientField($get('billing_recipient_id'))==='father_email'),DatePicker::make('birth_date')->label(trans('work_hub.birth_date'))->maxDate(now()),TextInput::make('city')->label(trans('work_hub.city'))->maxLength(255)];
        }elseif($this->kind==='expense'){
            $first=ExpenseFields::header();array_shift($first);
            foreach($first as $field)$field->label(__($field->getLabel()));
            $second=[Select::make('expense_type_id')->label(trans('work_hub.expense_type'))->options(fn()=>\App\ClubUnio\Models\ExpenseType::where('tenant_id',$tenant->id)->where('active',true)->localizedPluck('name','id'))->required()->searchable(),
                TextInput::make('amount')->label(trans('workspace.amount'))->prefix('CHF')->numeric()->minValue(.01)->maxValue(999999999)->required()];
        }else{
            $first=[Select::make('team_season_id')->label(trans('work_hub.team'))->options(fn()=>app(TeamPlanningService::class)->teams($actor,$tenant,true,'create')->orderBy('label')->pluck('label','id'))->required()->searchable(),TextInput::make('title')->label(trans('work_hub.title'))->required()->maxLength(255)];
            $second=[Select::make('kind')->label(trans('work_hub.event_kind'))->options(fn()=>$this->eventOptions('kind'))->required(),Select::make('mode')->label(trans('work_hub.participation'))->options(fn()=>$this->eventOptions('mode'))->required(),DateTimePicker::make('starts_at')->label(trans('workspace.start'))->format('Y-m-d\TH:i')->seconds(false)->required(),DateTimePicker::make('ends_at')->label(trans('work_hub.end'))->format('Y-m-d\TH:i')->seconds(false)->required()->after('starts_at'),
                DateTimePicker::make('deadline_at')->label(trans('work_hub.deadline'))->format('Y-m-d\TH:i')->seconds(false)->required()->beforeOrEqual('starts_at'),TextInput::make('location')->label(trans('work_hub.location'))->maxLength(255)];
        }
        return $schema->statePath('data')->components([Wizard::make([
            Step::make(trans('work_hub.identity'))->schema($first),Step::make(trans('workspace.details'))->schema($second),
            Step::make(trans('work_hub.review'))->schema([View::make('clubunio.work-hub.capture-review')]),
        ])->key('capture-'.$this->kind)->submitAction(view('clubunio.work-hub.capture-submit'))]);
    }
    private function memberOptions(string $model):array{return $model::where('tenant_id',Filament::getTenant()->id)->where('active',true)->localizedPluck('name','id')->all();}
    private function recipientField($id):?string{return \App\ClubUnio\Models\BillingRecipient::where('tenant_id',Filament::getTenant()->id)->where('active',true)->whereKey($id)->value('email_field');}
    private function eventOptions(string $category):array
    {
        [$actor,$tenant]=$this->workspaceContext();$rows=app(TeamPlanningService::class)->options($tenant)[$category]??collect();
        return $rows->filter(fn($row)=>$row->active)->mapWithKeys(fn($row)=>[$row->code=>app(\App\ClubUnio\Services\LookupTranslations::class)->label('planning_options',$row,tenant:$tenant->id)])->all();
    }
    public function captureFacts():array
    {
        $labels=['first_name','last_name','email','mother_email','father_email','birth_date','city','title','location','amount'];$facts=[];
        foreach($labels as $key)if(isset($this->data[$key])&&$this->data[$key]!=='')$facts[]=['label'=>trans($key==='amount'?'workspace.amount':'work_hub.'.$key),'value'=>(string)$this->data[$key]];
        foreach(['starts_at'=>'workspace.start','ends_at'=>'work_hub.end','deadline_at'=>'work_hub.deadline'] as $key=>$label)if($this->kind==='event'&&!empty($this->data[$key]))$facts[]=['label'=>trans($label),'value'=>str_replace('T',' ',$this->data[$key])];
        [$actor,$tenant]=$this->workspaceContext();
        if($this->kind==='member')foreach(['status_id'=>[\App\ClubUnio\Models\MemberStatus::class,'member_status'],'type_id'=>[\App\ClubUnio\Models\MemberType::class,'member_type'],'billing_recipient_id'=>[\App\ClubUnio\Models\BillingRecipient::class,'billing_recipient']] as $key=>[$model,$label])$facts[]=['label'=>trans('work_hub.'.$label),'value'=>$this->memberOptions($model)[$this->data[$key]??0]??''];
        if($this->kind==='event'){
            $team=app(TeamPlanningService::class)->teams($actor,$tenant,true,'create')->where('id',$this->data['team_season_id']??0)->value('label');
            $facts[]=['label'=>trans('work_hub.team'),'value'=>$team??''];
            foreach(['kind'=>'event_kind','mode'=>'participation'] as $key=>$label)$facts[]=['label'=>trans('work_hub.'.$label),'value'=>$this->eventOptions($key)[$this->data[$key]??'']??''];
        }
        if($this->kind==='expense'){
            $member=\App\ClubUnio\Models\Member::where('tenant_id',$tenant->id)->find($this->data['member_id']??0);
            $facts[]=['label'=>trans('work_hub.member'),'value'=>$member?->display_name??''];
            foreach(['team_season_id'=>[\App\ClubUnio\Models\TeamSeason::class,'label','work_hub.team'],'round_id'=>[\App\ClubUnio\Models\ExpenseRound::class,'name','work_hub.round'],'expense_type_id'=>[\App\ClubUnio\Models\ExpenseType::class,'name','work_hub.expense_type']] as $key=>[$model,$field,$label]){
                $row=$model::where('tenant_id',$tenant->id)->find($this->data[$key]??0);
                $value=$field==='name'&&$row?app(\App\ClubUnio\Services\LookupTranslations::class)->label($row->getTable(),$row,tenant:$tenant->id):($row?->$field??'');
                $facts[]=['label'=>trans($label),'value'=>$value];
            }
            $facts[]=['label'=>trans('work_hub.bank'),'value'=>ExpenseFields::bankOptions($this->data['member_id']??null)[$this->data['bank_account_id']??0]??''];
            $facts[]=['label'=>trans('work_hub.description'),'value'=>$this->data['notes']??''];
        }
        return $facts;
    }
    public function save():void
    {
        [$actor,$tenant]=$this->workspaceContext();$state=$this->form->getState();
        $url=app(WorkGuidedCapture::class)->save($actor,$tenant,$this->kind,$state,$this->captureCommand);$this->redirect($url);
    }
    protected function getViewData():array{$this->workspaceContext();return ['kinds'=>app(WorkGuidedCapture::class)->kinds()];}
}

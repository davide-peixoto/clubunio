<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Services\{ActiveTenant, SocialInsightsService, SyncWorkService};
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Crypt;

final class SocialInsights extends Page
{
    protected string $view = 'clubunio.pages.social-insights';
    protected static ?string $slug = 'social-media/insights';
    protected static bool $shouldRegisterNavigation = false;
    public string $platform = 'all';
    public int $days = 30;
    public string $search = '';
    public string $sort = 'date';
    public int $limit = 25;

    public static function canAccess(): bool { return SocialStudio::canAccess(); }
    public function getTitle(): string { return trans('social_insights.title'); }

    private function selection(): ?array
    {
        $encrypted = session('social_insights.selection');
        if (! is_string($encrypted)) return null;
        $data = json_decode(Crypt::decryptString($encrypted),true,512,JSON_THROW_ON_ERROR);
        if (($data['until'] ?? 0)<=time()) { session()->forget('social_insights.selection'); return null; }
        abort_unless(($data['actor'] ?? null) === Filament::auth()->id(),419);
        app(ActiveTenant::class)->assertSnapshot(request(),$data['context']);
        return $data;
    }

    public function choose(int $index): void
    {
        $service = app(SocialInsightsService::class);
        $data = $this->selection();
        abort_unless($data && isset($data['pages'][$index]),404);
        $service->connect(Filament::auth()->user(),Filament::getTenant(),$data['platform'],$data['pages'][$index],$data['expires']);
        session()->forget('social_insights.selection');
        Notification::make()->success()->title(trans('social_insights.connected'))->send();
    }

    public function requestImport(int $id): void
    {
        app(SocialInsightsService::class)->enqueue(Filament::auth()->user(),Filament::getTenant(),$id);
        Notification::make()->success()->title(trans('social_insights.queued'))->send();
    }

    public function disconnectAction(): Action
    {
        return Action::make('disconnect')->label(trans('social_insights.disconnect'))->color('danger')->requiresConfirmation()
            ->action(fn (array $arguments)=>app(SocialInsightsService::class)->disconnect(Filament::auth()->user(),Filament::getTenant(),(int) ($arguments['id'] ?? 0)));
    }

    protected function getViewData(): array
    {
        $service = app(SocialInsightsService::class); $tenant = Filament::getTenant(); $actor = Filament::auth()->user();
        $service->authorize($actor,$tenant);
        abort_unless(in_array($this->platform,['all','instagram','facebook'],true) && in_array($this->days,[7,30,90],true)
            && in_array($this->sort,['date',...SocialInsightsService::METRICS],true) && mb_strlen($this->search)<=100,422);
        $connections = $service->table('connections')->where('tenant_id',$tenant->id)->orderBy('platform')->get([
            'id','platform','name','external_id','active','expires_at','last_synced_at',
        ]);
        $query = $service->table('imported_posts')->where('social_imported_posts.tenant_id',$tenant->id)
            ->join('clubunio.social_connections as connection',fn ($j)=>$j->on('connection.id','=','social_imported_posts.connection_id')
                ->on('connection.tenant_id','=','social_imported_posts.tenant_id'))
            ->where('published_at','>=',now()->subDays($this->days))
            ->when($this->platform!=='all',fn ($q)=>$q->where('connection.platform',$this->platform))
            ->when($this->search!=='',fn ($q)=>$q->whereLike('caption','%'.$this->search.'%'));
        $all = (clone $query)->get(['social_imported_posts.metrics']);
        $totals = [];
        foreach (SocialInsightsService::METRICS as $metric) {
            $values = $all->map(fn ($r)=>json_decode($r->metrics,true)[$metric] ?? null)->filter(fn ($v)=>$v!==null);
            $totals[$metric] = ['value'=>$values->isEmpty()?null:$values->sum(),'coverage'=>$values->count()];
        }
        $posts = $query->select('social_imported_posts.*','connection.platform','connection.name')
            ->when($this->sort==='date',fn ($q)=>$q->orderByDesc('published_at'),
                fn ($q)=>$q->orderByRaw("(metrics->>'".$this->sort."')::bigint DESC NULLS LAST"))
            ->orderByDesc('social_imported_posts.id')->limit(min(200,max(25,$this->limit)))->get();
        foreach ($posts as $post) $post->values = json_decode($post->metrics,true);
        $history = app(SyncWorkService::class)->table()->where('tenant_id',$tenant->id)->where('operation','social_insights')
            ->latest('id')->limit(10)->get(['id','object_id','state','error_code','attempts','updated_at']);
        $followers = [];
        foreach ($connections as $connection) {
            $samples = $service->table('account_snapshots')->where('tenant_id',$tenant->id)->where('connection_id',$connection->id)->orderByDesc('day');
            $latest = (clone $samples)->first();
            $baseline = (clone $samples)->where('day','<=',now('UTC')->subDays($this->days)->toDateString())->first();
            $followers[$connection->id] = ['value'=>$latest?->followers,'delta'=>$latest?->followers!==null && $baseline?->followers!==null
                ? $latest->followers-$baseline->followers : null];
        }
        return compact('connections','posts','totals','history','followers')+[
            'total'=>$all->count(),'selection'=>$this->selection(),'enabled'=>$service->enabled(),
            'context'=>session(app(ActiveTenant::class)->key(request())),
            'canManage'=>app(\App\ClubUnio\Services\ModulePermissionService::class)->allows($actor,$tenant,'social_media','update'),
            'canSync'=>app(\App\ClubUnio\Services\ModulePermissionService::class)->allows($actor,$tenant,'social_media','sync'),
        ];
    }
}

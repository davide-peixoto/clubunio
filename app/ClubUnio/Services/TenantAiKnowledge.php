<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Http, Validator};
use Illuminate\Support\Str;

final class TenantAiKnowledge
{
    private function config(): TenantAiConfiguration { return app(TenantAiConfiguration::class); }
    private function rows(Tenant $tenant, string $name) { return $this->config()->table($name)->where('tenant_id',$tenant->id); }

    private function client(Tenant $tenant, ?array $credentials = null): \Illuminate\Http\Client\PendingRequest
    {
        $c = $credentials ?? $this->config()->credentials($tenant);
        return Http::withToken($c['key'])->withHeaders(['OpenAI-Project'=>$c['project'],'OpenAI-Beta'=>'assistants=v2'])
            ->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(35);
    }

    private function scope(Tenant $tenant): string { return hash('sha256',config('app.url').'|'.$tenant->id); }

    public function stores(Account $actor, Tenant $tenant): array
    {
        $this->config()->authorize($actor,$tenant);
        return $this->rows($tenant,'stores')->where('state','!=','archived')->orderBy('id')->get()->all();
    }

    public function documents(Account $actor, Tenant $tenant): array
    {
        $this->config()->authorize($actor,$tenant);
        return $this->config()->table('documents')->from('clubunio.tenant_ai_documents as d')
            ->join('clubunio.tenant_ai_stores as s',fn ($j)=>$j->on('s.id','=','d.store_id')->on('s.tenant_id','=','d.tenant_id'))
            ->where('d.tenant_id',$tenant->id)->where('d.state','!=','archived')
            ->orderByDesc('d.id')->limit(200)->get(['d.*','s.name as store_name'])->all();
    }

    public function createStore(Account $actor, Tenant $tenant, string $name, string $purpose = 'training'): void
    {
        $this->config()->authorize($actor,$tenant);
        Validator::make(compact('name','purpose'),['name'=>'required|string|max:160','purpose'=>'required|in:training,club'])->validate();
        $this->config()->credentials($tenant);
        $id = $this->rows($tenant,'stores')->insertGetId(['tenant_id'=>$tenant->id,'name'=>$name,'purpose'=>$purpose,'state'=>'creating']);
        try {
            $r = $this->client($tenant)->post('https://api.openai.com/v1/vector_stores',
                ['name'=>$name,'metadata'=>['clubunio_scope'=>$this->scope($tenant),'clubunio_store'=>(string)$id]]);
            abort_unless($r->successful() && preg_match('/^vs_[A-Za-z0-9_-]+$/D',(string)$r->json('id')),502);
            $this->rows($tenant,'stores')->where('id',$id)->update(['remote_id'=>$r->json('id'),'state'=>'ready']);
            $this->config()->event($tenant,$actor,'store.created',$id);
        } catch (\Throwable) {
            $this->rows($tenant,'stores')->where('id',$id)->update(['state'=>'attention']);
            abort(502,trans('tenant_ai.provider_failed'));
        }
    }

    public function attachStore(Account $actor, Tenant $tenant, string $name, string $purpose, string $remoteId): void
    {
        $this->config()->authorize($actor,$tenant);
        Validator::make(compact('name','purpose','remoteId'),['name'=>'required|string|max:160','purpose'=>'required|in:training,club',
            'remoteId'=>['required','regex:/^vs_[A-Za-z0-9_-]{1,90}$/D']])->validate();
        $credentials = $this->config()->credentials($tenant);
        $r = $this->client($tenant,$credentials)->get('https://api.openai.com/v1/vector_stores/'.$remoteId);
        abort_unless($r->successful() && $r->json('id')===$remoteId,502,trans('tenant_ai.connection_failed'));
        $scope = $r->json('metadata.clubunio_scope');
        abort_if($scope && !hash_equals($this->scope($tenant),$scope),403);
        DB::connection('clubunio')->transaction(function () use ($actor,$tenant,$name,$purpose,$remoteId,$r,$credentials) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->config()->authorize($actor,$tenant);
            $current = $this->config()->credentials($tenant);
            abort_unless($current['fingerprint']===$credentials['fingerprint'] && $current['revision']===$credentials['revision'],409);
            abort_if($this->config()->table('stores')->where('remote_id',$remoteId)->exists(),409);
            $id = $this->rows($tenant,'stores')->insertGetId(['tenant_id'=>$tenant->id,'name'=>$name,'purpose'=>$purpose,
                'remote_id'=>$remoteId,'state'=>'ready','usage_bytes'=>(int)$r->json('usage_bytes',0)]);
            $this->config()->event($tenant,$actor,'store.attached',$id);
        });
    }

    public function upload(Account $actor, Tenant $tenant, int $storeId, UploadedFile $file): void
    {
        $this->config()->authorize($actor,$tenant);
        Validator::make(['file'=>$file],['file'=>'required|file|max:20480|mimes:pdf,txt,md,docx'])->validate();
        $store = $this->rows($tenant,'stores')->where('id',$storeId)->where('state','ready')->first();
        abort_unless($store && $store->remote_id,404);
        $bytes = $file->get();
        $extension = strtolower($file->getClientOriginalExtension());
        $name = mb_substr(preg_replace('/[\x00-\x1f\x7f]/','',basename(str_replace('\\','/',$file->getClientOriginalName()))),0,255);
        $id = DB::connection('clubunio')->transaction(function () use ($actor,$tenant,$storeId,$name,$file,$bytes) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->config()->authorize($actor,$tenant);
            abort_unless($this->rows($tenant,'stores')->where('id',$storeId)->where('state','ready')->exists(),409);
            $this->config()->table()->where('tenant_id',$tenant->id)->increment('revision');
            return $this->rows($tenant,'documents')->insertGetId(['tenant_id'=>$tenant->id,'store_id'=>$storeId,'name'=>$name,
                'mime_type'=>$file->getMimeType(),'size_bytes'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'state'=>'stored']);
        });
        $filename = Str::uuid().'.'.$extension;
        try {
            $folder = app(TenantDocumentStorage::class)->folder($tenant,'ai',$id);
            app(TenantDocumentStorage::class)->put($tenant,$folder,$filename,$bytes,$file->getMimeType());
            $this->rows($tenant,'documents')->where('id',$id)->update(['folder'=>$folder,'filename'=>$filename]);
        } catch (\Throwable) {
            $this->rows($tenant,'documents')->where('id',$id)->update(['state'=>'attention','error_code'=>'original_storage_failed']);
            abort(503,trans('tenant_ai.storage_failed'));
        }
        $this->index($actor,$tenant,$id);
    }

    private function index(Account $actor, Tenant $tenant, int $id): void
    {
        $this->config()->authorize($actor,$tenant);
        $document = $this->rows($tenant,'documents')->where('id',$id)->first();
        abort_unless($document && $document->folder && $document->state==='stored',409);
        $store = $this->rows($tenant,'stores')->where('id',$document->store_id)->where('state','ready')->first();
        abort_unless($store,404);
        $bytes = app(TenantDocumentStorage::class)->read($tenant,$document->folder,$document->filename);
        abort_unless(hash_equals($document->sha256,hash('sha256',$bytes)),409);
        $claimed = $this->rows($tenant,'documents')->where('id',$id)->where('state','stored')->update(['state'=>'uploading']);
        abort_unless($claimed===1,409);
        try {
            $r = $this->client($tenant)->attach('file',$bytes,$document->filename,['Content-Type'=>$document->mime_type])
                ->post('https://api.openai.com/v1/files',['purpose'=>'assistants']);
            abort_unless($r->successful() && preg_match('/^file[-_][A-Za-z0-9_-]+$/D',(string)$r->json('id')),502);
            $remote = $r->json('id');
            $this->rows($tenant,'documents')->where('id',$id)->whereNull('remote_file_id')->update(['remote_file_id'=>$remote]);
            DB::connection('clubunio')->transaction(function () use ($actor,$tenant,$id,$store,$remote) {
                // Serialize this document's attachment with removal. The remote
                // file ID stays recorded even if the attachment is uncertain.
                $current = $this->rows($tenant,'documents')->where('id',$id)->lockForUpdate()->first();
                abort_unless($current && $current->state==='uploading',409);
                $this->config()->authorize($actor,$tenant);
                $this->rows($tenant,'documents')->where('id',$id)->update(['state'=>'indexing']);
                $r = $this->client($tenant)->post('https://api.openai.com/v1/vector_stores/'.$store->remote_id.'/files',['file_id'=>$remote]);
                abort_unless($r->successful(),502);
                $this->rows($tenant,'documents')->where('id',$id)->where('state','indexing')
                    ->update(['state'=>$r->json('status')==='completed'?'ready':'indexing']);
            });
            $this->config()->event($tenant,$actor,'document.indexed',$id);
        } catch (\Throwable) {
            $this->rows($tenant,'documents')->where('id',$id)->whereIn('state',['uploading','indexing'])
                ->update(['state'=>'attention','error_code'=>'index_uncertain']);
            // A timed-out attachment can finish remotely after a detach. Never
            // reuse that store automatically, even after local archival.
            $this->rows($tenant,'stores')->where('id',$store->id)->where('state','ready')->update(['state'=>'attention']);
            abort(502,trans('tenant_ai.provider_failed'));
        }
    }

    public function refresh(Account $actor, Tenant $tenant, int $id): void
    {
        $this->config()->authorize($actor,$tenant);
        $d = $this->rows($tenant,'documents')->where('id',$id)->where('state','!=','archived')->first();
        abort_unless($d && $d->remote_file_id,404);
        if ($d->state==='removing') { $this->remove($actor,$tenant,$id); return; }
        $s = $this->rows($tenant,'stores')->where('id',$d->store_id)->where('state','ready')->first();
        abort_unless($s,404);
        $r = $this->client($tenant)->get('https://api.openai.com/v1/vector_stores/'.$s->remote_id.'/files/'.$d->remote_file_id);
        abort_unless($r->successful(),502,trans('tenant_ai.provider_failed'));
        $state = match($r->json('status')) { 'completed'=>'ready','in_progress'=>'indexing',default=>'attention' };
        $changed = $this->rows($tenant,'documents')->where('id',$id)->where('state',$d->state)
            ->update(['state'=>$state,'error_code'=>$state==='attention'?'index_failed':null]);
        abort_unless($changed===1,409);
        $r = $this->client($tenant)->get('https://api.openai.com/v1/vector_stores/'.$s->remote_id);
        if ($r->successful()) $this->rows($tenant,'stores')->where('id',$s->id)->update(['usage_bytes'=>(int)$r->json('usage_bytes',0)]);
    }

    public function remove(Account $actor, Tenant $tenant, int $id): void
    {
        $this->config()->authorize($actor,$tenant);
        $d = $this->rows($tenant,'documents')->where('id',$id)->where('state','!=','archived')->first();
        abort_unless($d,404);
        $s = $this->rows($tenant,'stores')->where('id',$d->store_id)->first();
        DB::connection('clubunio')->transaction(function () use ($actor,$tenant,$id) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->config()->authorize($actor,$tenant);
            $changed = $this->rows($tenant,'documents')->where('id',$id)->whereNotIn('state',['removing','archived'])
                ->update(['state'=>'removing','error_code'=>'detach_pending']);
            if ($changed) $this->config()->table()->where('tenant_id',$tenant->id)->increment('revision');
        });
        if ($d->remote_file_id) {
            $r = $this->client($tenant)->delete('https://api.openai.com/v1/vector_stores/'.$s->remote_id.'/files/'.$d->remote_file_id);
            abort_unless($r->successful() || $r->status()===404,502,trans('tenant_ai.provider_failed'));
        }
        $this->rows($tenant,'documents')->where('id',$id)->update(['state'=>'archived','error_code'=>null]);
        $this->config()->event($tenant,$actor,'document.archived',$id);
    }

    public function removeStore(Account $actor, Tenant $tenant, int $id): void
    {
        $this->config()->authorize($actor,$tenant);
        $store = $this->rows($tenant,'stores')->where('id',$id)->where('state','!=','archived')->first();
        abort_unless($store,404);
        abort_if($this->rows($tenant,'documents')->where('store_id',$id)->where('state','!=','archived')->exists(),409);
        // Detach our binding, never delete an externally managed store or its files.
        $this->rows($tenant,'stores')->where('id',$id)->update(['state'=>'archived']);
        $this->config()->event($tenant,$actor,'store.archived',$id);
    }

    public function download(Account $actor, Tenant $tenant, int $id)
    {
        $this->config()->authorize($actor,$tenant);
        $d = $this->rows($tenant,'documents')->where('id',$id)->first();
        abort_unless($d && $d->folder,404);
        $bytes = app(TenantDocumentStorage::class)->read($tenant,$d->folder,$d->filename);
        return response($bytes)->header('Content-Type',$d->mime_type)->header('Content-Disposition','attachment; filename="'.$d->filename.'"')
            ->header('Cache-Control','private, no-store')->header('X-Content-Type-Options','nosniff');
    }

    public function trainingStores(Tenant $tenant): array
    {
        $this->config()->credentials($tenant);
        abort_if($this->rows($tenant,'documents')->whereNotIn('state',['ready','archived'])->exists(),503,trans('tenant_ai.provider_failed'));
        $stores = $this->rows($tenant,'stores')->where('purpose','training')->where('state','ready')->pluck('remote_id')->filter()->values()->all();
        abort_unless(count($stores)>0 && count($stores)<=2,503,trans('tenant_ai.training_store_required'));
        return $stores;
    }
}

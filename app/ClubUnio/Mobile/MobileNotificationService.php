<?php
namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, Tenant};
use Illuminate\Support\Facades\{Crypt, DB};

/** Personal original-screen inbox; provider opt-in never controls reading owned notices. */
final class MobileNotificationService
{
    private const SOURCES = ['expense' => 'expense', 'registration' => 'registration', 'bus_reservation' => 'bus',
        'member_change_request' => 'member_change', 'team_event' => 'planning', 'pitch_planning' => 'pitch_planning'];

    private function rows(Account $actor, Tenant $tenant)
    {
        return DB::connection('clubunio')->table('clubora.mobile_inbox')->where('tenant_id', $tenant->id)->where('account_id', $actor->id);
    }

    /** References real, currently visible owned source rows, never copies private payloads. */
    private function allowed(Account $actor, Tenant $tenant, string $kind, int $object, array &$rights): bool
    {
        // One operation only; mutation callers hold the tenant lock throughout authorization.
        $key = $kind.':'.$object;
        return $rights[$key] ??= app(MobilePushOutbox::class)->targetAllowed($actor, $tenant, $kind, $object, false);
    }

    private function materialize(Account $actor, Tenant $tenant, array &$rights): void
    {
        app(MobileAccess::class)->enter($actor, $tenant);
        DB::connection('clubunio')->table('clubora.notifications as source')->where('source.tenant_id', $tenant->id)->where('source.account_id', $actor->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('clubora.mobile_inbox as indexed')
                ->whereColumn('indexed.tenant_id', 'source.tenant_id')->whereColumn('indexed.account_id', 'source.account_id')
                ->whereRaw("indexed.event_key = 'notification:' || source.id::text"))
            ->whereIn('object_type', array_keys(self::SOURCES))->select(['id', 'object_type', 'object_id', 'created_at', 'read_at'])
            ->chunkById(100, function ($rows) use ($actor, $tenant, &$rights) {
                $insert = [];
                foreach ($rows as $row) {
                    $kind = self::SOURCES[$row->object_type];
                    if (! $this->allowed($actor, $tenant, $kind, (int) $row->object_id, $rights)) continue;
                    $insert[] = ['tenant_id' => $tenant->id, 'account_id' => $actor->id,
                        'kind' => $kind, 'object_id' => $row->object_id, 'event_key' => 'notification:'.$row->id,
                        'read_at' => $row->read_at, 'created_at' => $row->created_at];
                }
                if ($insert) $this->rows($actor, $tenant)->insertOrIgnore($insert);
            });
        // Preserve web/source read state without a separate update per old notification.
        $source = DB::connection('clubunio')->table('clubora.notifications as source')
            ->whereColumn('source.tenant_id', 'inbox.tenant_id')->whereColumn('source.account_id', 'inbox.account_id')
            ->whereColumn('source.object_id', 'inbox.object_id')->whereRaw("inbox.event_key = 'notification:' || source.id::text")
            ->whereRaw("inbox.kind = CASE source.object_type
                WHEN 'expense' THEN 'expense' WHEN 'registration' THEN 'registration' WHEN 'bus_reservation' THEN 'bus'
                WHEN 'member_change_request' THEN 'member_change' WHEN 'team_event' THEN 'planning' WHEN 'pitch_planning' THEN 'pitch_planning' END")
            ->whereNotNull('source.read_at');
        DB::connection('clubunio')->table('clubora.mobile_inbox as inbox')->where('inbox.tenant_id', $tenant->id)->where('inbox.account_id', $actor->id)
            ->whereNull('inbox.read_at')->whereExists((clone $source)->selectRaw('1'))
            ->update(['read_at' => (clone $source)->select('source.read_at')->limit(1)]);
    }

    private function visible(Account $actor, Tenant $tenant): array
    {
        $rights = [];
        $this->materialize($actor, $tenant, $rights);
        $visible = [];
        foreach ($this->rows($actor, $tenant)->orderByDesc('id')->cursor() as $row) {
            if ($this->allowed($actor, $tenant, $row->kind, (int) $row->object_id, $rights)) $visible[] = $row;
        }
        return $visible;
    }

    private function text(Account $actor, Tenant $tenant, object $row, array $sources): array
    {
        if ($row->kind === 'admin_notice') {
            $notice = app(MobileAdminNoticeAccess::class)->notice($actor, $tenant, (int) $row->object_id);
            return [Crypt::decryptString($notice->title_encrypted), Crypt::decryptString($notice->body_encrypted)];
        }
        if ($row->kind === 'penalty') {
            $notice = app(MobilePenaltyAccess::class)->notice($actor, $tenant, (int) $row->object_id);
            return [Crypt::decryptString($notice->title_encrypted), Crypt::decryptString($notice->body_encrypted)];
        }
        if (preg_match('/^notification:([1-9][0-9]*)$/D', $row->event_key, $matches)) {
            $source = $sources[(int) $matches[1]] ?? null;
            if ($source && (int) $source->object_id === (int) $row->object_id && (self::SOURCES[$source->object_type] ?? null) === $row->kind) return [$source->title, $source->body];
        }
        return match ($row->kind) {
            'invoice' => ['Neue Rechnung', 'Eine Rechnung ist verfügbar.'],
            'planning' => ['Aufgebot', 'Das Aufgebot ist verfügbar.'],
            'announcement' => ['Vereinsankündigung', 'Eine Vereinsankündigung ist verfügbar.'],
            default => ['Neue Mitteilung', 'Weitere Informationen sind im zugehörigen Eintrag verfügbar.'],
        };
    }

    public function page(Account $actor, Tenant $tenant, int $start, int $limit, bool $unreadOnly): array
    {
        $all = $this->visible($actor, $tenant);
        $unread = count(array_filter($all, fn ($r) => $r->read_at === null));
        $selected = $unreadOnly ? array_values(array_filter($all, fn ($r) => $r->read_at === null)) : $all;
        $page = array_slice($selected, $start, $limit);
        $sourceIds = [];
        foreach ($page as $row) if (preg_match('/^notification:([1-9][0-9]*)$/D', $row->event_key, $match)) $sourceIds[] = (int) $match[1];
        $sources = $sourceIds ? DB::connection('clubunio')->table('clubora.notifications')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)
            ->whereIn('id', $sourceIds)->get(['id','object_type','object_id','title','body'])->keyBy('id')->all() : [];
        $rows = [];
        foreach ($page as $row) {
            [$title, $body] = $this->text($actor, $tenant, $row, $sources);
            $rows[] = ['NotificationID' => (int) $row->id, 'title' => $title, 'body' => $body, 'objectType' => $row->kind,
                'objectId' => (int) $row->object_id, 'data' => ['tenant_id' => (int) $tenant->id, 'kind' => $row->kind, 'object_id' => (int) $row->object_id],
                'readAt' => $row->read_at, 'createdAt' => $row->created_at];
        }
        return ['notifications' => $rows, 'unreadCount' => $unread, 'totalRecordCount' => count($selected), 'watermark' => (int) ($all[0]->id ?? 0)];
    }

    public function count(Account $actor, Tenant $tenant): array
    {
        $rights=[]; $this->materialize($actor,$tenant,$rights); $unread=0;
        foreach($this->rows($actor,$tenant)->whereNull('read_at')->cursor() as $row) {
            if($this->allowed($actor,$tenant,$row->kind,(int)$row->object_id,$rights)) $unread++;
        }
        return ['unreadCount'=>$unread,'watermark'=>(int)($this->rows($actor,$tenant)->max('id')??0)];
    }

    public function read(Account $actor, Tenant $tenant, ?int $id, ?int $through, array $observed = []): array
    {
        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $through, $observed) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if(count($observed)>100 || array_filter($observed,fn($value)=>(!is_int($value) && !(is_string($value)&&ctype_digit($value))) || (int)$value<=0)) {
                throw new \InvalidArgumentException('Invalid observed notification identifiers');
            }
            $observed=array_unique(array_map('intval',$observed)); $rights=[];
            // Materialization/source-sync can write metadata, so it stays outside the facts frame.
            $this->materialize($actor,$tenant,$rights);
            $projection=app(MobileReadContext::class)->duringLockedProjection(request(),$actor,$tenant,function()use($actor,$tenant,$id,$through,$observed,&$rights){
                $unread=$this->rows($actor,$tenant)->whereNull('read_at')->get();
                $checked=$id!==null ? $this->rows($actor,$tenant)->where('id',$id)->get()
                    : ($observed ? $this->rows($actor,$tenant)->whereIn('id',$observed)->where('id','<=',$through)->get() : collect());
                if($id!==null && $checked->isEmpty()) abort(404);
                $marked=[]; $confirmed=[]; $remaining=0;
                foreach($unread->concat($checked)->unique('id') as $row) {
                    $allowed=$this->allowed($actor,$tenant,$row->kind,(int)$row->object_id,$rights);
                    if(!$allowed) { if($id!==null && (int)$row->id===$id) abort(404); continue; }
                    $selected=$id!==null ? (int)$row->id===$id : (int)$row->id<=(int)$through;
                    if($row->read_at===null) {
                        if($selected) $marked[]=(int)$row->id;
                        else $remaining++;
                    }
                    if(($id!==null || in_array((int)$row->id,$observed,true)) && ($row->read_at!==null || $selected)) $confirmed[]=(int)$row->id;
                }
                return ['marked'=>$marked,'confirmed'=>$confirmed,'remaining'=>$remaining];
            });
            // No authorization snapshot survives into the read-marker writes.
            $stamp=now()->toIso8601String();
            foreach(array_chunk($projection['marked'],500) as $ids) {
                $this->rows($actor,$tenant)->whereIn('id',$ids)->whereNull('read_at')->update(['read_at'=>$stamp]);
                $indexed=DB::connection('clubunio')->table('clubora.mobile_inbox as indexed')->where('indexed.tenant_id',$tenant->id)
                    ->where('indexed.account_id',$actor->id)->whereIn('indexed.id',$ids)
                    ->whereColumn('indexed.object_id','source.object_id')->whereRaw("indexed.event_key = 'notification:' || source.id::text")
                    ->whereRaw("indexed.kind = CASE source.object_type
                        WHEN 'expense' THEN 'expense' WHEN 'registration' THEN 'registration' WHEN 'bus_reservation' THEN 'bus'
                        WHEN 'member_change_request' THEN 'member_change' WHEN 'team_event' THEN 'planning' WHEN 'pitch_planning' THEN 'pitch_planning' END");
                DB::connection('clubunio')->table('clubora.notifications as source')->where('source.tenant_id',$tenant->id)
                    ->where('source.account_id',$actor->id)->whereNull('source.read_at')->whereExists($indexed->selectRaw('1'))->update(['read_at'=>$stamp]);
            }
            return ['unreadCount'=>$projection['remaining'],'read'=>true,'through_id'=>$through,
                'marked_ids'=>$projection['marked'],'read_ids'=>$projection['confirmed']];
        });
    }
}

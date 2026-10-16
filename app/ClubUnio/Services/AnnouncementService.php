<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\AccountRole;
use App\ClubUnio\Models\Announcement;
use App\ClubUnio\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AnnouncementService
{
    public const TARGETS = ['all' => 'Alle erreichbaren Benutzer', 'roles' => 'Benutzerrollen', 'teams' => 'Mannschaften inklusive berechtigter Eltern', 'accounts' => 'Einzelne Benutzer'];

    public static function states(Tenant $tenant): array
    {
        return DB::connection('clubunio')->table('clubora.announcement_statuses')->where('tenant_id', $tenant->id)->orderBy('sort_order')->pluck('name', 'code')->all();
    }

    public function save(Account $actor, Tenant $tenant, ?int $id, array $input): Announcement
    {
        return $this->persist($actor, $tenant, $id, $input, $id ? 'update' : 'create');
    }

    private function persist(Account $actor, Tenant $tenant, ?int $id, array $input, string $function): Announcement
    {
        $this->authorize($actor, $tenant, $function);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $input, $function) {
            $this->lock($actor, $tenant, $function);
            $r = $id ? Announcement::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id) : new Announcement;
            $this->draft($r, (int) ($input['revision'] ?? 0));
            $rules = ['title' => ['required', 'string', 'max:200', 'not_regex:/[\r\n]|<[^>]*>/'],
                'body' => ['required', 'string', 'max:10000', 'not_regex:/<[^>]*>/'], 'target_type' => ['required', Rule::in(array_keys(self::TARGETS))],
                'target_ids' => 'present|array|max:500', 'target_ids.*' => ($input['target_type'] ?? null) === 'roles' ? 'required|string|max:30|distinct' : 'required|integer|min:1|distinct', 'revision' => 'nullable|integer|min:0'];
            if (array_diff(array_keys($input), array_keys($rules))) {
                $this->error('Unerlaubte Felder.');
            }
            $data = Validator::make($input, $rules)->validate();
            $ids = $data['target_ids'];
            if ($data['target_type'] === 'all') {
                if ($ids) {
                    $this->error('Bei allen Benutzern darf keine Einzelauswahl gesetzt sein.');
                }
            } else {
                if (! $ids) {
                    $this->error('Bitte mindestens einen Empfänger oder eine Gruppe auswählen.');
                }
                if ($data['target_type'] === 'roles') {
                    $valid = AccountRole::where('active', true)->whereIn('code', $ids)->count();
                } else {
                    Validator::make(['ids' => $ids], ['ids.*' => 'required|integer|min:1'])->validate();
                    $query = DB::connection('clubunio')->table($data['target_type'] === 'teams' ? 'clubora.team_seasons' : 'clubora.tenant_accounts')->where('tenant_id', $tenant->id);
                    $valid = $query->when($data['target_type'] === 'teams', fn ($q) => $q->where('active', true))->whereIn($data['target_type'] === 'teams' ? 'id' : 'account_id', $ids)->count();
                    $ids = array_map('intval', $ids);
                }
                if ($valid !== count($ids)) {
                    $this->error('Auswahl enthält fremde oder ungültige Werte.');
                }
            }
            sort($ids);
            $r->forceFill(['tenant_id' => $tenant->id, 'title' => trim($data['title']), 'body' => trim($data['body']), 'target_type' => $data['target_type'],
                'target_ids' => $ids, 'state' => 'draft', 'revision' => $id ? $r->revision + 1 : 1,
                'preview_hash' => null, 'preview_token' => null, 'preview_by' => null, 'preview_expires_at' => null]);
            if (! $id) {
                $r->forceFill(['created_by' => $actor->id]);
            }
            $r->save();
            $this->audit($actor, $r, $id ? 'edited' : 'created');

            return $r;
        });
    }

    public function prepare(Account $actor, Tenant $tenant, int $id, int $revision): array
    {
        $this->authorize($actor, $tenant, 'send');

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision) {
            $this->lock($actor, $tenant, 'send');
            $r = Announcement::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
            $this->draft($r, $revision);
            $plan = $this->plan($tenant, $r);
            $r->forceFill(['preview_hash' => $plan['hash'], 'preview_token' => (string) Str::uuid(), 'preview_by' => $actor->id, 'preview_expires_at' => now()->addMinutes(15)])->save();

            return ['revision' => $r->revision, 'preview_token' => $r->preview_token];
        });
    }

    public function duplicate(Account $actor, Tenant $tenant, int $id): Announcement
    {
        $this->authorize($actor, $tenant, 'copy');
        $r = Announcement::where('tenant_id', $tenant->id)->findOrFail($id);

        return $this->persist($actor, $tenant, null, $r->only(['title', 'body', 'target_type', 'target_ids']), 'copy');
    }

    public function preview(Account $actor, Tenant $tenant, int $id): array
    {
        $this->authorize($actor, $tenant, 'view');
        $r = Announcement::where('tenant_id', $tenant->id)->findOrFail($id);
        if ($r->state === 'draft') {
            $plan = $this->plan($tenant, $r);

            return ['title' => $plan['message']['title'], 'body' => $plan['message']['body'], 'count' => count($plan['ids']),
                'names' => Account::whereIn('id', $plan['ids'])->orderBy('display_name')->limit(20)->pluck('display_name')->all()];
        }

        return ['title' => $r->rendered_title ?: $r->title, 'body' => $r->rendered_body ?: $r->body,
            'count' => $r->recipient_count, 'names' => []];
    }

    public function publish(Account $actor, Tenant $tenant, int $id, int $revision, string $token): int
    {
        $this->authorize($actor, $tenant, 'send');

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $token) {
            $this->lock($actor, $tenant, 'send');
            $r = Announcement::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
            $this->draft($r, $revision);
            if ($r->preview_by !== $actor->id || ! $r->preview_expires_at?->isFuture() || ! hash_equals((string) $r->preview_token, $token)) {
                $this->error('Vorschau fehlt oder ist abgelaufen. Bitte erneut öffnen.');
            }
            $plan = $this->plan($tenant, $r);
            if (! hash_equals((string) $r->preview_hash, $plan['hash'])) {
                $this->error('Empfänger, Text oder Vorlage wurden geändert. Bitte Vorschau erneut öffnen.');
            }
            $db = DB::connection('clubunio');
            foreach (array_chunk($plan['ids'], 250) as $chunk) {
                $db->table('clubora.announcement_recipients')->insert(array_map(fn ($account) => ['tenant_id' => $tenant->id, 'announcement_id' => $r->id, 'account_id' => $account], $chunk));
                $db->table('clubora.notifications')->insert(array_map(fn ($account) => ['tenant_id' => $tenant->id, 'announcement_id' => $r->id, 'account_id' => $account,
                    'title' => $plan['message']['title'], 'body' => $plan['message']['body'], 'object_type' => 'announcement', 'object_id' => $r->id], $chunk));
            }
            $r->forceFill(['state' => 'published', 'revision' => $revision + 1, 'published_at' => now(), 'published_by' => $actor->id,
                'recipient_count' => count($plan['ids']), 'rendered_title' => $plan['message']['title'], 'rendered_body' => $plan['message']['body'],
                'template_id' => $plan['message']['template_id'], 'template_revision' => $plan['message']['revision'], 'preview_token' => null, 'preview_hash' => null])->save();
            $this->audit($actor, $r, 'published');

            return $r->recipient_count;
        });
    }

    public function withdraw(Account $actor, Tenant $tenant, int $id, int $revision): void
    {
        $this->authorize($actor, $tenant, 'deactivate');
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision) {
            $this->lock($actor, $tenant, 'deactivate');
            $r = Announcement::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
            if ($r->state === 'withdrawn' || $r->revision !== $revision) {
                $this->error('Datensatz wurde inzwischen geändert. Bitte erneut öffnen.');
            }
            $r->forceFill(['state' => 'withdrawn', 'withdrawn_at' => now(), 'revision' => $revision + 1, 'preview_token' => null, 'preview_hash' => null])->save();
            DB::connection('clubunio')->table('clubora.notifications')->where('tenant_id', $tenant->id)->where('announcement_id', $id)
                ->update(['title' => 'Ankündigung zurückgezogen', 'body' => 'Diese Ankündigung wurde von der Verwaltung zurückgezogen.']);
            $this->audit($actor, $r, 'withdrawn');
        });
    }

    public function portal(Account $actor, Tenant $tenant): Builder
    {
        abort_unless(app(MemberAccessService::class)->canEnter($actor->fresh(), $tenant), 403);

        return Announcement::where('tenant_id', $tenant->id)->where('state', 'published')->whereIn('id', DB::connection('clubunio')->table('clubora.announcement_recipients')
            ->where('tenant_id', $tenant->id)->where('account_id', $actor->id)->select('announcement_id'));
    }

    private function plan(Tenant $tenant, Announcement $r): array
    {
        $message = app(MessageTemplateService::class)->render($tenant, 'announcement.manual', 'push', ['verein' => $tenant->name, 'titel' => $r->title, 'nachricht' => $r->body]);
        if (! $message || mb_strlen($message['title']) > 200 || mb_strlen($message['body']) > 10000) {
            $this->error('Interne Ankündigungsvorlage fehlt, ist deaktiviert oder erzeugt zu langen Text.');
        }
        $ids = $this->recipients($tenant, $r)->orderBy('clubora.accounts.id')->limit(5001)->pluck('clubora.accounts.id')->map(fn ($v) => (int) $v)->all();
        if (! $ids || count($ids) > 5000) {
            $this->error('Keine erreichbaren Empfänger oder mehr als 5000. Bitte Auswahl prüfen.');
        }

        return ['message' => $message, 'ids' => $ids, 'hash' => hash('sha256', json_encode([$r->revision, $message, $ids], JSON_THROW_ON_ERROR))];
    }

    private function recipients(Tenant $tenant, Announcement $r): Builder
    {
        $db = DB::connection('clubunio');
        $today = now('Europe/Zurich')->toDateString();
        // Reuse active membership semantics; only approved self/minor-parent links count.
        $links = $db->table('clubora.account_member_links as l')->selectRaw('1')->where('l.tenant_id', $tenant->id)
            ->whereColumn('l.member_id', 'clubora.members.id')->whereColumn('l.account_id', 'clubora.accounts.id')->where('l.state', 'approved')
            ->where(fn ($q) => $q->where('l.kind', 'self')->orWhere(fn ($q) => $q->where('l.kind', 'parent')->where('birth_date', '<=', $today)->whereRaw("birth_date + interval '18 years' > CAST(? AS date)", [$today])));
        $linked = app(MemberAccessService::class)->activeMembers($tenant)->selectRaw('1')->whereExists($links);
        $memberships = $db->table('clubora.tenant_accounts as ta')->selectRaw('1')->where('ta.tenant_id', $tenant->id)->whereColumn('ta.account_id', 'clubora.accounts.id')
            ->where(fn ($q) => $q->whereIn('ta.role', ['admin', 'office'])->orWhere(fn ($q) => $q->where('ta.portal_enabled', true)->whereExists($linked->toBase())));
        if ($r->target_type === 'roles') {
            $memberships->whereIn('ta.role', $r->target_ids);
        }
        $query = Account::where('active', true)->whereExists($memberships);
        if ($r->target_type === 'accounts') {
            $query->whereIn('id', $r->target_ids);
        }
        if ($r->target_type === 'teams') {
            $teams = $db->table('clubora.team_seasons as ts')->join('clubora.teams as t', fn ($j) => $j->on('t.id', '=', 'ts.team_id')->on('t.tenant_id', '=', 'ts.tenant_id'))
                ->where('ts.tenant_id', $tenant->id)->where('ts.active', true)->where('t.active', true)->whereIn('ts.id', $r->target_ids)->select('ts.id');
            $teamLinks = clone $linked;
            $teamLinks->where(function ($q) use ($teams, $tenant, $db) {
                foreach (['team_players', 'team_staff', 'technical_leads'] as $table) {
                    $q->orWhereIn('clubora.members.id', $db->table('clubora.'.$table)->where('tenant_id', $tenant->id)->where('active', true)->whereIn('team_season_id', $teams)->select('member_id'));
                }
            });
            $query->whereExists($teamLinks->toBase());
        }

        return $query;
    }

    private function authorize(Account $actor, Tenant $tenant, string $function): void
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'announcements', $function);
    }

    private function lock(Account $actor, Tenant $tenant, string $function): void
    {
        Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
        $this->authorize($actor, $tenant, $function);
    }

    private function draft(Announcement $r, int $revision): void
    {
        if ($r->exists && ($r->state !== 'draft' || $r->revision !== $revision)) {
            $this->error('Entwurf wurde inzwischen geändert oder abgeschlossen. Bitte erneut öffnen.');
        }
    }

    private function error(string $message): never
    {
        throw ValidationException::withMessages(['title' => $message]);
    }

    private function audit(Account $actor, Announcement $r, string $operation): void
    {
        DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $r->tenant_id, 'actor_id' => $actor->id,
            'object_type' => 'announcements', 'object_id' => $r->id, 'after_value' => json_encode(['operation' => $operation, 'revision' => $r->revision,
                'target_type' => $r->target_type, 'recipient_count' => $r->recipient_count], JSON_THROW_ON_ERROR)]);
    }
}

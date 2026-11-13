<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Jobs\TriageSupportTicket;
use App\ClubUnio\Services\{LanguagePreferences, SupportAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Crypt, DB};
use Illuminate\Support\Str;

final class SupportCenterController
{
    public function __construct(private SupportAccess $access) {}

    public function __invoke(Request $request, ?int $club = null)
    {
        abort_unless(config('clubunio-support.enabled'), 404);
        $platform = $request->routeIs('clubunio.platform.support');
        $actor = $request->attributes->get('mobile.actor') ?? $request->user('clubunio') ?? $request->user('portal');
        $staff = false;
        if ($platform || $club !== null) {
            abort_unless($actor, 401);
            if ($platform) {
                $this->access->platform($request, $actor);
            } else {
                $staff = $this->access->staff($this->access->role($request, $actor, Tenant::findOrFail($club)));
            }
        }
        $db = DB::connection('clubunio');
        $tickets = fn () => $platform ? $db->table('clubora.support_tickets')->where('audience', 'platform')
            : $this->access->tickets($club, $actor->id, $staff);
        $locale = app(LanguagePreferences::class)->resolve($actor?->preferred_locale, app()->getLocale());
        $assignees = fn () => $db->table($platform ? 'clubora.platform_admins as access' : 'clubora.tenant_accounts as access')
            ->join('clubora.accounts as account', 'account.id', '=', 'access.account_id')
            ->where('account.active', true)->where('account.api_only', false)
            ->when($platform, fn ($q) => $q->where('access.active', true), fn ($q) => $q
                ->where('access.tenant_id', $club)->whereIn('access.role', \App\ClubUnio\Services\ModulePermissionService::FULL_BACKOFFICE_ROLES));
        $editableArticles = fn () => $db->table('clubora.support_articles')->where('tenant_id', $club)
            ->whereIn('audience', $platform ? ['public','platform'] : ['club','club_staff']);
        if ($request->isMethod('GET') && $request->filled('attachment')) {
            abort_unless($platform || $club !== null, 404);
            $download = $request->validate(['attachment' => 'required|uuid', 'ticket' => 'required|uuid']);
            abort_unless($tickets()->where('id', $download['ticket'])->exists(), 404);
            $file = $db->table('clubora.support_attachments as file')->join('clubora.support_posts as post', 'post.id', '=', 'file.post_id')
                ->where('file.id', $download['attachment'])->where('post.ticket_id', $download['ticket'])
                ->when(!$staff && !$platform, fn ($q) => $q->where('post.internal', false))->first(['file.*']);
            abort_unless($file, 404);
            $response = response(Crypt::decryptString($file->contents), 200, [
                'Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            ]);
            $response->headers->set('Content-Disposition', $response->headers->makeDisposition('attachment', $file->filename, 'attachment'));
            return $response;
        }
        if ($request->isMethod('POST') && $request->input('operation') === 'ask') {
            $input = $request->validate(['body' => 'required|string|max:500', 'previous_topic' => 'nullable|in:login,expense']);
            $answer = app(\App\ClubUnio\Services\SupportGuide::class)->answer($input['body'], $locale, $input['previous_topic'] ?? null);
            return response()->json(['data' => $answer])->header('Cache-Control', 'private, no-store');
        }
        if ($request->isMethod('POST')) {
            abort_unless($platform || $club !== null, 403);
            $d = $request->validate(['operation' => 'required|in:create,reply,escalate,close,article,assign',
                'ticket' => 'nullable|uuid', 'subject' => 'nullable|string|max:160', 'body' => 'nullable|string|max:10000',
                'internal' => 'nullable|boolean', 'audience' => 'nullable|in:public,club,club_staff,platform',
                'locale' => 'nullable|in:de,en', 'article_id' => 'nullable|integer|min:1', 'published' => 'nullable|boolean',
                'assignee_id' => 'nullable|integer|min:1', 'attachment' => 'nullable|file|max:4096|mimetypes:image/png,image/jpeg,application/pdf',
                'video_url' => 'nullable|string|max:2048|url:https', 'share' => 'nullable|array|max:5', 'share.*' => 'uuid|distinct']);
            abort_if(!empty($d['share']) && $d['operation'] !== 'escalate', 422);
            abort_if(isset($d['attachment']) && !in_array($d['operation'], ['create','reply'], true), 422);
            $id = $db->transaction(function () use ($db, $tickets, $d, $club, $platform, $staff, $actor, $assignees, $editableArticles, $locale) {
                $body = trim($d['body'] ?? '');
                $title = trim($d['subject'] ?? '');
                $operation = $d['operation'];
                if ($operation === 'article') {
                    abort_unless($platform || $staff, 403);
                    abort_unless($title !== '' && $body !== '' && isset($d['locale'], $d['audience']), 422);
                    abort_unless(in_array($d['audience'], $platform ? ['public','platform'] : ['club','club_staff'], true), 403);
                    $values = ['tenant_id' => $club, 'title' => $title, 'body' => $body, 'video_url' => $d['video_url'] ?? null,
                        'audience' => $d['audience'], 'locale' => $d['locale'], 'published' => $d['published'] ?? true];
                    if (isset($d['article_id'])) {
                        $article = $editableArticles()->where('id', $d['article_id']);
                        abort_unless((clone $article)->lockForUpdate()->first(), 404);
                        $article->update($values);
                    } else {
                        $db->table('clubora.support_articles')->insert($values);
                    }
                    return null;
                }
                if ($operation === 'create') {
                    abort_unless(!$platform && $title !== '' && $body !== '', 422);
                    $id = (string) Str::uuid();
                    $db->table('clubora.support_tickets')->insert(['id' => $id, 'tenant_id' => $club, 'requester_id' => $actor->id,
                        'audience' => 'club', 'subject' => $title]);
                } else {
                    $ticket = $tickets()->where('id', $d['ticket'] ?? null)->lockForUpdate()->first();
                    abort_unless($ticket, 404);
                    $id = $ticket->id;
                    if ($operation === 'close') {
                        abort_unless($staff || $platform, 403);
                        $tickets()->where('id', $id)->update(['status' => 'closed']);
                        return $id;
                    }
                    abort_if($ticket->status === 'closed', 409);
                    if ($operation === 'assign') {
                        abort_unless($staff || $platform, 403);
                        $assignee = $d['assignee_id'] ?? null;
                        abort_if($assignee && !$assignees()->where('account.id', $assignee)->sharedLock()->first(['account.id']), 422);
                        $tickets()->where('id', $id)->update(['assignee_id' => $assignee]);
                        return $id;
                    }
                    if ($operation === 'escalate') {
                        abort_unless($staff && !$platform, 403);
                        abort_unless($title !== '' && $body !== '', 422);
                        abort_if($db->table('clubora.support_tickets')->where('source_id', $id)->exists(), 409);
                        $id = (string) Str::uuid();
                        $db->table('clubora.support_tickets')->insert(['id' => $id, 'tenant_id' => $club, 'requester_id' => $actor->id,
                            'audience' => 'platform', 'source_id' => $ticket->id, 'subject' => $title]);
                    }
                }
                abort_unless($body !== '', 422);
                $internal = (bool) ($d['internal'] ?? false);
                abort_if($internal && (!$staff && !$platform || $operation !== 'reply'), 403);
                $post = $db->table('clubora.support_posts')->insertGetId(['ticket_id' => $id, 'author_id' => $actor->id, 'body' => $body, 'internal' => $internal]);
                if ($operation === 'escalate' && !empty($d['share'])) {
                    $files = $db->table('clubora.support_attachments as file')
                        ->join('clubora.support_posts as source', 'source.id', '=', 'file.post_id')
                        ->where('source.ticket_id', $ticket->id)->where('source.internal', false)
                        ->whereIn('file.id', $d['share'])->get(['file.filename','file.mime','file.contents']);
                    abort_unless($files->count() === count($d['share']), 422);
                    foreach ($files as $file) {
                        $db->table('clubora.support_attachments')->insert((array) $file + ['id' => (string) Str::uuid(), 'post_id' => $post]);
                    }
                }
                if ($upload = $d['attachment'] ?? null) {
                    $filename = Str::limit(trim(preg_replace('~[\\\\/\x00-\x1f\x7f]~', '_', $upload->getClientOriginalName())), 180, '');
                    $db->table('clubora.support_attachments')->insert(['id' => (string) Str::uuid(), 'post_id' => $post,
                        'filename' => $filename ?: 'attachment', 'mime' => $upload->getMimeType(), 'contents' => Crypt::encryptString($upload->get())]);
                }
                if ($operation === 'reply' && !$internal && !$platform && (int) $ticket->requester_id !== (int) $actor->id) {
                    $recipient = $db->table('clubora.tenant_accounts as membership')->join('clubora.accounts as account', 'account.id', '=', 'membership.account_id')
                        ->where('membership.tenant_id', $club)->where('account.id', $ticket->requester_id)
                        ->where('account.active', true)->where('account.api_only', false)->sharedLock()->first(['account.id','account.preferred_locale']);
                    if ($recipient) {
                        $language = app(LanguagePreferences::class)->resolve($recipient->preferred_locale, config('app.fallback_locale'));
                        $db->table('clubora.notifications')->insert(['tenant_id' => $club, 'account_id' => $recipient->id,
                            'title' => trans('support.notice_title', [], $language), 'body' => trans('support.notice_body', [], $language),
                            'object_type' => 'support', 'object_id' => $post]);
                    }
                }
                if (in_array($operation, ['create','escalate'], true)) {
                    TriageSupportTicket::enqueue($id, $club, $operation === 'create' ? 'club' : 'platform', $locale);
                }
                return $operation === 'escalate' ? $ticket->id : $id;
            });
            return $request->expectsJson() ? response()->json(['data' => ['id' => $id]], 201)
                : redirect()->to($request->url().($id ? '?ticket='.$id : ''));
        }
        $d = $request->validate(['ticket' => 'nullable|uuid', 'q' => 'nullable|string|max:120']);
        $articles = $this->access->articles($club, $staff, $platform, $locale)
            ->when(filled($d['q'] ?? null), fn ($q) => $q->whereLike('title', '%'.$d['q'].'%'))
            ->orderBy('title')->limit(40)->get(['id','title','body','video_url']);
        $faq = trans('support.faq');
        $rows = collect();
        $posts = collect();
        $updates = collect();
        $selected = null;
        if ($platform || $club !== null) {
            $rows = $tickets()->latest()->limit(50)->get(['id','subject','status','created_at']);
            if (isset($d['ticket'])) {
                $selected = $tickets()->where('id', $d['ticket'])->first($staff || $platform ? ['id','subject','status','assignee_id'] : ['id','subject','status']);
                abort_unless($selected, 404);
                $posts = $db->table('clubora.support_posts')->where('ticket_id', $selected->id)
                    ->when(!$staff && !$platform, fn ($q) => $q->where('internal', false))->orderBy('id')->limit(200)
                    ->get(['id','body','internal','created_at']);
                $files = $db->table('clubora.support_attachments')->whereIn('post_id', $posts->pluck('id'))
                    ->get(['id','post_id','filename','mime'])->groupBy('post_id');
                foreach ($posts as $post) $post->attachments = $files->get($post->id, collect());
                if ($staff && !$platform) {
                    $escalation = $db->table('clubora.support_tickets')->where('tenant_id', $club)->where('audience', 'platform')
                        ->where('source_id', $selected->id)->first(['id','status']);
                    if ($escalation) $updates = $db->table('clubora.support_posts')->where('ticket_id', $escalation->id)
                        ->where('internal', false)->orderBy('id')->limit(200)->get(['body','created_at']);
                }
            }
        }
        $data = compact('articles','rows','posts','selected','staff','platform','club','updates','faq');
        if ($staff || $platform) {
            $data['triage'] = $selected && config('clubunio-support.triage_enabled')
                ? $db->table('clubora.support_triage')->where('ticket_id', $selected->id)->first(['topic','draft','provider_generated']) : null;
            $data['assignees'] = $assignees()->orderBy('account.display_name')->get(['account.id','account.display_name']);
            $data['editableArticles'] = $editableArticles()->orderBy('title')->limit(100)->get();
        }
        return ($request->expectsJson() ? response()->json(['data' => $data]) : response()->view('clubunio.support.center', $data))
            ->header('Cache-Control', 'private, no-store');
    }
}

<?php

namespace App\ClubUnio\Http;

use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Services\{LanguagePreferences, SupportAccess};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        if ($request->isMethod('POST') && $request->input('operation') === 'ask') {
            $question = $request->validate(['body' => 'required|string|max:500'])['body'];
            $answer = app(\App\ClubUnio\Services\SupportGuide::class)->answer($question, $locale);
            return response()->json(['data' => $answer])->header('Cache-Control', 'private, no-store');
        }
        if ($request->isMethod('POST')) {
            abort_unless($platform || $club !== null, 403);
            $d = $request->validate(['operation' => 'required|in:create,reply,escalate,close,article',
                'ticket' => 'nullable|uuid', 'subject' => 'nullable|string|max:160', 'body' => 'nullable|string|max:10000',
                'internal' => 'nullable|boolean', 'audience' => 'nullable|in:public,club,club_staff,platform',
                'locale' => 'nullable|in:de,en']);
            $id = $db->transaction(function () use ($db, $tickets, $d, $club, $platform, $staff, $actor) {
                $body = trim($d['body'] ?? '');
                $title = trim($d['subject'] ?? '');
                $operation = $d['operation'];
                if ($operation === 'article') {
                    abort_unless($platform || $staff, 403);
                    abort_unless($title !== '' && $body !== '' && isset($d['locale'], $d['audience']), 422);
                    abort_unless(in_array($d['audience'], $platform ? ['public','platform'] : ['club','club_staff'], true), 403);
                    $db->table('clubora.support_articles')->insert(['tenant_id' => $club, 'title' => $title, 'body' => $body,
                        'audience' => $d['audience'], 'locale' => $d['locale'], 'published' => true]);
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
                $db->table('clubora.support_posts')->insert(['ticket_id' => $id, 'author_id' => $actor->id, 'body' => $body, 'internal' => $internal]);
                return $operation === 'escalate' ? $ticket->id : $id;
            });
            return $request->expectsJson() ? response()->json(['data' => ['id' => $id]], 201)
                : redirect()->to($request->url().($id ? '?ticket='.$id : ''));
        }
        $d = $request->validate(['ticket' => 'nullable|uuid', 'q' => 'nullable|string|max:120']);
        $articles = $this->access->articles($club, $staff, $platform, $locale)
            ->when(filled($d['q'] ?? null), fn ($q) => $q->whereLike('title', '%'.$d['q'].'%'))
            ->orderBy('title')->limit(40)->get(['id','title','body']);
        $faq = trans('support.faq');
        $rows = collect();
        $posts = collect();
        $updates = collect();
        $selected = null;
        if ($platform || $club !== null) {
            $rows = $tickets()->latest()->limit(50)->get(['id','subject','status','created_at']);
            if (isset($d['ticket'])) {
                $selected = $tickets()->where('id', $d['ticket'])->first(['id','subject','status']);
                abort_unless($selected, 404);
                $posts = $db->table('clubora.support_posts')->where('ticket_id', $selected->id)
                    ->when(!$staff && !$platform, fn ($q) => $q->where('internal', false))->orderBy('id')->limit(200)
                    ->get(['body','internal','created_at']);
                if ($staff && !$platform) {
                    $escalation = $db->table('clubora.support_tickets')->where('tenant_id', $club)->where('audience', 'platform')
                        ->where('source_id', $selected->id)->first(['id','status']);
                    if ($escalation) $updates = $db->table('clubora.support_posts')->where('ticket_id', $escalation->id)
                        ->where('internal', false)->orderBy('id')->limit(200)->get(['body','created_at']);
                }
            }
        }
        $data = compact('articles','rows','posts','selected','staff','platform','club','updates','faq');
        return ($request->expectsJson() ? response()->json(['data' => $data]) : response()->view('clubunio.support.center', $data))
            ->header('Cache-Control', 'private, no-store');
    }
}

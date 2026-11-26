<?php

namespace App\ClubUnio\Services;

use Carbon\CarbonImmutable;

/** Effective authority is always evaluated on this exact account/member link. */
final class ParentAccessPolicy
{
    public function scopePersonalLinks($query, \App\ClubUnio\Models\Tenant $tenant, string $linkAlias): void
    {
        $members = app(MemberAccessService::class)->activeMembers($tenant)->selectRaw('1')
            ->whereColumn('clubunio.members.id', $linkAlias.'.member_id')
            ->where(fn ($q) => $q->where($linkAlias.'.kind', 'self')->orWhere(fn ($q) => $this->scope($q, $linkAlias, 'clubunio.members')));
        $query->where($linkAlias.'.state', 'approved')->whereExists($members->toBase());
    }

    public function scope($query, string $linkAlias, string $memberAlias): void
    {
        foreach ([$linkAlias, $memberAlias] as $alias) {
            if (! preg_match('/\A[a-z_][a-z0-9_]*(?:\.[a-z_][a-z0-9_]*)?\z/D', $alias)) throw new \InvalidArgumentException('Untrusted parent access alias');
        }
        $today = now('Europe/Zurich')->toDateString();
        $base = $query instanceof \Illuminate\Database\Eloquent\Builder ? $query->getQuery() : $query;
        $birth = $base->getGrammar()->wrap($memberAlias.'.birth_date');
        if (! config('clubunio-parent-access.enabled', false)) {
            $query->where($linkAlias.'.kind', 'parent')->where($linkAlias.'.state', 'approved')
                ->where($memberAlias.'.birth_date', '<=', $today)
                ->whereRaw($birth." + interval '18 years' > CAST(? AS date)", [$today]);
            return;
        }
        $mother = $base->getGrammar()->wrap($memberAlias.'.mother_email');
        $father = $base->getGrammar()->wrap($memberAlias.'.father_email');
        $query->where($linkAlias.'.kind', 'parent')->where($linkAlias.'.state', 'approved')
            ->whereNotNull($memberAlias.'.birth_date')->where($memberAlias.'.birth_date', '<=', $today)
            ->where(function ($q) use ($linkAlias, $birth, $mother, $father, $today) {
                $q->where(function ($q) use ($linkAlias, $birth, $mother, $father, $today) {
                    $q->whereRaw($birth." + interval '18 years' > CAST(? AS date)", [$today])
                        ->where(fn ($q) => $q->where($linkAlias.'.source', '!=', 'verified_parent_login')
                            ->orWhereExists(function ($proof) use ($linkAlias, $mother, $father) {
                                $proof->selectRaw('1')->from('clubunio.account_parent_email_proofs as parent_proof')
                                    ->whereColumn('parent_proof.account_id', $linkAlias.'.account_id')->whereNotNull('parent_proof.verified_at')
                                    ->where(fn ($p) => $p->whereRaw('parent_proof.email = lower(trim('.$mother.'))')
                                        ->orWhereRaw('parent_proof.email = lower(trim('.$father.'))'));
                            }));
                })->orWhere(fn ($q) => $q->whereRaw($birth." + interval '18 years' <= CAST(? AS date)", [$today])->whereNotNull($linkAlias.'.adult_consent_at')
                        ->whereNotNull($linkAlias.'.adult_consent_by')->whereIn($linkAlias.'.adult_consent_source', ['member', 'office']));
            });
    }

    public function effective(object $link, object $member): bool
    {
        if (($link->kind ?? null) !== 'parent' || ($link->state ?? null) !== 'approved' || ! ($member->birth_date ?? null)) return false;
        try { $birth = CarbonImmutable::parse($member->birth_date instanceof \DateTimeInterface ? $member->birth_date->format('Y-m-d') : (string) $member->birth_date, 'Europe/Zurich')->startOfDay(); }
        catch (\Throwable) { return false; }
        $today = CarbonImmutable::now('Europe/Zurich')->startOfDay();
        if ($birth->gt($today)) return false;
        if (! config('clubunio-parent-access.enabled', false)) return $birth->addYearsNoOverflow(18)->gt($today);
        if ($birth->addYearsNoOverflow(18)->lte($today)) return ($link->adult_consent_at ?? null) !== null
            && ($link->adult_consent_by ?? null) !== null && in_array($link->adult_consent_source ?? null, ['member', 'office'], true);
        if (($link->source ?? null) !== 'verified_parent_login') return true;
        $emails = array_filter(array_map(fn ($v) => mb_strtolower(trim((string) $v)), [$member->mother_email ?? null, $member->father_email ?? null]));
        return $emails !== [] && \Illuminate\Support\Facades\DB::connection('clubunio')->table('clubunio.account_parent_email_proofs')
            ->where('account_id', $link->account_id)->whereNotNull('verified_at')->whereIn('email', $emails)->exists();
    }
}

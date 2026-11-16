<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable;

final class KeycloakMemberMatch
{
    /**
     * Produces candidates only. The caller must first establish ownership of
     * the address, and repeat the lookup at activation before granting access.
     */
    public function candidates(Tenant $tenant, string $email, string $firstName, string $lastName): ?array
    {
        $email = self::normalize($email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $today = now('Europe/Zurich');
        $rows = app(MemberAccessService::class)->activeMembers($tenant)
            ->where(fn ($query) => $query->whereRaw('lower(trim(email)) = ?', [$email])
                ->orWhereRaw('lower(trim(mother_email)) = ?', [$email])
                ->orWhereRaw('lower(trim(father_email)) = ?', [$email]))
            ->limit(51)->get(['id', 'first_name', 'last_name', 'email', 'mother_name',
                'mother_email', 'father_name', 'father_email', 'birth_date']);

        return $this->classify($rows, $email, $firstName, $lastName, $today->toDateString());
    }

    public function classify(iterable $rows, string $email, string $firstName, string $lastName, string $today): ?array
    {
        $email = self::normalize($email);
        $first = self::normalize($firstName);
        $last = self::normalize($lastName);
        if ($email === '' || $first === '' || $last === '') {
            return null;
        }
        $own = [];
        $children = [];
        $count = 0;
        foreach ($rows as $row) {
            if (++$count > 50) {
                return null;
            }
            if (self::normalize($row->email) === $email) {
                if (self::normalize($row->first_name) !== $first || self::normalize($row->last_name) !== $last) {
                    return null;
                }
                $own[] = (int) $row->id;
            }
            $birth = substr((string) $row->birth_date, 0, 10);
            if ($birth === '' || $birth > $today || CarbonImmutable::parse($birth)->addYearsNoOverflow(18)->toDateString() <= $today) {
                continue;
            }
            foreach (['mother', 'father'] as $parent) {
                if (self::normalize($row->{$parent.'_email'}) !== $email) {
                    continue;
                }
                if (! in_array(self::normalize($row->{$parent.'_name'}), ["$first $last", "$last $first"], true)) {
                    return null;
                }
                $children[(int) $row->id] = (int) $row->id;
            }
        }
        if (count($own) > 1 || array_intersect($own, $children) || (! $own && ! $children)) {
            return null;
        }
        sort($children);

        return ['member_id' => $own[0] ?? null, 'child_ids' => $children];
    }

    private static function normalize(?string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value ?? '')) ?? '');
    }
}

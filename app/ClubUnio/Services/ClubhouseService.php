<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ClubhouseService
{
    public function view(Account $actor, Tenant $tenant): void
    {
        abort_unless(app(ClubhouseAccess::class)->ready() && app(ClubhouseAccess::class)->member($actor, $tenant)
            && (app(MemberAccessService::class)->canEnter($actor, $tenant) || app(ClubhouseAccess::class)->allows($actor, $tenant, 'view')), 403);
    }

    public function overview(Tenant $tenant, string $channel, ?string $from = null): array
    {
        $hours = app(ClubhouseHours::class);
        $zone = $hours->timezone($tenant);
        $now = Time::now($zone);
        $date = $from ?? $now->toDateString();
        Validator::make(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->validate();
        $calendar = $hours->calendar($tenant, $date, Time::parse($date, $zone)->addDays(30)->toDateString());
        $openUntil = null;
        $nextOpening = null;
        $segments = [];
        foreach ($calendar as $day) {
            foreach ($day['intervals'] as $slot) {
                $a = Time::parse($slot['start']);
                $b = Time::parse($slot['end']);
                $last = count($segments) - 1;
                // Calendar days split at midnight; the live state spans adjacent days.
                if ($last >= 0 && $segments[$last][1] >= $a) {
                    $segments[$last][1] = $segments[$last][1]->max($b);
                } else {
                    $segments[] = [$a, $b];
                }
            }
        }
        foreach ($segments as [$a, $b]) {
            if ($a <= $now && $b > $now) {
                $openUntil = $b->toIso8601String();
            } elseif ($a > $now && $nextOpening === null) {
                $nextOpening = $a->toIso8601String();
            }
        }

        return ['timezone' => $zone, 'as_of' => $now->toIso8601String(), 'date' => $date,
            'open_now' => $openUntil !== null, 'open_until' => $openUntil, 'next_opening' => $nextOpening,
            'next_opening_search_until' => end($calendar)['date'], 'days' => $calendar,
            'menus' => app(ClubhouseMenus::class)->published($tenant, $channel)];
    }

    public function management(Account $actor, Tenant $tenant, array $filters = []): array
    {
        $access = app(ClubhouseAccess::class);
        $access->authorize($actor, $tenant, 'view');
        $hours = app(ClubhouseHours::class);
        $menus = app(ClubhouseMenus::class);
        $filter = Validator::make($filters, ['page' => 'sometimes|integer|min:1|max:10000',
            'status' => 'sometimes|in:all,draft,published,archived', 'search' => 'nullable|string|max:160'])->validate();
        $page = (int) ($filter['page'] ?? 1);
        $menuQuery = $menus->query($tenant)->when(($filter['status'] ?? 'all') !== 'all', fn ($q) => $q->where('status', $filter['status']));
        if (($search = trim($filter['search'] ?? '')) !== '') {
            $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
            $menuQuery->where(fn ($q) => $q->whereRaw("LOWER(title_de) LIKE ? ESCAPE '!'", [$needle])->orWhereRaw("LOWER(title_en) LIKE ? ESCAPE '!'", [$needle]));
        }
        $total = (clone $menuQuery)->count();
        $rules = $hours->rules($tenant)->orderByDesc('active')->orderBy('starts_on')->orderBy('id')->limit(200)->get()->map(function ($row) use ($hours) {
            $row->weekdays = $hours->decode($row->weekdays);
            $row->intervals = $hours->decode($row->intervals);
            $row->active = (bool) $row->active;

            return (array) $row;
        })->all();
        $exceptions = $hours->exceptions($tenant)->where('date', '>=', now($hours->timezone($tenant))->subDay()->toDateString())
            ->orderBy('date')->limit(200)->get()->map(function ($row) use ($hours) {
                $row->intervals = $hours->decode($row->intervals);
                $row->closed = (bool) $row->closed;

                return (array) $row;
            })->all();
        $settings = DB::connection('clubunio')->table('clubora.clubhouse_settings')->where('tenant_id', $tenant->id)->first();
        $statuses = DB::connection('clubunio')->table('clubora.clubhouse_menu_statuses')->where('tenant_id', $tenant->id)
            ->orderBy('sort_order')->get()->map(fn ($row) => ['code' => $row->code, 'label' => trans($row->label_key), 'color' => $row->color])->all();

        return ['capabilities' => $access->capabilities($actor, $tenant), 'rules' => $rules, 'exceptions' => $exceptions,
            'menus' => $menuQuery->orderByDesc('id')->offset(($page - 1) * 25)->limit(25)->get()->map(fn ($row) => $menus->row($row))->all(),
            'pagination' => ['page' => $page, 'per_page' => 25, 'total' => $total, 'has_more' => $page * 25 < $total],
            'statuses' => $statuses, 'settings' => ['public_enabled' => (bool) ($settings->public_enabled ?? false),
                'timezone' => $settings->timezone ?? 'Europe/Zurich', 'revision' => (int) ($settings->revision ?? 0)]];
    }

    public function settings(Account $actor, Tenant $tenant, array $input): void
    {
        $access = app(ClubhouseAccess::class);
        abort_unless($access->ready() && $access->administrator($actor, $tenant), 403);
        $data = Validator::make($input, ['public_enabled' => 'required|boolean',
            'timezone' => ['required', Rule::in(timezone_identifiers_list())], 'revision' => 'required|integer|min:0'])->validate();
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $data, $access) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_unless($access->administrator($actor, $tenant), 403);
            $table = DB::connection('clubunio')->table('clubora.clubhouse_settings')->where('tenant_id', $tenant->id);
            $old = (clone $table)->lockForUpdate()->first();
            if ((int) ($old->revision ?? 0) !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => trans('clubhouse.conflict')]);
            }
            $table->updateOrInsert(['tenant_id' => $tenant->id], ['public_enabled' => (bool) $data['public_enabled'],
                'timezone' => $data['timezone'], 'revision' => $data['revision'] + 1]);
            $access->audit($actor, $tenant, 'settings', null, 'saved', $data);
        });
    }

    public function publicEnabled(Tenant $tenant): bool
    {
        return $tenant->fresh() !== null && app(ClubhouseAccess::class)->ready()
            && (bool) DB::connection('clubunio')->table('clubora.clubhouse_settings')->where('tenant_id', $tenant->id)->value('public_enabled');
    }
}

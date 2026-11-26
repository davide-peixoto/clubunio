<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Sabre\VObject\Recur\RRuleIterator;

final class ClubhouseHours
{
    public function rules(Tenant $tenant)
    {
        return DB::connection('clubunio')->table('clubunio.clubhouse_opening_rules')->where('tenant_id', $tenant->id);
    }

    public function exceptions(Tenant $tenant)
    {
        return DB::connection('clubunio')->table('clubunio.clubhouse_opening_exceptions')->where('tenant_id', $tenant->id);
    }

    public function timezone(Tenant $tenant): string
    {
        $zone = DB::connection('clubunio')->table('clubunio.clubhouse_settings')->where('tenant_id', $tenant->id)->value('timezone') ?? 'Europe/Zurich';

        return in_array($zone, timezone_identifiers_list(), true) ? $zone : 'Europe/Zurich';
    }

    public function intervals(array $intervals, bool $allowEmpty = false): array
    {
        Validator::make(['intervals' => $intervals], ['intervals' => ['array', 'max:8', $allowEmpty ? 'present' : 'min:1'],
            'intervals.*.start' => 'required|date_format:H:i', 'intervals.*.end' => 'required|date_format:H:i',
            'intervals.*.overnight' => 'required|boolean'])->validate();
        $normalized = [];
        foreach ($intervals as $slot) {
            $start = substr($slot['start'], 0, 5);
            $end = substr($slot['end'], 0, 5);
            $overnight = (bool) $slot['overnight'];
            // A next-day closing time must still describe at most one day.
            if ((! $overnight && $end <= $start) || ($overnight && $end > $start)) {
                throw ValidationException::withMessages(['intervals' => trans('clubhouse.invalid_interval')]);
            }
            $normalized[] = ['start' => $start, 'end' => $end, 'overnight' => $overnight];
        }
        usort($normalized, fn ($a, $b) => $a['start'] <=> $b['start']);
        $end = -1;
        foreach ($normalized as $slot) {
            $start = (int) substr($slot['start'], 0, 2) * 60 + (int) substr($slot['start'], 3);
            $finish = (int) substr($slot['end'], 0, 2) * 60 + (int) substr($slot['end'], 3) + ($slot['overnight'] ? 1440 : 0);
            if ($start < $end) {
                throw ValidationException::withMessages(['intervals' => trans('clubhouse.overlap')]);
            }
            $end = $finish;
        }

        return $normalized;
    }

    public function save(Account $actor, Tenant $tenant, ?int $id, array $input): int
    {
        app(ClubhouseAccess::class)->authorize($actor, $tenant, 'hours.write');
        $data = Validator::make($input, ['title' => 'required|string|max:160',
            'starts_on' => 'required|date_format:Y-m-d|after_or_equal:2000-01-01|before_or_equal:2100-12-31',
            'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on|before_or_equal:2100-12-31',
            'weekdays' => 'present|array|max:7', 'weekdays.*' => ['integer', 'distinct', Rule::in(range(1, 7))],
            'intervals' => 'present|array|max:8', 'active' => 'required|boolean',
            'revision' => $id ? 'required|integer|min:1' : 'prohibited',
            'scope' => 'sometimes|in:all,this,future', 'on' => 'nullable|date_format:Y-m-d'])->validate();
        $data['intervals'] = $this->intervals($data['intervals']);
        $data['weekdays'] = array_map('intval', $data['weekdays']);
        if ($data['weekdays'] === []) {
            $data['ends_on'] = $data['starts_on'];
        }

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ClubhouseAccess::class)->authorize($actor, $tenant, 'hours.write');
            $old = $id ? $this->rules($tenant)->where('id', $id)->lockForUpdate()->firstOrFail() : null;
            if ($old && (int) $old->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => trans('clubhouse.conflict')]);
            }
            $scope = $data['scope'] ?? 'all';
            if ($scope !== 'all') {
                if (! $old || ! ($data['on'] ?? null) || ! $this->occurs($old, $data['on'])) {
                    throw ValidationException::withMessages(['on' => trans('clubhouse.invalid_occurrence')]);
                }
                if ($scope === 'this') {
                    $this->exceptions($tenant)->updateOrInsert(['rule_id' => $id, 'date' => $data['on']],
                        ['tenant_id' => $tenant->id, 'closed' => ! $data['active'],
                            'intervals' => json_encode($data['intervals'], JSON_THROW_ON_ERROR), 'note' => $data['title'],
                            'revision' => (int) $old->revision + 1]);
                    $this->rules($tenant)->where('id', $id)->update(['revision' => $old->revision + 1, 'updated_at' => now()]);
                    app(ClubhouseAccess::class)->audit($actor, $tenant, 'opening', $id, 'exception', ['date' => $data['on']]);

                    return $id;
                }
                if ($data['on'] === $old->starts_on) {
                    $scope = 'all';
                } else {
                    if (($data['ends_on'] ?? $old->ends_on) !== null && ($data['ends_on'] ?? $old->ends_on) < $data['on']) {
                        throw ValidationException::withMessages(['ends_on' => trans('clubhouse.invalid_occurrence')]);
                    }
                    $this->rules($tenant)->where('id', $id)->update(['ends_on' => Time::parse($data['on'])->subDay()->toDateString(),
                        'revision' => $old->revision + 1, 'updated_at' => now()]);
                    $data['starts_on'] = $data['on'];
                    $data['ends_on'] = $data['ends_on'] ?? $old->ends_on;
                }
            }
            $values = collect($data)->only(['title', 'starts_on', 'ends_on', 'active'])->all()
                + ['weekdays' => json_encode($data['weekdays'], JSON_THROW_ON_ERROR),
                    'intervals' => json_encode($data['intervals'], JSON_THROW_ON_ERROR),
                    'revision' => $scope === 'all' && $old ? $old->revision + 1 : 1, 'updated_at' => now()];
            if ($old && $scope === 'all') {
                $this->rules($tenant)->where('id', $id)->update($values);
                $result = $id;
            } else {
                $result = $this->rules($tenant)->insertGetId($values + ['tenant_id' => $tenant->id, 'created_at' => now()]);
                if ($old) {
                    $this->exceptions($tenant)->where('rule_id', $id)->where('date', '>=', $data['on'])->update(['rule_id' => $result]);
                }
            }
            app(ClubhouseAccess::class)->audit($actor, $tenant, 'opening', $result, $scope, ['previous_id' => $id, 'values' => $values]);

            return (int) $result;
        });
    }

    public function saveException(Account $actor, Tenant $tenant, array $input): int
    {
        app(ClubhouseAccess::class)->authorize($actor, $tenant, 'hours.write');
        $data = Validator::make($input, ['date' => 'required|date_format:Y-m-d|after_or_equal:2000-01-01|before_or_equal:2100-12-31',
            'closed' => 'required|boolean', 'intervals' => 'present|array|max:8', 'note' => 'nullable|string|max:500',
            'revision' => 'required|integer|min:0'])->validate();
        $data['intervals'] = $this->intervals($data['intervals'], (bool) $data['closed']);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ClubhouseAccess::class)->authorize($actor, $tenant, 'hours.write');
            $query = $this->exceptions($tenant)->whereNull('rule_id')->where('date', $data['date']);
            $old = (clone $query)->lockForUpdate()->first();
            if ((int) ($old->revision ?? 0) !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => trans('clubhouse.conflict')]);
            }
            $values = collect($data)->only(['date', 'closed', 'note'])->all()
                + ['intervals' => json_encode($data['intervals'], JSON_THROW_ON_ERROR), 'revision' => $data['revision'] + 1];
            if ($old) {
                $query->update($values);
                $id = $old->id;
            } else {
                $id = $query->insertGetId($values + ['tenant_id' => $tenant->id, 'rule_id' => null]);
            }
            app(ClubhouseAccess::class)->audit($actor, $tenant, 'exception', $id, 'saved', $values);

            return (int) $id;
        });
    }

    public function removeException(Account $actor, Tenant $tenant, int $id, int $revision): void
    {
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ClubhouseAccess::class)->authorize($actor, $tenant, 'hours.write');
            $row = $this->exceptions($tenant)->where('id', $id)->lockForUpdate()->firstOrFail();
            if ((int) $row->revision !== $revision) {
                throw ValidationException::withMessages(['revision' => trans('clubhouse.conflict')]);
            }
            $this->exceptions($tenant)->where('id', $id)->delete();
            app(ClubhouseAccess::class)->audit($actor, $tenant, 'exception', $id, 'removed', (array) $row);
        });
    }

    public function occurs(object $rule, string $date): bool
    {
        $weekdays = $this->decode($rule->weekdays);

        return $date >= $rule->starts_on && (! $rule->ends_on || $date <= $rule->ends_on)
            && ($weekdays === [] ? $date === $rule->starts_on : in_array(Time::parse($date)->dayOfWeekIso, $weekdays, true));
    }

    public function decode(mixed $value): array
    {
        return is_array($value) ? $value : json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
    }

    public function calendar(Tenant $tenant, string $from, string $to): array
    {
        Validator::make(compact('from', 'to'), ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        $zone = $this->timezone($tenant);
        $start = Time::parse($from, $zone)->startOfDay();
        $end = Time::parse($to, $zone)->startOfDay();
        abort_if($start->diffInDays($end) > 90, 422);
        $originStart = $start->subDay();
        $rules = $this->rules($tenant)->where('active', true)->where('starts_on', '<=', $to)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $originStart->toDateString()))->limit(501)->get();
        abort_if($rules->count() > 500, 422);
        $exceptions = $this->exceptions($tenant)->whereBetween('date', [$originStart->toDateString(), $to])->get();
        $overrides = $exceptions->whereNull('rule_id')->keyBy('date');
        $specific = $exceptions->whereNotNull('rule_id')->keyBy(fn ($x) => $x->rule_id.':'.$x->date);
        $segments = [];
        $add = function (string $date, array $slots, ?int $ruleId) use (&$segments, $zone) {
            foreach ($slots as $slot) {
                $a = Time::parse($date.' '.$slot['start'], $zone);
                $b = Time::parse($date.' '.$slot['end'], $zone)->addDays($slot['overnight'] ? 1 : 0);
                $segments[] = ['start' => $a, 'end' => $b, 'origin' => $date, 'rule_id' => $ruleId];
            }
        };
        foreach ($rules as $rule) {
            $first = max($rule->starts_on, $originStart->toDateString());
            $last = min($to, $rule->ends_on ?? $to);
            $weekdays = $this->decode($rule->weekdays);
            if ($weekdays === []) {
                $dates = $rule->starts_on >= $originStart->toDateString() && $rule->starts_on <= $to ? [$rule->starts_on] : [];
            } else {
                $anchor = Time::parse($first, $zone);
                for ($i = 0; $i < 7 && ! in_array($anchor->dayOfWeekIso, $weekdays, true); $i++) {
                    $anchor = $anchor->addDay();
                }
                $days = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];
                $iterator = new RRuleIterator('FREQ=WEEKLY;BYDAY='.implode(',', array_map(fn ($day) => $days[$day], $weekdays)), $anchor);
                $dates = [];
                while ($iterator->valid() && ($date = $iterator->current()->format('Y-m-d')) <= $last && count($dates) < 92) {
                    $dates[] = $date;
                    $iterator->next();
                }
            }
            foreach ($dates as $date) {
                if ($overrides->has($date)) {
                    continue;
                }
                $override = $specific->get($rule->id.':'.$date);
                if ($override && $override->closed) {
                    continue;
                }
                $add($date, $this->decode($override?->intervals ?? $rule->intervals), (int) $rule->id);
            }
        }
        foreach ($overrides as $override) {
            if (! $override->closed) {
                $add($override->date, $this->decode($override->intervals), null);
            }
        }
        $result = [];
        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            $date = $day->toDateString();
            $limit = $day->addDay();
            $override = $overrides->get($date);
            $slots = [];
            foreach ($segments as $segment) {
                if ($segment['start'] >= $limit || $segment['end'] <= $day || ($override && ($override->closed || $segment['origin'] !== $date))) {
                    continue;
                }
                $slots[] = [max($segment['start'], $day), min($segment['end'], $limit)];
            }
            usort($slots, fn ($a, $b) => $a[0] <=> $b[0]);
            $merged = [];
            foreach ($slots as [$a, $b]) {
                $last = count($merged) - 1;
                if ($last >= 0 && $a <= $merged[$last][1]) {
                    $merged[$last][1] = max($b, $merged[$last][1]);
                } else {
                    $merged[] = [$a, $b];
                }
            }
            $result[] = ['date' => $date, 'exception' => $override !== null,
                'intervals' => array_map(fn ($slot) => ['start' => $slot[0]->toIso8601String(), 'end' => $slot[1]->toIso8601String()], $merged)];
        }

        return $result;
    }
}

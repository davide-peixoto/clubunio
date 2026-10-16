<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Competition;
use App\ClubUnio\Models\FieldAvailability;
use App\ClubUnio\Models\FootballClub;
use App\ClubUnio\Models\SignageNotice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Models\TrainingOption;
use App\ClubUnio\Models\TrainingSlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SignageService
{
    public function preview(Account $actor, Tenant $tenant, array $input): array
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'signage', 'view');

        return $this->display($tenant, $input);
    }

    public function publicDisplay(Tenant $tenant, array $input): array
    {
        app(PublicWebService::class)->authorize($tenant, 'signage');

        return $this->display($tenant, ['date' => now('Europe/Zurich')->toDateString(), 'period_id' => $input['period_id'] ?? null]);
    }

    private function display(Tenant $tenant, array $input): array
    {
        $data = Validator::make($input, [
            'date' => 'required|date_format:Y-m-d',
            'period_id' => ['nullable', 'integer', Rule::exists('clubunio.clubora.training_options', 'id')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->where('active', true)->where('category', 'period'))],
        ])->validate();
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $data['date'], 'Europe/Zurich');
        $fields = FieldAvailability::where('tenant_id', $tenant->id)->where('active', true)->whereHas('field', fn ($q) => $q->where('active', true))->with(['field', 'status'])->get();
        // Unknown/inactive status is not interpreted as an open field.
        $blocked = fn ($code) => ! $fields->contains(fn ($f) => $f->field->code === $code && $f->status?->active && ! $f->status->blocks_training);
        $flag = $blocked('H') ? ($blocked('T') ? 'synthetic_only' : 'without_main') : 'all_fields';
        $trainings = collect();
        if (! empty($data['period_id'])) {
            $trainings = TrainingSlot::where('tenant_id', $tenant->id)->where('active', true)->where('period_id', $data['period_id'])
                ->where($flag, true)->whereHas('weekday', fn ($q) => $q->where('active', true)->where('code', (string) ($date->dayOfWeek + 1)))
                ->whereHas('teamSeason', fn ($q) => $q->where('active', true)->whereHas('team', fn ($q) => $q->where('active', true))->whereHas('season', fn ($q) => $q->where('active', true)->where('starts_on', '<=', $data['date'])->where('ends_on', '>=', $data['date'])))
                ->with('teamSeason.team')->orderBy('starts_at')->orderBy('id')->get();
        }

        $competitions = Competition::where('tenant_id', $tenant->id)->where('active', true)->where('show_on_signage', true)->where('plays_on', $data['date'])->orderBy('starts_at')->orderBy('id')->get();
        $this->loadDisplayOptions($tenant, $fields, $competitions, $trainings);
        $numbers = $competitions->pluck('host_club_number')->merge($competitions->pluck('guest_club_number'))->filter()->unique();
        $clubs = FootballClub::where('tenant_id', $tenant->id)->whereIn('club_number', $numbers)->get()->keyBy('club_number');

        return [
            'competitions' => $competitions, 'clubs' => $clubs,
            'notices' => SignageNotice::where('tenant_id', $tenant->id)->where('active', true)->where('shows_on', $data['date'])->orderBy('id')->get(),
            'fields' => $fields, 'trainings' => $trainings, 'training_variant' => $flag,
        ];
    }

    private function loadDisplayOptions(Tenant $tenant, Collection $fields, Collection $competitions, Collection $trainings): void
    {
        $options = $fields->pluck('field')->filter()->keyBy('id');
        $groups = [[$competitions, ['field' => 'field_id', 'hostRoom' => 'host_room_id', 'guestRoom' => 'guest_room_id', 'hostSide' => 'host_side_id', 'guestSide' => 'guest_side_id']],
            [$trainings, ['field' => 'field_id', 'room' => 'room_id', 'side' => 'side_id']]];
        $ids = collect();
        foreach ($groups as [$rows, $relations]) {
            foreach ($relations as $foreignKey) {
                $ids = $ids->merge($rows->pluck($foreignKey));
            }
        }
        $missing = $ids->filter()->unique()->diff($options->keys());
        if ($missing->isNotEmpty()) {
            $options = $options->union(TrainingOption::where('tenant_id', $tenant->id)->whereIn('id', $missing)->get()->keyBy('id'));
        }
        // One request-local lookup pool; no cross-request cache or permission snapshot.
        foreach ($groups as [$rows, $relations]) {
            foreach ($rows as $row) {
                foreach ($relations as $relation => $foreignKey) {
                    $row->setRelation($relation, $options->get($row->$foreignKey));
                }
            }
        }
    }

    public static function roomLabel(?string $name): string
    {
        if (! $name || preg_match('/^(keine|ohne)\b/iu', trim($name))) {
            return '–';
        }

        return preg_replace('/^Garderobe\s+/iu', '', $name);
    }
}

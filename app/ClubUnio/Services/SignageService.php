<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Competition;
use App\ClubUnio\Models\FieldAvailability;
use App\ClubUnio\Models\FootballClub;
use App\ClubUnio\Models\SignageNotice;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Models\TrainingSlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SignageService
{
    public function preview(Account $actor, Tenant $tenant, array $input): array
    {
        abort_unless($actor->canAccessTenant($tenant), 403);
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
                ->with(['teamSeason', 'field', 'room', 'side'])->orderBy('starts_at')->orderBy('id')->get();
        }

        $competitions = Competition::where('tenant_id', $tenant->id)->where('active', true)->where('show_on_signage', true)->where('plays_on', $data['date'])->with(['field', 'hostRoom', 'guestRoom', 'hostSide', 'guestSide'])->orderBy('starts_at')->orderBy('id')->get();
        $numbers = $competitions->pluck('host_club_number')->merge($competitions->pluck('guest_club_number'))->filter()->unique();
        $clubs = FootballClub::where('tenant_id', $tenant->id)->whereIn('club_number', $numbers)->get()->keyBy('club_number');

        return [
            'competitions' => $competitions, 'clubs' => $clubs,
            'notices' => SignageNotice::where('tenant_id', $tenant->id)->where('active', true)->where('shows_on', $data['date'])->orderBy('id')->get(),
            'fields' => $fields, 'trainings' => $trainings, 'training_variant' => $flag,
        ];
    }
}

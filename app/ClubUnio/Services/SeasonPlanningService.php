<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ContributionPrice;
use App\ClubUnio\Models\ContributionRule;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\Team;
use App\ClubUnio\Models\TeamPlayer;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\TeamStaff;
use App\ClubUnio\Models\TechnicalLead;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Models\TrainingSlot;
use Carbon\CarbonImmutable as Time;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SeasonPlanningService
{
    public const KINDS = ['player' => TeamPlayer::class, 'staff' => TeamStaff::class, 'lead' => TechnicalLead::class];

    public function table(Tenant $tenant)
    {
        return DB::connection('clubunio')->table('clubora.season_plans')->where('tenant_id', $tenant->id);
    }

    public function authorize(Account $actor, Tenant $tenant, bool $edit = false): void
    {
        $permissions = app(ModulePermissionService::class);
        foreach (['seasons', 'teams', 'members', 'contributions', 'training_slots'] as $module) {
            $permissions->authorize($actor, $tenant, $module, 'view');
        }
        if ($edit) {
            $permissions->authorize($actor, $tenant, 'seasons', 'create');
        }
    }

    public function get(Account $actor, Tenant $tenant, int $id): object
    {
        $this->authorize($actor, $tenant);
        $row = $this->table($tenant)->where('id', $id)->first();
        abort_unless($row, 404);
        $row->document = json_decode(Crypt::decryptString($row->document_encrypted), true, 128, JSON_THROW_ON_ERROR);
        unset($row->document_encrypted);

        return $row;
    }

    public function facts(Account $actor, Tenant $tenant): array
    {
        $this->authorize($actor, $tenant);
        $facts = [];
        foreach (['teams' => ['id', 'name', 'laundry_allowance'], 'genders' => ['id', 'name'],
            'staff_functions' => ['id', 'name'], 'training_options' => ['id', 'name', 'category'],
            'seasons' => ['id', 'name', 'starts_on', 'ends_on']] as $table => $columns) {
            $facts[$table] = DB::connection('clubunio')->table('clubora.'.$table)->where('tenant_id', $tenant->id)
                ->where('active', true)->orderBy('id')->get($columns)->keyBy('id')->all();
        }
        $facts['members'] = app(MemberAccessService::class)->activeMembers($tenant)->orderBy('id')
            ->get(['id', 'first_name', 'last_name', 'birth_date', 'gender_id'])->keyBy('id')->all();

        return $facts;
    }

    public function seed(Account $actor, Tenant $tenant, ?int $source): array
    {
        $facts = $this->facts($actor, $tenant);
        $season = $source ? ($facts['seasons'][$source] ?? abort(404)) : null;
        preg_match('/\b(20\d{2})\b/', $season?->name ?? '', $match);
        $start = $season?->starts_on ? Time::parse($season->starts_on)->addYear()
            : Time::create((int) ($match[1] ?? now('Europe/Zurich')->year) + 1, 7, 1);
        $doc = ['progress' => 1, 'season' => ['name' => $start->year.'/'.($start->year + 1), 'starts_on' => $start->toDateString(), 'ends_on' => $start->addYear()->subDay()->toDateString()],
            'teams' => [], 'prices' => [], 'rules' => [], 'assignments' => [], 'slots' => []];
        if (! $source) {
            return $doc;
        }
        $db = DB::connection('clubunio');
        $sourceRules = $db->table('clubora.contribution_rules')->where('tenant_id', $tenant->id)->where('season_id', $source)->where('active', true)->orderBy('id')->get();
        $sourcePrices = $db->table('clubora.contribution_prices')->where('tenant_id', $tenant->id)->where('active', true)
            ->whereIn('id', $sourceRules->pluck('price_id')->unique())->get()->keyBy('id');
        $sourceTeams = $db->table('clubora.team_seasons')->where('tenant_id', $tenant->id)->where('season_id', $source)->where('active', true)->orderBy('id')->get();
        $sourceAssignments = [];
        foreach (self::KINDS as $kind => $model) {
            $sourceAssignments[$kind] = $model::where('tenant_id', $tenant->id)->whereIn('team_season_id', $sourceTeams->pluck('id'))
                ->where('active', true)->orderBy('id')->get()->groupBy('team_season_id');
        }
        $sourceSlots = TrainingSlot::where('tenant_id', $tenant->id)->whereIn('team_season_id', $sourceTeams->pluck('id'))
            ->where('active', true)->orderBy('id')->get()->groupBy('team_season_id');
        $prices = [];
        foreach ($sourceRules as $rule) {
            $price = $sourcePrices->get($rule->price_id);
            if (! $price) {
                continue;
            }
            if (! isset($prices[$price->id])) {
                $prices[$price->id] = (string) Str::uuid();
                $doc['prices'][] = ['key' => $prices[$price->id], 'name' => mb_substr($price->name.' '.$doc['season']['name'], 0, 160), 'amount' => $price->amount,
                    'bexio_product_id' => $price->bexio_product_id, 'bexio_article_id' => $price->bexio_article_id];
            }
            $doc['rules'][] = ['key' => (string) Str::uuid(), 'price_key' => $prices[$price->id], 'gender_id' => $rule->gender_id,
                'year_from' => $rule->year_from + 1, 'year_to' => $rule->year_to + 1];
        }
        $slots = ['period_id', 'weekday_id', 'starts_at', 'ends_at', 'field_id', 'room_id', 'side_id', 'hall', 'all_fields', 'without_main', 'synthetic_only', 'website_visible'];
        foreach ($sourceTeams as $ts) {
            if (! isset($facts['teams'][$ts->team_id])) {
                continue;
            }
            $key = (string) Str::uuid();
            $team = $facts['teams'][$ts->team_id];
            $planned = ['key' => $key, 'team_id' => $team->id, 'name' => $team->name, 'laundry_allowance' => $team->laundry_allowance,
                'rule_key' => null, 'year_from' => null, 'year_to' => null, 'gender_id' => null, 'capacity' => null];
            $births = [];
            foreach (self::KINDS as $kind => $model) {
                foreach ($sourceAssignments[$kind]->get($ts->id, collect()) as $assignment) {
                    $doc['assignments'][] = ['key' => (string) Str::uuid(), 'kind' => $kind, 'team_key' => $key,
                        'member_id' => $assignment->member_id, 'function_id' => $kind === 'staff' ? $assignment->staff_function_id : null];
                    if ($kind === 'player' && isset($facts['members'][$assignment->member_id])) {
                        $births[] = $facts['members'][$assignment->member_id];
                    }
                }
            }
            $scores = [];
            foreach ($doc['rules'] as $rule) {
                // Match last season's cohort against the unshifted range; the new range advances one year.
                $scores[$rule['key']] = count(array_filter($births, fn ($m) => $m->birth_date && $m->gender_id == $rule['gender_id']
                    && (int) substr($m->birth_date, 0, 4) >= $rule['year_from'] - 1 && (int) substr($m->birth_date, 0, 4) <= $rule['year_to'] - 1));
            }
            if ($scores && max($scores) > 0 && count(array_filter($scores, fn ($n) => $n === max($scores))) === 1) {
                $planned['rule_key'] = array_search(max($scores), $scores, true);
            }
            $doc['teams'][] = $planned;
            foreach ($sourceSlots->get($ts->id, collect()) as $slot) {
                $doc['slots'][] = ['key' => (string) Str::uuid(), 'team_key' => $key] + $slot->only($slots);
            }
        }

        return $doc;
    }

    public function save(Account $actor, Tenant $tenant, ?int $id, ?int $revision, array $doc, ?int $source = null): object
    {
        $this->authorize($actor, $tenant, true);
        $doc = $this->shape($doc);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $doc, $source) {
            $this->authorize($actor, $tenant, true);
            $old = $id ? $this->table($tenant)->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && ! $old, 404);
            $this->authorize($actor, $tenant, true);
            if ($old && ($old->state !== 'draft' || $old->revision !== $revision)) {
                throw ValidationException::withMessages(['plan' => 'Der Entwurf wurde geändert oder abgeschlossen. Bitte neu öffnen; deine Änderungen wurden nicht überschrieben.']);
            }
            if ($old && json_decode(Crypt::decryptString($old->document_encrypted), true, 128, JSON_THROW_ON_ERROR) === $doc) {
                return $this->get($actor, $tenant, $id);
            }
            if (! $old && $source !== null) {
                abort_unless(DB::connection('clubunio')->table('clubora.seasons')->where('tenant_id', $tenant->id)->where('id', $source)->exists(), 404);
            }
            $data = ['name' => ($doc['season']['name'] ?? null) ?: 'Neue Saison', 'document_encrypted' => Crypt::encryptString(json_encode($doc, JSON_THROW_ON_ERROR)),
                'revision' => ($old->revision ?? 0) + 1, 'updated_at' => now()];
            if ($old) {
                $this->table($tenant)->where('id', $id)->update($data);
            } else {
                $id = $this->table($tenant)->insertGetId($data + ['tenant_id' => $tenant->id, 'created_by' => $actor->id, 'source_season_id' => $source]);
            }
            $this->audit($actor, $tenant, $id, $old?->revision ?? 0, $data['revision'], 'saved');

            return $this->get($actor, $tenant, $id);
        });
    }

    public function shape(array $doc): array
    {
        $rules = ['progress' => 'sometimes|integer|min:1|max:7', 'season' => 'required|array:name,starts_on,ends_on', 'season.name' => 'nullable|string|max:100',
            'season.starts_on' => 'nullable|date_format:Y-m-d', 'season.ends_on' => 'nullable|date_format:Y-m-d',
            'teams' => 'present|array|max:100', 'prices' => 'present|array|max:100', 'rules' => 'present|array|max:200',
            'assignments' => 'present|array|max:2000', 'slots' => 'present|array|max:500'];
        $columns = [
            'teams' => ['team_id' => 'nullable|integer', 'name' => 'nullable|string|max:160', 'laundry_allowance' => 'nullable|numeric|min:0|max:9999999999.99|decimal:0,2',
                'rule_key' => 'nullable|uuid', 'year_from' => 'nullable|integer|min:1800|max:2200', 'year_to' => 'nullable|integer|min:1800|max:2200',
                'gender_id' => 'nullable|integer', 'capacity' => 'nullable|integer|min:1|max:200'],
            'prices' => ['name' => 'nullable|string|max:160', 'amount' => 'nullable|numeric|min:0|max:999999999.99|decimal:0,4',
                'bexio_product_id' => 'nullable|string|max:100', 'bexio_article_id' => 'nullable|integer|min:1'],
            'rules' => ['price_key' => 'nullable|uuid', 'gender_id' => 'nullable|integer', 'year_from' => 'nullable|integer|min:1800|max:2200', 'year_to' => 'nullable|integer|min:1800|max:2200'],
            'assignments' => ['kind' => 'required|in:player,staff,lead', 'team_key' => 'required|uuid', 'member_id' => 'required|integer|min:1', 'function_id' => 'nullable|integer'],
            'slots' => ['team_key' => 'nullable|uuid', 'starts_at' => 'nullable|date_format:H:i,H:i:s', 'ends_at' => 'nullable|date_format:H:i,H:i:s', 'hall' => 'nullable|string|max:255'],
        ];
        foreach (['period', 'weekday', 'field', 'room', 'side'] as $kind) {
            $columns['slots'][$kind.'_id'] = 'nullable|integer';
        }
        foreach (['all_fields', 'without_main', 'synthetic_only', 'website_visible'] as $field) {
            $columns['slots'][$field] = 'required|boolean';
        }
        foreach ($columns as $collection => $fields) {
            $rules[$collection.'.*'] = 'array:key,'.implode(',', array_keys($fields));
            $rules[$collection.'.*.key'] = 'required|uuid|distinct';
            foreach ($fields as $field => $rule) {
                $rules[$collection.'.*.'.$field] = $rule;
            }
        }
        abort_if(array_diff(array_keys($doc), ['progress', 'season', ...array_keys($columns)]), 422);
        $result = Validator::make($doc, $rules)->validate();
        $keys = [];
        $result['season'] += ['name' => null, 'starts_on' => null, 'ends_on' => null];
        $result['progress'] = (int) ($result['progress'] ?? 1);
        foreach ($columns as $collection => $fields) {
            $result[$collection] = array_values($result[$collection]);
            foreach ($result[$collection] as &$row) {
                $row += array_fill_keys(array_keys($fields), null);
                foreach ($fields as $field => $_) {
                    if (($row[$field] ?? null) === '') {
                        $row[$field] = null;
                    }
                }
                if ($collection === 'slots') {
                    foreach (['starts_at', 'ends_at'] as $time) {
                        if (strlen($row[$time] ?? '') === 5) {
                            $row[$time] .= ':00';
                        }
                    }
                }
                if (isset($keys[$row['key']])) {
                    throw ValidationException::withMessages(['plan' => 'Entwurfskennungen müssen eindeutig sein.']);
                }
                $keys[$row['key']] = true;
            }
            unset($row);
        }

        return $result;
    }

    public function range(array $team, array $doc): array
    {
        return collect($doc['rules'])->firstWhere('key', $team['rule_key'] ?? null) ?? $team;
    }

    public function matches(object $member, array $team, array $doc): bool
    {
        $range = $this->range($team, $doc);

        return $member->birth_date && ! empty($range['year_from']) && ! empty($range['year_to'])
            && (int) substr($member->birth_date, 0, 4) >= $range['year_from'] && (int) substr($member->birth_date, 0, 4) <= $range['year_to']
            && (empty($range['gender_id']) || $range['gender_id'] == $member->gender_id);
    }

    public function review(Account $actor, Tenant $tenant, int $id): array
    {
        $row = $this->get($actor, $tenant, $id);
        $facts = $this->facts($actor, $tenant);
        $doc = $this->shape($row->document);
        $errors = [];
        $warnings = [];
        $season = $doc['season'];
        if (empty($season['name']) || empty($season['starts_on']) || empty($season['ends_on']) || $season['ends_on'] < $season['starts_on']) {
            $errors[] = 'Saisonbezeichnung und gültiger Zeitraum sind erforderlich.';
        }
        if (DB::connection('clubunio')->table('clubora.seasons')->where('tenant_id', $tenant->id)->where('name', $season['name'] ?? '')->exists()) {
            $errors[] = 'Eine Saison mit dieser Bezeichnung besteht bereits.';
        }
        $teams = collect($doc['teams'])->keyBy('key');
        $prices = collect($doc['prices'])->keyBy('key');
        $rules = collect($doc['rules'])->keyBy('key');
        if ($teams->isEmpty()) {
            $errors[] = 'Mindestens eine Mannschaft ist erforderlich.';
        }
        $seen = [];
        foreach ($teams as $team) {
            if (! empty($team['team_id']) ? ! isset($facts['teams'][$team['team_id']]) : empty($team['name'])) {
                $errors[] = 'Eine Mannschaft fehlt oder ist nicht aktiv.';
            }
            $identity = ! empty($team['team_id']) ? 'id:'.$team['team_id'] : 'name:'.mb_strtolower(trim($team['name'] ?? ''));
            if (isset($seen[$identity])) {
                $errors[] = 'Eine Mannschaft ist mehrfach im Entwurf enthalten.';
            }
            $seen[$identity] = true;
            if (empty($team['team_id']) && collect($facts['teams'])->contains(fn ($t) => mb_strtolower(trim($t->name)) === mb_strtolower(trim($team['name'] ?? '')))) {
                $errors[] = 'Neue Mannschaft besteht bereits; bitte die bestehende auswählen.';
            }
            if (! empty($team['rule_key']) && ! $rules->has($team['rule_key'])) {
                $errors[] = 'Eine Mannschaft verweist auf eine entfernte Beitragsregel.';
            }
            $range = $this->range($team, $doc);
            if (empty($range['year_from']) || empty($range['year_to'])) {
                $warnings[] = ($team['name'] ?? 'Mannschaft').': kein Jahrgangsbereich für Spielervorschläge.';
            } elseif ($range['year_from'] > $range['year_to'] || (! empty($range['gender_id']) && ! isset($facts['genders'][$range['gender_id']]))) {
                $errors[] = 'Ungültiger Jahrgangsbereich oder Geschlechtswert bei einer Mannschaft.';
            }
        }
        foreach ($prices as $price) {
            if (empty($price['name']) || ! isset($price['amount'])) {
                $errors[] = 'Bei einem Beitragspreis fehlen Name oder Betrag.';
            }
        }
        foreach ($rules as $rule) {
            if (! $prices->has($rule['price_key'] ?? '') || ! isset($facts['genders'][$rule['gender_id'] ?? 0]) || empty($rule['year_from']) || empty($rule['year_to']) || $rule['year_from'] > $rule['year_to']) {
                $errors[] = 'Eine Beitragsregel ist unvollständig.';
            }
            foreach ($rules as $other) {
                if ($rule['key'] !== $other['key'] && ($rule['gender_id'] ?? null) == ($other['gender_id'] ?? null)
                    && ($rule['year_from'] ?? 0) <= ($other['year_to'] ?? 0) && ($rule['year_to'] ?? 0) >= ($other['year_from'] ?? 0)) {
                    $errors[] = 'Beitragsregeln desselben Geschlechts überschneiden sich.';
                }
            }
        }
        $seen = [];
        $players = [];
        foreach ($doc['assignments'] as $assignment) {
            $member = $facts['members'][$assignment['member_id']] ?? null;
            $team = $teams->get($assignment['team_key']);
            if (! $member || ! $team) {
                $errors[] = 'Eine Zuordnung verweist auf ein ausgetretenes, archiviertes oder fehlendes Mitglied beziehungsweise eine fehlende Mannschaft.';

                continue;
            }
            if ($assignment['kind'] === 'staff' && ! isset($facts['staff_functions'][$assignment['function_id'] ?? 0])) {
                $errors[] = 'Bei einer Staff-Zuordnung fehlt eine aktive Funktion.';
            }
            $key = $assignment['kind'].':'.$assignment['team_key'].':'.$assignment['member_id'].':'.($assignment['kind'] === 'staff' ? ($assignment['function_id'] ?? '') : '');
            if (isset($seen[$key])) {
                $errors[] = 'Eine Zuordnung ist doppelt enthalten.';
            }
            $seen[$key] = true;
            if ($assignment['kind'] === 'player') {
                $players[$assignment['member_id']][] = $team['name'];
                if (! $this->matches($member, $team, $doc)) {
                    $warnings[] = $member->first_name.' '.$member->last_name.': Jahrgang/Geschlecht für '.$team['name'].' nicht passend oder nicht definiert.';
                }
            }
        }
        foreach ($players as $member => $assigned) {
            if (count($assigned) > 1) {
                $warnings[] = 'Mitglied #'.$member.' ist mehreren Mannschaften zugeordnet.';
            }
        }
        foreach ($teams as $team) {
            $assignments = collect($doc['assignments'])->where('team_key', $team['key']);
            if (! $assignments->contains('kind', 'staff')) {
                $warnings[] = $team['name'].': kein Staff zugeordnet.';
            }
            if (! $assignments->contains('kind', 'lead')) {
                $warnings[] = $team['name'].': keine technische Leitung zugeordnet.';
            }
            if (! empty($team['capacity']) && $assignments->where('kind', 'player')->count() > $team['capacity']) {
                $warnings[] = $team['name'].': Zielgrösse überschritten.';
            }
        }
        foreach ($doc['slots'] as $slot) {
            if (! $teams->has($slot['team_key'] ?? '') || empty($slot['starts_at']) || empty($slot['ends_at']) || $slot['ends_at'] <= $slot['starts_at'] || (empty($slot['field_id']) && empty($slot['hall']))) {
                $errors[] = 'Eine Trainingszeit ist unvollständig oder ungültig.';
            }
            foreach (['period', 'weekday', 'field', 'room', 'side'] as $category) {
                $option = $facts['training_options'][$slot[$category.'_id'] ?? 0] ?? null;
                if ((! empty($slot[$category.'_id']) || in_array($category, ['period', 'weekday'])) && (! $option || $option->category !== $category)) {
                    $errors[] = 'Bei einer Trainingszeit fehlt ein gültiger Auswahlwert ('.$category.').';
                }
            }
            foreach ($doc['slots'] as $other) {
                if ($other['key'] === $slot['key'] || ($other['period_id'] ?? null) != ($slot['period_id'] ?? null) || ($other['weekday_id'] ?? null) != ($slot['weekday_id'] ?? null)) {
                    continue;
                }
                $samePlace = (! empty($slot['field_id']) && $slot['field_id'] == ($other['field_id'] ?? null)) || (! empty($slot['hall']) && $slot['hall'] === ($other['hall'] ?? null));
                if (($samePlace || ($slot['team_key'] ?? null) === ($other['team_key'] ?? null)) && ($slot['starts_at'] ?? '') < ($other['ends_at'] ?? '') && ($slot['ends_at'] ?? '') > ($other['starts_at'] ?? '')) {
                    $warnings[] = 'Trainingszeiten überschneiden sich am selben Ort oder für dieselbe Mannschaft; Platzaufteilung prüfen.';
                }
            }
        }
        $existingSlots = [];
        if ($doc['slots'] && ! empty($season['starts_on']) && ! empty($season['ends_on'])) {
            $existingSlots = DB::connection('clubunio')->table('clubora.training_slots as slot')
                ->join('clubora.team_seasons as ts', fn ($join) => $join->on('ts.id', '=', 'slot.team_season_id')->on('ts.tenant_id', '=', 'slot.tenant_id'))
                ->join('clubora.seasons as s', fn ($join) => $join->on('s.id', '=', 'ts.season_id')->on('s.tenant_id', '=', 'ts.tenant_id'))
                ->where('slot.tenant_id', $tenant->id)->where('slot.active', true)->where('ts.active', true)->where('s.active', true)
                ->where('s.starts_on', '<=', $season['ends_on'])->where('s.ends_on', '>=', $season['starts_on'])->orderBy('slot.id')
                ->get(['slot.id', 'slot.period_id', 'slot.weekday_id', 'slot.starts_at', 'slot.ends_at', 'slot.field_id', 'slot.hall', 'ts.team_id'])->all();
            foreach ($doc['slots'] as $slot) {
                $team = $teams->get($slot['team_key'] ?? '');
                foreach ($existingSlots as $existing) {
                    $samePlace = (! empty($slot['field_id']) && $slot['field_id'] == $existing->field_id)
                        || (! empty($slot['hall']) && $slot['hall'] === $existing->hall);
                    $sameTeam = ! empty($team['team_id']) && $team['team_id'] == $existing->team_id;
                    if (($samePlace || $sameTeam) && ($slot['period_id'] ?? null) == $existing->period_id && ($slot['weekday_id'] ?? null) == $existing->weekday_id
                        && ($slot['starts_at'] ?? '') < $existing->ends_at && ($slot['ends_at'] ?? '') > $existing->starts_at) {
                        $warnings[] = 'Trainingszeit überschneidet sich mit einer bestehenden Belegung einer zeitlich überlappenden Saison; Belegung und Platzaufteilung prüfen.';
                    }
                }
            }
        }
        $errors = array_values(array_unique($errors));
        $warnings = array_values(array_unique($warnings));

        return ['errors' => $errors, 'warnings' => $warnings, 'token' => hash_hmac('sha256', json_encode([$row->id, $row->revision, $doc, $facts, $existingSlots], JSON_THROW_ON_ERROR), config('app.key'))];
    }

    public function complete(Account $actor, Tenant $tenant, int $id, int $revision, string $token, bool $acknowledge): int
    {
        $this->authorizeCompletion($actor, $tenant);

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision, $token, $acknowledge) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $row = $this->table($tenant)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $this->authorizeCompletion($actor, $tenant);
            if ($row->state === 'completed') {
                return (int) $row->season_id;
            }
            abort_unless($row->state === 'draft' && $row->revision === $revision, 409);
            // Keep the referenced eligibility/catalog rows stable until all domain writes finish.
            $document = $this->get($actor, $tenant, $id)->document;
            foreach (['member_statuses', 'genders', 'staff_functions', 'training_options'] as $table) {
                DB::connection('clubunio')->table('clubora.'.$table)->where('tenant_id', $tenant->id)->orderBy('id')->sharedLock()->get(['id']);
            }
            DB::connection('clubunio')->table('clubora.members')->where('tenant_id', $tenant->id)
                ->whereIn('id', collect($document['assignments'])->pluck('member_id')->unique()->all())->orderBy('id')->sharedLock()->get(['id']);
            $review = $this->review($actor, $tenant, $id);
            if ($review['errors'] || ! hash_equals($review['token'], $token) || ($review['warnings'] && ! $acknowledge)) {
                throw ValidationException::withMessages(['plan' => 'Bitte aktuelle Prüfung und Hinweise bestätigen; bei geänderten Daten erneut prüfen.']);
            }
            $doc = $this->get($actor, $tenant, $id)->document;
            $catalog = app(CatalogService::class);
            $links = app(TeamAssignmentService::class);
            $contributions = app(ContributionTrainingService::class);
            $results = [];
            $remember = function (string $key, $record) use (&$results, $tenant, $id) {
                $results[] = ['tenant_id' => $tenant->id, 'plan_id' => $id, 'item_key' => $key,
                    'entity' => str($record->getTable())->afterLast('.')->toString(), 'target_id' => $record->id];

                return $record->id;
            };
            $season = $remember('season', $catalog->save($actor, $tenant, Season::class, null, $doc['season'] + ['active' => true]));
            $prices = [];
            foreach ($doc['prices'] as $price) {
                $key = $price['key'];
                unset($price['key']);
                $prices[$key] = $remember($key, $contributions->save($actor, $tenant, ContributionPrice::class, null, $price + ['active' => true]));
            }
            foreach ($doc['rules'] as $rule) {
                $key = $rule['key'];
                $data = ['season_id' => $season, 'price_id' => $prices[$rule['price_key']], 'gender_id' => $rule['gender_id'], 'year_from' => $rule['year_from'], 'year_to' => $rule['year_to'], 'active' => true];
                $remember($key, $contributions->save($actor, $tenant, ContributionRule::class, null, $data));
            }
            $teams = [];
            foreach ($doc['teams'] as $team) {
                $teamId = $team['team_id'] ?? null;
                if (! $teamId) {
                    $record = $catalog->save($actor, $tenant, Team::class, null, ['name' => $team['name'], 'laundry_allowance' => $team['laundry_allowance'] ?? null, 'active' => true]);
                    $teamId = $remember('master:'.$team['key'], $record);
                }
                $teams[$team['key']] = $remember($team['key'], $links->save($actor, $tenant, TeamSeason::class, null, ['team_id' => $teamId, 'season_id' => $season, 'active' => true]));
            }
            foreach (self::KINDS as $kind => $model) {
                $assignments = collect($doc['assignments'])->where('kind', $kind)->values();
                if ($assignments->isEmpty()) {
                    continue;
                }
                $inputs = $assignments->map(function ($assignment) use ($teams, $kind) {
                    $data = ['team_season_id' => $teams[$assignment['team_key']], 'member_id' => $assignment['member_id'], 'active' => true];
                    if ($kind === 'staff') {
                        $data['staff_function_id'] = $assignment['function_id'];
                    }

                    return $data;
                })->all();
                foreach ($links->createMany($actor, $tenant, $model, $inputs) as $index => $record) {
                    $remember($assignments[$index]['key'], $record);
                }
            }
            foreach ($doc['slots'] as $slot) {
                $key = $slot['key'];
                $slot['team_season_id'] = $teams[$slot['team_key']];
                unset($slot['key'], $slot['team_key']);
                $remember($key, $contributions->save($actor, $tenant, TrainingSlot::class, null, $slot + ['active' => true]));
            }
            foreach (array_chunk($results, 100) as $chunk) {
                DB::connection('clubunio')->table('clubora.season_plan_results')->insert($chunk);
            }
            $this->authorizeCompletion($actor, $tenant);
            $this->table($tenant)->where('id', $id)->update(['state' => 'completed', 'revision' => $revision + 1, 'season_id' => $season, 'completed_at' => now(), 'updated_at' => now()]);
            $this->audit($actor, $tenant, $id, $revision, $revision + 1, 'completed');

            return $season;
        });
    }

    public function discard(Account $actor, Tenant $tenant, int $id, int $revision): void
    {
        $this->authorize($actor, $tenant, true);
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'seasons', 'deactivate');
        DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $id, $revision) {
            $row = $this->table($tenant)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row && $row->state === 'draft' && $row->revision === $revision, 409);
            $this->authorize($actor, $tenant, true);
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'seasons', 'deactivate');
            $this->table($tenant)->where('id', $id)->update(['state' => 'discarded', 'revision' => $revision + 1, 'updated_at' => now()]);
            $this->audit($actor, $tenant, $id, $revision, $revision + 1, 'discarded');
        });
    }

    private function audit(Account $actor, Tenant $tenant, int $id, int $before, int $after, string $event): void
    {
        DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
            'object_type' => 'season_plans', 'object_id' => $id, 'before_value' => json_encode(['revision' => $before]),
            'after_value' => json_encode(['revision' => $after, 'event' => $event])]);
    }

    private function authorizeCompletion(Account $actor, Tenant $tenant): void
    {
        $this->authorize($actor, $tenant, true);
        foreach (['seasons' => 'transfer', 'teams' => 'create', 'contributions' => 'create', 'training_slots' => 'create'] as $module => $function) {
            app(ModulePermissionService::class)->authorize($actor, $tenant, $module, $function);
        }
    }
}

<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Resources\ProfileUi;
use App\ClubUnio\Services\SeasonPlanningService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class SeasonPlanning extends Page
{
    protected string $view = 'clubunio.season-planning';

    protected static ?string $title = 'Saisonplanung';

    #[Locked]
    public ?int $planId = null;

    #[Locked]
    public int $revision = 0;

    #[Locked]
    public string $state = 'draft';

    #[Locked]
    public array $review = [];

    #[Locked]
    public ?int $seasonId = null;

    #[Locked]
    public ?string $savedAt = null;

    public array $document = [];

    public ?int $sourceId = null;

    public int $step = 1;

    public bool $acknowledge = false;

    public string $search = '';

    public string $kind = 'player';

    public ?int $functionId = null;

    public string $targetTeam = '';

    public bool $onlyUnassigned = true;

    public bool $onlyMatching = false;

    public ?int $birthYear = null;

    public int $poolPage = 1;

    public string $planSearch = '';

    public int $draftPage = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Sport';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-calendar-days';
    }

    public static function canAccess(): bool
    {
        foreach (['seasons', 'teams', 'members', 'contributions', 'training_slots'] as $module) {
            if (! ProfileUi::allows($module)) {
                return false;
            }
        }

        return true;
    }

    private function service(): SeasonPlanningService
    {
        return app(SeasonPlanningService::class);
    }

    private function writable(): void
    {
        $this->service()->authorize(Filament::auth()->user(), Filament::getTenant(), true);
        abort_unless($this->planId && $this->state === 'draft', 409);
    }

    public function newPlan(): void
    {
        $service = $this->service();
        $service->authorize(Filament::auth()->user(), Filament::getTenant(), true);
        $doc = $service->seed(Filament::auth()->user(), Filament::getTenant(), $this->sourceId);
        $row = $service->save(Filament::auth()->user(), Filament::getTenant(), null, null, $doc, $this->sourceId);
        $this->openPlan($row->id);
    }

    public function openPlan(int $id): void
    {
        $row = $this->service()->get(Filament::auth()->user(), Filament::getTenant(), $id);
        $this->planId = $row->id;
        $this->revision = $row->revision;
        $this->state = $row->state;
        $this->document = $row->document;
        $this->sourceId = $row->source_season_id;
        $this->seasonId = $row->season_id;
        $this->savedAt = $row->updated_at;
        $this->review = [];
        $this->acknowledge = false;
        $this->step = (int) ($row->document['progress'] ?? 1);
        $this->poolPage = 1;
        $this->resetErrorBag();
    }

    public function updatedDocument(): void
    {
        $this->saveDraft();
    }

    public function saveDraft(): void
    {
        $this->writable();
        abort_unless($this->step >= 1 && $this->step <= 7, 422);
        $this->document['progress'] = $this->step;
        $this->review = [];
        $this->acknowledge = false;
        $this->savedAt = null;
        $row = $this->service()->save(Filament::auth()->user(), Filament::getTenant(), $this->planId, $this->revision, $this->document);
        // Keep pending browser edits intact; normalized storage is loaded when reopening.
        $this->revision = $row->revision;
        $this->savedAt = $row->updated_at;
        $this->resetErrorBag();
    }

    public function go(int $step): void
    {
        abort_unless($step >= 1 && $step <= 7, 422);
        $this->step = $step;
        if ($this->state === 'draft') {
            $this->saveDraft();
        }
        if ($step === 4) {
            $this->kind = 'player';
        }
        if ($step === 5 && $this->kind === 'player') {
            $this->kind = 'staff';
        }
        $this->poolPage = 1;
        if ($step === 7 && $this->state === 'draft') {
            $this->checkPlan();
        }
    }

    public function checkPlan(): void
    {
        $this->saveDraft();
        $this->review = $this->service()->review(Filament::auth()->user(), Filament::getTenant(), $this->planId);
        $this->step = 7;
    }

    public function finish(): void
    {
        // Repeated delivery after a lost response is safe; the service returns the existing result.
        abort_unless($this->planId && isset($this->review['token']), 409);
        $this->seasonId = $this->service()->complete(Filament::auth()->user(), Filament::getTenant(), $this->planId,
            $this->revision, $this->review['token'], $this->acknowledge);
        $this->openPlan($this->planId);
        Notification::make()->title('Saison vollständig erstellt')->success()->send();
    }

    public function discard(): void
    {
        $this->writable();
        $this->service()->discard(Filament::auth()->user(), Filament::getTenant(), $this->planId, $this->revision);
        $this->openPlan($this->planId);
    }

    public function addRow(string $collection): void
    {
        $this->writable();
        $row = match ($collection) {
            'teams' => ['team_id' => null, 'name' => '', 'laundry_allowance' => null, 'rule_key' => null, 'year_from' => null, 'year_to' => null, 'gender_id' => null, 'capacity' => null],
            'prices' => ['name' => '', 'amount' => null, 'bexio_product_id' => null, 'bexio_article_id' => null],
            'rules' => ['price_key' => null, 'gender_id' => null, 'year_from' => null, 'year_to' => null],
            'slots' => ['team_key' => null, 'period_id' => null, 'weekday_id' => null, 'field_id' => null, 'room_id' => null, 'side_id' => null,
                'starts_at' => null, 'ends_at' => null, 'hall' => null, 'all_fields' => false, 'without_main' => false, 'synthetic_only' => false, 'website_visible' => true],
            default => abort(422),
        };
        $this->document[$collection][] = ['key' => (string) Str::uuid()] + $row;
        $this->saveDraft();
    }

    public function removeRow(string $collection, string $key): void
    {
        $this->writable();
        abort_unless(in_array($collection, ['teams', 'prices', 'rules', 'slots', 'assignments']), 422);
        $this->document[$collection] = array_values(array_filter($this->document[$collection], fn ($r) => $r['key'] !== $key));
        if ($collection === 'teams') {
            foreach (['assignments', 'slots'] as $child) {
                $this->document[$child] = array_values(array_filter($this->document[$child], fn ($r) => $r['team_key'] !== $key));
            }
        }
        $this->saveDraft();
    }

    public function selectTeam(int $index, ?int $id): void
    {
        $this->writable();
        abort_unless(isset($this->document['teams'][$index]), 422);
        $facts = $this->service()->facts(Filament::auth()->user(), Filament::getTenant());
        $team = $id ? ($facts['teams'][$id] ?? abort(422)) : null;
        $this->document['teams'][$index]['team_id'] = $id;
        $this->document['teams'][$index]['name'] = $team?->name ?? '';
        $this->document['teams'][$index]['laundry_allowance'] = $team?->laundry_allowance;
        $this->saveDraft();
    }

    public function mergeTeam(string $from, string $to): void
    {
        $this->writable();
        $teams = collect($this->document['teams'])->keyBy('key');
        abort_unless($from !== $to && $teams->has($from) && $teams->has($to), 422);
        foreach (['assignments', 'slots'] as $collection) {
            foreach ($this->document[$collection] as &$row) {
                if ($row['team_key'] === $from) {
                    $row['team_key'] = $to;
                }
            }
            unset($row);
        }
        $seen = [];
        $this->document['assignments'] = array_values(array_filter($this->document['assignments'], function ($r) use (&$seen) {
            $key = implode(':', [$r['kind'], $r['team_key'], $r['member_id'], $r['kind'] === 'staff' ? $r['function_id'] : '']);
            if (isset($seen[$key])) {
                return false;
            }

            return $seen[$key] = true;
        }));
        $this->document['teams'] = $teams->except($from)->values()->all();
        $this->saveDraft();
    }

    public function moveMember(string $kind, int $member, ?string $from, string $to): void
    {
        $this->writable();
        abort_unless(in_array($kind, ['player', 'staff', 'lead']) && collect($this->document['teams'])->contains('key', $to), 422);
        $facts = $this->service()->facts(Filament::auth()->user(), Filament::getTenant());
        abort_unless(isset($facts['members'][$member]), 422);
        if ($kind === 'staff' && ! isset($facts['staff_functions'][$this->functionId ?? 0])) {
            $this->addError('assignment', 'Bitte eine aktive Staff-Funktion auswählen.');

            return;
        }
        if ($from) {
            $this->document['assignments'] = array_values(array_filter($this->document['assignments'], fn ($r) => ! ($r['kind'] === $kind && $r['member_id'] == $member && $r['team_key'] === $from && ($kind !== 'staff' || $r['function_id'] == $this->functionId))));
        }
        $exists = collect($this->document['assignments'])->contains(fn ($r) => $r['kind'] === $kind && $r['member_id'] == $member && $r['team_key'] === $to && ($kind !== 'staff' || $r['function_id'] == $this->functionId));
        if (! $exists) {
            $this->document['assignments'][] = ['key' => (string) Str::uuid(), 'kind' => $kind, 'team_key' => $to, 'member_id' => $member, 'function_id' => $kind === 'staff' ? $this->functionId : null];
        }
        $this->saveDraft();
    }

    public function suggest(string $teamKey): void
    {
        $this->writable();
        $team = collect($this->document['teams'])->firstWhere('key', $teamKey) ?? abort(422);
        $facts = $this->service()->facts(Filament::auth()->user(), Filament::getTenant());
        $assigned = collect($this->document['assignments'])->where('kind', 'player')->pluck('member_id')->all();
        $count = collect($this->document['assignments'])->where('kind', 'player')->where('team_key', $teamKey)->count();
        foreach ($facts['members'] as $member) {
            if (! in_array($member->id, $assigned) && $this->service()->matches($member, $team, $this->document) && (empty($team['capacity']) || $count < $team['capacity'])) {
                $this->document['assignments'][] = ['key' => (string) Str::uuid(), 'kind' => 'player', 'team_key' => $teamKey, 'member_id' => $member->id, 'function_id' => null];
                $count++;
            }
        }
        $this->saveDraft();
    }

    public function browsePool(int $direction): void
    {
        $this->service()->authorize(Filament::auth()->user(), Filament::getTenant());
        $this->poolPage = max(1, $this->poolPage + ($direction > 0 ? 1 : -1));
    }

    public function browseDrafts(int $direction): void
    {
        $this->service()->authorize(Filament::auth()->user(), Filament::getTenant());
        $this->draftPage = max(1, $this->draftPage + ($direction > 0 ? 1 : -1));
    }

    public function updated($field): void
    {
        if (in_array($field, ['search', 'birthYear', 'kind', 'onlyUnassigned', 'onlyMatching', 'targetTeam'])) {
            $this->poolPage = 1;
        }
        if ($field === 'planSearch') {
            $this->draftPage = 1;
        }
        if ($field === 'kind' && $this->kind !== 'player') {
            $this->onlyMatching = false;
        }
    }

    protected function getViewData(): array
    {
        $facts = $this->service()->facts(Filament::auth()->user(), Filament::getTenant());
        $draftQuery = $this->service()->table(Filament::getTenant())
            ->when($this->planSearch !== '', fn ($q) => $q->whereRaw('name ILIKE ? ESCAPE \'!\'', ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $this->planSearch).'%']));
        $draftCount = (clone $draftQuery)->count();
        $this->draftPage = max(1, min($this->draftPage, (int) ceil($draftCount / 50)));
        $drafts = $draftQuery->orderByDesc('updated_at')->orderByDesc('id')->offset((max(1, $this->draftPage) - 1) * 50)->limit(50)
            ->get(['id', 'name', 'state', 'revision', 'updated_at']);
        $pool = collect($facts['members'])->sortBy(fn ($m) => mb_strtolower($this->service()->memberLabel($m)));
        $assigned = collect($this->document['assignments'] ?? [])->where('kind', $this->kind)->pluck('member_id')->all();
        $team = collect($this->document['teams'] ?? [])->firstWhere('key', $this->targetTeam);
        $pool = $pool->filter(fn ($m) => (! $this->search || str_contains(mb_strtolower($this->service()->memberLabel($m).' '.$m->first_name.' '.$m->last_name), mb_strtolower($this->search)))
            && (! $this->birthYear || substr($m->birth_date ?? '', 0, 4) == $this->birthYear)
            && (! $this->onlyUnassigned || ! in_array($m->id, $assigned))
            && ($this->kind !== 'player' || ! $this->onlyMatching || ($team && $this->service()->matches($m, $team, $this->document))));
        $total = $pool->count();
        $this->poolPage = max(1, min($this->poolPage, (int) ceil($total / 40)));
        $pool = $pool->slice((max(1, $this->poolPage) - 1) * 40, 40);

        return compact('facts', 'drafts', 'draftCount', 'pool', 'total');
    }
}

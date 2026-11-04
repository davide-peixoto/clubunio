<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Pages\Components\PitchPlanningCalendar;
use App\ClubUnio\Services\PitchPlanningService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PitchPlanning extends Page
{
    use LocalizedTitle;

    protected string $view = 'clubunio.pitch-planning.page';
    protected static ?string $title = 'Sportplatzbelegung';
    protected static ?string $slug = 'pitch-planning';

    public string $date = '';
    public string $mode = 'day';
    public ?int $teamId = null;
    public array $form = [];

    #[Locked]
    public array $options = ['fields' => [], 'teams' => [], 'capabilities' => []];
    #[Locked]
    public array $entries = [];
    #[Locked]
    public ?array $selected = null;
    #[Locked]
    public ?array $selectedArea = null;
    #[Locked]
    public ?string $editor = null;
    #[Locked]
    public ?string $loadError = null;

    public static function getNavigationGroup(): ?string { return 'Anlagen & Kommunikation'; }
    public static function getNavigationIcon(): string { return 'heroicon-o-calendar-days'; }
    public static function getNavigationLabel(): string { return __('Sportplatzbelegung'); }

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        $tenant = Filament::getTenant();

        return $actor && $tenant && app(PitchPlanningService::class)->canView($actor, $tenant);
    }

    protected function service(): PitchPlanningService { return app(PitchPlanningService::class); }

    protected function callService(string $method, mixed ...$arguments): array
    {
        return $this->service()->$method(Filament::auth()->user(), Filament::getTenant(), ...$arguments);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->date = CarbonImmutable::now(PitchPlanningCalendar::TIMEZONE)->toDateString();
        $this->refreshCalendar();
    }

    public function refreshCalendar(): void
    {
        $this->validate(['date' => ['required', 'date_format:Y-m-d'], 'mode' => ['required', 'in:day,week,month'], 'teamId' => ['nullable', 'integer', 'min:1']]);
        $this->loadError = null;
        try {
            $this->options = $this->callService('options');
            [$start, $end] = (new PitchPlanningCalendar)->range($this->date, $this->mode);
            $entries = [];
            for ($from = $start; $from->lt($end); $from = $until) {
                $until = $from->addDays(42)->min($end);
                $projection = $this->callService('calendar', array_filter([
                    'from' => $from->toDateString(), 'to' => $until->subDay()->toDateString(),
                    'team_season_id' => $this->teamId,
                ], fn ($value) => $value !== null));
                foreach ($projection['entries'] as $entry) { $entries[$entry['key']] = $entry; }
                $this->options['fields'] = $projection['fields'];
                $this->options['capabilities'] = $projection['capabilities'];
            }
            $this->entries = array_values($entries);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 503) { throw $exception; }
            $this->entries = [];
            $this->options = ['fields' => [], 'teams' => [], 'capabilities' => []];
            $this->loadError = __('Die Sportplatzplanung ist noch nicht verfügbar.');
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['date', 'mode', 'teamId'], true)) {
            $this->closeEditor();
            $this->refreshCalendar();
        }
        if ($property === 'form.field_id') { $this->form['area_id'] = null; }
    }

    public function browse(int $direction): void
    {
        $this->date = (new PitchPlanningCalendar)->browse($this->date, $this->mode, $direction);
        $this->updated('date');
    }

    public function today(): void
    {
        $this->date = CarbonImmutable::now(PitchPlanningCalendar::TIMEZONE)->toDateString();
        $this->updated('date');
    }

    public function openDay(string $date): void
    {
        (new PitchPlanningCalendar)->range($date, 'day');
        $this->date = $date;
        $this->mode = 'day';
        $this->updated('date');
    }

    public function createEntry(string $kind): void
    {
        abort_unless(in_array($kind, ['request', 'block', 'manual'], true), 422);
        $this->refreshCalendar();
        abort_unless($this->capability($kind === 'request' ? 'request' : 'manage'), 403);
        $this->selected = null;
        $this->editor = $kind;
        $this->form = ['field_id' => $this->options['fields'][0]['id'] ?? null, 'area_id' => null,
            'team_season_id' => $this->options['teams'][0]['id'] ?? null,
            'date' => $this->date, 'starts_at' => '18:00', 'duration_minutes' => 120,
            'opponent' => '', 'notes' => '', 'reason' => '', 'title' => ''];
        $this->showEditor();
    }

    public function editArea(?int $fieldId = null, ?int $areaId = null): void
    {
        $this->refreshCalendar();
        abort_unless($this->capability('manage'), 403);
        $field = collect($this->options['fields'])->firstWhere('id', $fieldId ?? ($this->options['fields'][0]['id'] ?? null));
        abort_unless($field, 404);
        $area = $areaId ? collect($field['areas'])->firstWhere('id', $areaId) : null;
        abort_if($areaId && ! $area, 404);
        $this->selected = null;
        $this->selectedArea = $area;
        $this->editor = 'area';
        $this->form = ['field_id' => $field['id'], 'name_de' => $area['name_de'] ?? '', 'name_en' => $area['name_en'] ?? '',
            'x' => $area['x'] ?? 0, 'y' => $area['y'] ?? 0, 'width' => $area['width'] ?? 0.5, 'height' => $area['height'] ?? 1];
        $this->showEditor();
    }

    public function editEntry(string $key): void
    {
        $this->refreshCalendar();
        $entry = collect($this->entries)->firstWhere('key', $key);
        abort_unless($entry, 404);
        $this->selected = $entry;
        $this->editor = 'edit';
        $this->form = ['field_id' => $entry['field_id'], 'area_id' => $entry['area_id'],
            'date' => $entry['date'],
            'starts_at' => CarbonImmutable::parse($entry['starts_at'], PitchPlanningCalendar::TIMEZONE)->setTimezone(PitchPlanningCalendar::TIMEZONE)->format('H:i'),
            'duration_minutes' => $entry['duration_minutes'], 'reason' => $entry['source'] === 'block' ? $entry['title'] : ''];
        $this->showEditor();
    }

    public function proposeMove(string $key, int $fieldId, string $date, string $time): void
    {
        $this->editEntry($key);
        abort_unless($this->selected['editable'] && $this->capability('manage'), 403);
        abort_unless(collect($this->options['fields'])->contains('id', $fieldId), 422);
        (new PitchPlanningCalendar)->range($date, 'day');
        abort_unless((bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time), 422);
        if ((int) $this->form['field_id'] !== $fieldId) { $this->form['area_id'] = null; }
        $this->form['field_id'] = $fieldId;
        $this->form['date'] = $date;
        $this->form['starts_at'] = $time;
    }

    public function editCompetitionTeams(string $key): void
    {
        $this->editEntry($key);
        abort_unless($this->selected['source'] === 'competition' && $this->capability('manage'), 403);
        $this->editor = 'teams';
        $this->form = ['team_season_ids' => $this->selected['team_season_ids']];
    }

    private function showEditor(): void
    {
        $this->resetErrorBag();
        $this->dispatch('open-modal', id: 'pitch-entry');
    }

    public function closeEditor(): void
    {
        $this->editor = null;
        $this->selected = null;
        $this->selectedArea = null;
        $this->form = [];
        $this->resetErrorBag();
        $this->dispatch('close-modal', id: 'pitch-entry');
    }

    public function saveEntry(): void
    {
        abort_unless($this->editor, 409);
        if ($this->editor === 'teams') {
            abort_unless($this->selected && $this->selected['source'] === 'competition', 409);
            $this->validate(['form.team_season_ids' => ['present', 'array', 'max:20'], 'form.team_season_ids.*' => ['integer', 'distinct', 'min:1']]);
            $this->perform(fn () => $this->callService('configureCompetition', $this->selected['source_id'],
                ['revision' => $this->selected['revision'], 'team_season_ids' => $this->form['team_season_ids']]));
            return;
        }
        if ($this->editor === 'area') {
            $this->validate(['form.field_id' => ['required', 'integer', 'min:1'], 'form.name_de' => ['required', 'string', 'max:160'],
                'form.name_en' => ['required', 'string', 'max:160'], 'form.x' => ['required', 'numeric', 'between:0,1'],
                'form.y' => ['required', 'numeric', 'between:0,1'], 'form.width' => ['required', 'numeric', 'gt:0', 'max:1'],
                'form.height' => ['required', 'numeric', 'gt:0', 'max:1']]);
            $input = array_intersect_key($this->form, array_flip(['field_id', 'name_de', 'name_en', 'x', 'y', 'width', 'height']));
            if ($this->selectedArea) { $input += ['id' => $this->selectedArea['id'], 'revision' => $this->selectedArea['revision']]; }
            $this->perform(fn () => $this->callService('saveArea', $input));
            return;
        }
        $this->validate(['form.field_id' => ['required', 'integer', 'min:1'], 'form.area_id' => ['nullable', 'integer', 'min:1'],
            'form.date' => ['required', 'date_format:Y-m-d'], 'form.starts_at' => ['required', 'date_format:H:i'],
            'form.duration_minutes' => ['required', 'integer', 'min:1', 'max:'.($this->editor === 'block' || ($this->selected['source'] ?? '') === 'block' ? 44640 : 1440)]]);
        $input = array_intersect_key($this->form, array_flip(['field_id', 'area_id', 'date', 'starts_at', 'duration_minutes', 'team_season_id', 'opponent', 'notes', 'reason', 'title']));
        $input['area_id'] = $input['area_id'] ?: null;
        $this->perform(function () use ($input) {
            if ($this->editor === 'edit') {
                abort_unless($this->selected && $this->selected['editable'], 403);
                if ($this->selected['source'] === 'block') {
                    $this->callService('saveBlock', $input + ['id' => $this->selected['source_id'], 'revision' => $this->selected['revision']]);
                } else {
                    $this->callService('move', $this->selected['key'], $input + ['revision' => $this->selected['revision']]);
                }
            } else {
                $method = match ($this->editor) { 'request' => 'requestFriendly', 'block' => 'saveBlock', 'manual' => 'createManual', default => abort(422) };
                $this->callService($method, $input);
            }
        });
    }

    public function decideEntry(string $decision): void
    {
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 422);
        abort_unless($this->selected && $this->selected['source'] === 'request' && $this->selected['status'] === 'pending', 409);
        $this->perform(fn () => $this->callService('decide', $this->selected['source_id'],
            ['revision' => $this->selected['revision'], 'decision' => $decision]));
    }

    public function cancelEntry(): void
    {
        abort_unless($this->selected && in_array($this->selected['source'], ['training', 'request', 'block'], true), 409);
        $this->perform(fn () => $this->callService('cancel', $this->selected['key'], ['revision' => $this->selected['revision']]));
    }

    private function perform(callable $operation): void
    {
        $this->resetErrorBag();
        try {
            $operation();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                foreach ($messages as $message) { $this->addError('form.'.$key, $message); }
            }
            return;
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [409, 503], true)) { throw $exception; }
            $this->addError('save', $exception->getStatusCode() === 409
                ? __('Nicht gespeichert: Belegung oder Revision hat sich geändert. Bitte neu laden und prüfen.')
                : __('Die Sportplatzplanung ist noch nicht verfügbar.'));
            return;
        }
        $this->closeEditor();
        $this->refreshCalendar();
        Notification::make()->title(__('Gespeichert'))->success()->send();
    }

    public function capability(string $name): bool { return (bool) ($this->options['capabilities'][$name] ?? false); }

    protected function validationAttributes(): array
    {
        return ['date' => __('Datum'), 'mode' => __('Kalenderansicht'), 'teamId' => __('Mannschaft'),
            'form.field_id' => __('Spielfeld'), 'form.area_id' => __('Teilfläche'), 'form.date' => __('Datum'),
            'form.starts_at' => __('Beginn'), 'form.duration_minutes' => __('Dauer in Minuten'),
            'form.name_de' => __('Name (Deutsch)'), 'form.name_en' => __('Name (Englisch)'),
            'form.x' => __('Position X (0–1)'), 'form.y' => __('Position Y (0–1)'),
            'form.width' => __('Breite (0–1)'), 'form.height' => __('Höhe (0–1)'),
            'form.team_season_ids' => __('Mannschaften'), 'form.team_season_ids.*' => __('Mannschaft')];
    }

    protected function getViewData(): array
    {
        $calendar = new PitchPlanningCalendar;
        try {
            $days = $calendar->days($this->date ?: CarbonImmutable::now(PitchPlanningCalendar::TIMEZONE)->toDateString(), $this->mode, $this->entries);
        } catch (\InvalidArgumentException $exception) {
            $days = $calendar->days(CarbonImmutable::now(PitchPlanningCalendar::TIMEZONE)->toDateString(), 'day', []);
        }

        return ['days' => $days, 'columns' => $calendar->columns($this->options['fields'], $days[0]['events']),
            'areas' => collect($this->options['fields'])->firstWhere('id', $this->form['field_id'] ?? null)['areas'] ?? []];
    }
}

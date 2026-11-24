<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Pages\Components\PitchPlanningCalendar;
use App\ClubUnio\Services\{PitchPlanningService, VeoRecordingPlan, VeoRecordingAdjustments};
use Carbon\CarbonImmutable as Time;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Validation\ValidationException;

class VeoPlanning extends Page
{
    protected string $view = 'clubunio.veo-planning';
    protected static ?string $slug = 'veo-planning';
    public string $date = '';
    public string $mode = 'week';
    public array $cameraNames = [];
    public array $form = [];
    #[Locked]
    public ?array $selected = null;

    public static function getNavigationGroup(): ?string { return PitchPlanning::getNavigationGroup(); }
    public static function getNavigationIcon(): string { return 'heroicon-o-video-camera'; }
    public static function getNavigationLabel(): string { return __('veo.title'); }
    public function getTitle(): string { return static::getNavigationLabel(); }

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        $tenant = Filament::getTenant();
        return $actor && $tenant && app(VeoRecordingAdjustments::class)->canManage($actor, $tenant);
    }

    public function mount(VeoRecordingPlan $plan): void
    {
        abort_unless(static::canAccess(), 403);
        $this->date = Time::today('Europe/Zurich')->toDateString();
        $this->cameraNames = $plan->cameras(Filament::getTenant())->pluck('name', 'field_id')->all();
    }

    public function browse(int $direction): void
    {
        $this->validateRange();
        $this->date = (new PitchPlanningCalendar)->browse($this->date, $this->mode, $direction);
        $this->closeEditor();
    }

    public function today(): void { $this->date = Time::today('Europe/Zurich')->toDateString(); $this->closeEditor(); }

    public function openDay(string $date): void
    {
        (new PitchPlanningCalendar)->range($date, 'day');
        $this->date = $date; $this->mode = 'day'; $this->closeEditor();
    }

    private function validateRange(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['date' => 'required|date_format:Y-m-d', 'mode' => 'required|in:day,week,month'],
            attributes: ['date' => __('Datum'), 'mode' => __('Kalenderansicht')]);
    }

    public function saveCamera(int $fieldId, VeoRecordingPlan $plan): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(collect($this->options()['fields'])->contains('id', $fieldId), 404);
        $this->validate(['cameraNames.'.$fieldId => 'nullable|string|max:120'], attributes: ['cameraNames.'.$fieldId => __('veo.camera')]);
        $name = trim($this->cameraNames[$fieldId] ?? '');
        $plan->saveCamera(Filament::getTenant(), $fieldId, $name === '' ? null : $name);
        Notification::make()->title(__('veo.saved'))->success()->send();
    }

    public function setTeam(int $teamId, bool $enabled, VeoRecordingPlan $plan): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(collect($this->options()['teams'])->contains('id', $teamId), 404);
        $plan->setTeamEnabled(Filament::getTenant(), $teamId, $enabled);
    }

    private function options(): array
    {
        return app(PitchPlanningService::class)->options(Filament::auth()->user(), Filament::getTenant());
    }

    public function editEntry(string $key): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(preg_match('/\Arecording:([1-9][0-9]*)\z/', $key, $matches), 404);
        $entry = app(VeoRecordingAdjustments::class)->current(Filament::getTenant(), (int) $matches[1]);
        $this->selected = ['match_id' => $entry['match_id'], 'title' => $entry['title'], 'revision' => $entry['revision'],
            'adjusted' => $entry['adjusted'], 'fixture' => $entry['fixture_starts_at']->format('d.m.Y H:i')];
        $this->form = ['date' => $entry['starts_at']->toDateString(), 'time' => $entry['starts_at']->format('H:i'),
            'field_id' => $entry['field_id'], 'duration' => (int) $entry['starts_at']->diffInMinutes($entry['ends_at'])];
        $this->resetValidation();
        $this->dispatch('open-modal', id: 'veo-recording-editor');
    }

    public function proposeMove(string $key, int $fieldId, string $date, string $time): void
    {
        $this->editEntry($key);
        $this->form = array_replace($this->form, compact('date', 'time') + ['field_id' => $fieldId]);
    }

    public function saveRecording(bool $reset = false): void
    {
        abort_unless(static::canAccess() && $this->selected, 403);
        try {
            app(VeoRecordingAdjustments::class)->save(Filament::auth()->user(), Filament::getTenant(),
                $this->selected['match_id'], array_replace($this->form, ['revision' => $this->selected['revision']]), $reset);
        } catch (HttpExceptionInterface $exception) {
            if (! in_array($exception->getStatusCode(), [409, 422], true)) { throw $exception; }
            throw ValidationException::withMessages(['form' => $exception->getMessage()]);
        }
        $this->closeEditor();
        Notification::make()->title(__('veo.saved'))->success()->send();
    }

    public function closeEditor(): void
    {
        $this->selected = null; $this->form = []; $this->resetValidation();
        $this->dispatch('close-modal', id: 'veo-recording-editor');
    }

    protected function getViewData(): array
    {
        $this->validateRange();
        [$start, $end] = (new PitchPlanningCalendar)->range($this->date, $this->mode);
        $plan = app(VeoRecordingPlan::class);
        $options = $this->options();
        $entries = [];
        for ($from = $start; $from->lt($end); $from = $until) {
            $until = $from->addDays(28)->min($end);
            foreach ($plan->entries(Filament::getTenant(), $from->toDateString(), $until->subDay()->toDateString()) as $entry) {
                $entries[$entry['match_id']] = $entry;
            }
        }
        $events = collect($entries)->map(fn ($entry) => [
            'key' => 'recording:'.$entry['match_id'], 'title' => $entry['title'], 'field_id' => $entry['field_id'] ?? 0,
            'area_id' => null, 'source' => 'recording', 'status' => $entry['status'], 'editable' => true,
            'starts_at_iso' => $entry['starts_at']->toIso8601String(), 'ends_at_iso' => $entry['ends_at']->toIso8601String(),
            'conflicts' => $entry['status'] === 'conflict' ? 1 : 0,
        ])->values()->all();
        $days = (new PitchPlanningCalendar)->days($this->date, $this->mode, $events);
        $cameras = $plan->cameras(Filament::getTenant());
        $fields = collect($options['fields'])->map(fn ($field) => array_replace($field, [
            'name' => $field['name'].($cameras->has($field['id']) ? ' / '.$cameras->get($field['id'])->name : ''),
            'areas' => [],
        ]))->values();
        if (collect($events)->contains('field_id', 0)) {
            $fields->push(['id' => 0, 'name' => __('veo.unplaced'), 'available' => false, 'areas' => []]);
        }
        $statuses = ['planned' => __('veo.planned'), 'unplaced' => __('veo.unplaced'),
            'no_camera' => __('veo.no_camera'), 'conflict' => __('veo.conflict'), 'needs_review' => trans('veo_calendar.needs_review')];
        $text = array_replace(trans('facility_ui'), [
            'bookings' => trans('veo_calendar.recordings'), 'pending' => trans('veo_calendar.attention'),
            'readonly' => trans('veo_calendar.calendar_hint'), 'whole' => trans('veo_calendar.recording'),
            'available_hint' => trans('veo_calendar.manual_notice'),
        ]);
        return ['entries' => array_values($entries), 'fields' => $options['fields'], 'teams' => $options['teams'],
            'enabledTeams' => $plan->enabledTeams(Filament::getTenant())->all(),
            'period' => $start->locale(app()->getLocale())->isoFormat('LL').' - '.$end->subDay()->locale(app()->getLocale())->isoFormat('LL'),
            'payload' => ['mode' => $this->mode, 'date' => $this->date, 'locale' => app()->getLocale(), 'fields' => $fields->all(),
                'days' => collect($days)->map(fn ($day) => ['date' => $day['date']->toDateString(),
                    'label' => $day['date']->locale(app()->getLocale())->isoFormat('ddd, D. MMM'),
                    'today' => $day['date']->isToday(), 'events' => $day['events']])->all(),
                'canRequest' => false, 'canManage' => true, 'text' => $text, 'statuses' => $statuses,
                'sources' => ['recording' => trans('veo_calendar.recording')],
                'overview' => [[$text['bookings'], count($entries)], [$text['pending'], collect($entries)->where('status', '!=', 'planned')->count()],
                    [$text['conflicts'], collect($entries)->where('status', 'conflict')->count()]]],
        ];
    }
}

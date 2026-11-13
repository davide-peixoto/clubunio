<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Services\ModulePermissionService;
use App\ClubUnio\Services\PitchPlanningService;
use App\ClubUnio\Services\VeoRecordingPlan;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

class VeoPlanning extends Page
{
    protected string $view = 'clubunio.veo-planning';

    protected static ?string $slug = 'veo-planning';

    public string $from = '';

    #[Locked]
    public string $weekStart = '';

    public array $cameraNames = [];

    public static function getNavigationGroup(): ?string
    {
        return PitchPlanning::getNavigationGroup();
    }

    public static function getNavigationLabel(): string
    {
        return __('veo.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public static function canAccess(): bool
    {
        $actor = Filament::auth()->user();
        $tenant = Filament::getTenant();

        return $actor && $tenant && ! request()->attributes->has('clubunio_impersonation')
            && $actor->hasUnrestrictedBackofficeAccess($tenant)
            && app(ModulePermissionService::class)->allows($actor, $tenant, 'competitions', 'update');
    }

    public function mount(VeoRecordingPlan $plan): void
    {
        abort_unless(static::canAccess(), 403);
        $today = CarbonImmutable::today('Europe/Zurich');
        $this->from = $today->toDateString();
        $this->weekStart = $this->from;
        $this->cameraNames = $plan->cameras(Filament::getTenant())->pluck('name', 'field_id')->all();
    }

    public function previousWeek(): void
    {
        $this->shiftWeek(-7);
    }

    public function nextWeek(): void
    {
        $this->shiftWeek(7);
    }

    private function shiftWeek(int $days): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['from' => 'required|date_format:Y-m-d'], attributes: ['from' => __('veo.start_date')]);
        $start = CarbonImmutable::parse($this->from, 'Europe/Zurich')->addDays($days);
        $this->from = $start->toDateString();
        $this->weekStart = $this->from;
    }

    public function showWeek(): void
    {
        $this->shiftWeek(0);
    }

    public function saveCamera(int $fieldId, VeoRecordingPlan $plan): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(collect($this->options()['fields'])->contains('id', $fieldId), 404);
        $key = 'cameraNames.'.$fieldId;
        $this->validate([$key => 'nullable|string|max:120'], attributes: [$key => __('veo.camera')]);
        $name = trim($this->cameraNames[$fieldId] ?? '');
        $plan->saveCamera(Filament::getTenant(), $fieldId, $name === '' ? null : $name);
        Notification::make()->title(__('veo.saved'))->success()->send();
    }

    public function setTeam(int $teamId, bool $enabled, VeoRecordingPlan $plan): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(collect($this->options()['teams'])->contains('id', $teamId), 404);
        $plan->setTeamEnabled(Filament::getTenant(), $teamId, $enabled);
        Notification::make()->title(__('veo.saved'))->success()->send();
    }

    private function options(): array
    {
        return app(PitchPlanningService::class)->options(Filament::auth()->user(), Filament::getTenant());
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $tenant = Filament::getTenant();
        $plan = app(VeoRecordingPlan::class);
        $options = $this->options();
        $start = CarbonImmutable::parse($this->weekStart, 'Europe/Zurich');

        return [
            'entries' => $plan->entries($tenant, $this->weekStart, $start->addDays(6)->toDateString()),
            'fields' => collect($options['fields'])->keyBy('id'),
            'teams' => $options['teams'],
            'enabledTeams' => $plan->enabledTeams($tenant)->map(fn ($id) => (int) $id)->all(),
        ];
    }
}

<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Models\Competition;
use App\ClubUnio\Services\SfvCompetitionService;
use App\ClubUnio\Services\SfvPlanningService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;

class SfvCompetitions extends Page
{
    protected string $view = 'clubunio.sfv-competitions';

    protected static ?string $title = 'SFV-Wettkämpfe';

    public string $from = '';

    public string $to = '';

    public string $match = '';

    public string $target = '';

    public bool $separate = false;

    #[Locked]
    public ?array $preview = null;

    #[Locked]
    public ?array $batchPreview = null;

    public static function getNavigationGroup(): ?string
    {
        return 'Anlagen und Spielbetrieb';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-arrow-down-tray';
    }

    public static function canAccess(): bool
    {
        return \App\ClubUnio\Resources\ProfileUi::allows('competitions');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->from = now('Europe/Zurich')->toDateString();
        $this->to = now('Europe/Zurich')->addDays(30)->toDateString();
    }

    public function updated(): void
    {
        $this->preview = null;
        $this->batchPreview = null;
        $this->resetValidation();
    }

    private function validateRange(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
    }

    public function refreshSchedule(): void
    {
        abort_unless(\App\ClubUnio\Resources\ProfileUi::allows('competitions', 'sync'), 403);
        $this->validateRange();
        $this->preview = null;
        $this->batchPreview = null;
        $count = app(SfvPlanningService::class)->refresh(Filament::auth()->user(), Filament::getTenant(), ['from' => $this->from, 'to' => $this->to]);
        Notification::make()->title($count.' SFV-Spiele aktualisiert')->success()->send();
    }

    public function inspect(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->preview = null;
        $this->batchPreview = null;
        $this->validate(['match' => 'required|integer|min:1', 'target' => 'nullable|integer|min:1', 'separate' => 'boolean']);
        $this->preview = app(SfvCompetitionService::class)->review(Filament::auth()->user(), Filament::getTenant(), (int) $this->match, $this->target ? (int) $this->target : null, $this->separate);
    }

    public function apply(): void
    {
        abort_unless(\App\ClubUnio\Resources\ProfileUi::allows('competitions', 'sync'), 403);
        abort_unless(static::canAccess(), 403);
        abort_unless($this->preview, 422);
        app(SfvCompetitionService::class)->apply(Filament::auth()->user(), Filament::getTenant(), $this->preview);
        $this->preview = null;
        $this->target = '';
        $this->separate = false;
        Notification::make()->title('Wettkampf übernommen')->success()->send();
    }

    public function inspectBatch(): void
    {
        $this->resetValidation();
        $this->validateRange();
        $this->preview = null;
        $this->batchPreview = null;
        $this->batchPreview = app(SfvCompetitionService::class)->previewBatch(Filament::auth()->user(), Filament::getTenant(), $this->from, $this->to);
    }

    public function applyBatch(): void
    {
        abort_unless(\App\ClubUnio\Resources\ProfileUi::allows('competitions', 'sync'), 403);
        abort_unless(static::canAccess(), 403);
        abort_unless($this->batchPreview, 422);
        $count = app(SfvCompetitionService::class)->applyBatch(Filament::auth()->user(), Filament::getTenant(), $this->batchPreview['entries']);
        $this->batchPreview = null;
        Notification::make()->title($count.' Wettkämpfe übernommen')->success()->send();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        if (Validator::make(['from' => $this->from, 'to' => $this->to], ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from'])->fails()) {
            return ['matches' => collect(), 'targets' => collect()];
        }
        $tenant = Filament::getTenant();
        $matches = DB::connection('clubunio')->table('clubora.sfv_schedule')->where('tenant_id', $tenant->id)
            ->whereRaw("(starts_at AT TIME ZONE 'Europe/Zurich')::date BETWEEN ? AND ?", [$this->from, $this->to])->orderBy('starts_at')->limit(501)->get();
        $targets = Competition::where('tenant_id', $tenant->id)->where('active', true)->whereBetween('plays_on', [$this->from, $this->to])->with(['hostRoom', 'guestRoom'])->orderBy('plays_on')->limit(501)->get();

        return compact('matches', 'targets');
    }
}

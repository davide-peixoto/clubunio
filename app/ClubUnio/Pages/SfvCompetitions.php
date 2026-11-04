<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Models\Competition;
use App\ClubUnio\Models\SfvSchedule;
use App\ClubUnio\Resources\ProfileUi;
use App\ClubUnio\Services\SfvCompetitionService;
use App\ClubUnio\Services\SfvPlanningService;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;

class SfvCompetitions extends Page implements HasTable
{
    use LocalizedTitle;
    use InteractsWithTable;

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
        return ProfileUi::allows('competitions');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->from = now('Europe/Zurich')->toDateString();
        $this->to = now('Europe/Zurich')->addDays(30)->toDateString();
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['from', 'to', 'match', 'target', 'separate'], true)) {
            return;
        }
        $this->preview = null;
        $this->batchPreview = null;
        $this->resetValidation();
        if (in_array($property, ['from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->scheduleQuery())
            ->columns([
                TextColumn::make('starts_at')->label('Datum / Zeit')->dateTime('d.m.Y H:i', timezone: 'Europe/Zurich')->sortable(),
                TextColumn::make('title')->label('SFV-Spiel')->searchable()->sortable()->wrap(),
                TextColumn::make('location')->label('Spielort')->searchable()->sortable()->wrap(),
                TextColumn::make('state')->label('Status')->searchable()->sortable()->badge()
                    ->color(fn ($state) => StatusAppearance::palette($state)),
            ])
            ->recordActions([
                Action::make('inspect')->label('Auswählen')->icon('heroicon-o-clipboard-document-check')
                    ->action(function (SfvSchedule $record): void {
                        abort_unless(static::canAccess(), 403);
                        $this->match = (string) $record->getKey();
                        $this->updated('match');
                    }),
            ])
            ->defaultSort('starts_at')
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Keine SFV-Spiele gefunden')
            ->emptyStateDescription(null);
    }

    protected function scheduleQuery(): Builder
    {
        abort_unless(static::canAccess(), 403);
        $query = SfvSchedule::query()->where('tenant_id', Filament::getTenant()->id);

        return $this->hasValidRange()
            ? $query->whereRaw("(starts_at AT TIME ZONE 'Europe/Zurich')::date BETWEEN ? AND ?", [$this->from, $this->to])
            : $query->whereRaw('1 = 0');
    }

    private function hasValidRange(): bool
    {
        return ! Validator::make(['from' => $this->from, 'to' => $this->to], [
            'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
        ])->fails();
    }

    private function validateRange(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
    }

    public function refreshSchedule(): void
    {
        abort_unless(ProfileUi::allows('competitions', 'sync'), 403);
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
        abort_unless(ProfileUi::allows('competitions', 'sync'), 403);
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
        abort_unless(ProfileUi::allows('competitions', 'sync'), 403);
        abort_unless(static::canAccess(), 403);
        abort_unless($this->batchPreview, 422);
        $count = app(SfvCompetitionService::class)->applyBatch(Filament::auth()->user(), Filament::getTenant(), $this->batchPreview['entries']);
        $this->batchPreview = null;
        Notification::make()->title($count.' Wettkämpfe übernommen')->success()->send();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        if (! $this->hasValidRange()) {
            return ['selectedMatch' => null, 'targets' => collect()];
        }
        $tenant = Filament::getTenant();
        $selectedMatch = filter_var($this->match, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
            ? SfvSchedule::where('tenant_id', $tenant->id)->whereKey($this->match)->first()
            : null;
        $targets = Competition::where('tenant_id', $tenant->id)->where('active', true)->whereBetween('plays_on', [$this->from, $this->to])->with(['hostRoom', 'guestRoom'])->orderBy('plays_on')->limit(501)->get();

        return compact('selectedMatch', 'targets');
    }
}

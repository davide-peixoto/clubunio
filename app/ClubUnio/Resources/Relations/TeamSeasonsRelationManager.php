<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Services\SeasonAssignmentTransferService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;

class TeamSeasonsRelationManager extends DossierRelationManager
{
    #[Locked]
    public array $seasonTransferPreview = [];

    protected static string $relationship = 'teamSeasons';

    protected static string $kind = 'teamSeasons';

    protected static ?string $title = 'Mannschaften pro Saison';

    public function table(Table $table): Table
    {
        $table = parent::table($table);
        if (! $this->getOwnerRecord() instanceof Season) {
            return $table;
        }

        return $table->headerActions([...$table->getHeaderActions(), Action::make('transferSeason')
            ->label('In andere Saison übernehmen')->icon('heroicon-o-document-duplicate')->slideOver()->modalWidth('5xl')
            ->modalSubmitActionLabel('Vorschau übernehmen')
            ->modalSubmitAction(fn (Action $action) => $action->disabled(empty($this->seasonTransferPreview) || collect($this->seasonTransferPreview['rows'] ?? [])->contains('state', 'blockiert')))
            ->fillForm(function () {
                $this->seasonTransferPreview = [];

                return ['team_season_ids' => $this->transferTeamOptions()->keys()->all()];
            })
            ->schema([
                Select::make('target_season_id')->label('Zielsaison')->required()->live()
                    ->options(fn () => Season::where('tenant_id', Filament::getTenant()->id)->where('active', true)->whereKeyNot($this->getOwnerRecord()->id)->orderByDesc('name')->pluck('name', 'id'))
                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                        $set('confirmed', false);
                        $this->seasonTransferPreview = [];
                        if ($state && $get('team_season_ids')) {
                            $this->refreshTransferPreview((int) $state, $get('team_season_ids'));
                        }
                    }),
                Select::make('team_season_ids')->label('Mannschaften übernehmen')->multiple()->searchable()->required()->live()
                    ->options(fn () => $this->transferTeamOptions())
                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                        $set('confirmed', false);
                        $this->seasonTransferPreview = [];
                        if ($state && $get('target_season_id')) {
                            $this->refreshTransferPreview((int) $get('target_season_id'), $state);
                        }
                    }),
                View::make('clubunio.season-transfer-preview')->viewData(fn () => ['plan' => $this->seasonTransferPreview]),
                Checkbox::make('confirmed')->label('Vorschau geprüft; neue Zuordnungen übernehmen')->accepted()->required(),
            ])->action(function (array $data) {
                FormMutation::run($this, function () use ($data) {
                    abort_unless(($this->seasonTransferPreview['target'] ?? null) === (int) $data['target_season_id'], 422);
                    $count = app(SeasonAssignmentTransferService::class)->apply(Filament::auth()->user(), Filament::getTenant(), $this->getOwnerRecord()->id, (int) $data['target_season_id'], $this->seasonTransferPreview['token'], $data['team_season_ids']);
                    $this->seasonTransferPreview = [];
                    Notification::make()->title($count.' Zuordnungen übernommen')->success()->send();
                });
            })]);
    }

    private function transferTeamOptions(): \Illuminate\Support\Collection
    {
        return TeamSeason::where('tenant_id', Filament::getTenant()->id)->where('season_id', $this->getOwnerRecord()->id)->where('active', true)->with('team')->get()->mapWithKeys(fn ($team) => [$team->id => $team->label]);
    }

    private function refreshTransferPreview(int $target, array $teams): void
    {
        $this->seasonTransferPreview = app(SeasonAssignmentTransferService::class)->preview(Filament::auth()->user(), Filament::getTenant(), $this->getOwnerRecord()->id, $target, $teams) + ['target' => $target];
    }
}

<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\LegacyTrainingPlan;
use App\ClubUnio\Services\LegacyTrainingPlanImporter;
use App\ClubUnio\Services\HistoricalTrainingPlanAdoption;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LegacyTrainingPlanResource extends CatalogResource
{
    protected static ?string $model = LegacyTrainingPlan::class;

    protected static ?string $modelLabel = 'Altplan';

    protected static ?string $pluralModelLabel = 'Altpläne übernehmen';

    protected static ?string $tenantRelationshipName = 'legacyTrainingPlans';

    public static function getNavigationGroup(): ?string
    {
        return 'Mannschaften';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => LegacyTrainingPlanResource\Pages\ManageRecords::route('/')];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->select('clubunio.legacy_training_plans.*')->selectSub(
            DB::connection('clubunio')->table('clubunio.training_plans')->select('id')
                ->whereColumn('tenant_id', 'clubunio.legacy_training_plans.tenant_id')
                ->whereColumn('legacy_source_id', 'clubunio.legacy_training_plans.id')->limit(1), 'adopted_plan_id');
    }

    public static function filterState(Builder $query, array $data): Builder
    {
        $state = $data['value'] ?? null;
        if (! $state) {
            return $query;
        }
        $native = fn ($q) => $q->selectRaw('1')->from('clubunio.training_plans')
            ->whereColumn('tenant_id', 'clubunio.legacy_training_plans.tenant_id')
            ->whereColumn('legacy_source_id', 'clubunio.legacy_training_plans.id');

        return $state === 'imported'
            ? $query->where(fn ($q) => $q->where('state', 'imported')->orWhereExists($native))
            : $query->where('state', $state)->whereNotExists($native);
    }

    public static function table(Table $table): Table
    {
        $service = fn () => app(LegacyTrainingPlanImporter::class);
        $actor = fn () => Filament::auth()->user();
        $tenant = fn () => Filament::getTenant();

        return $table->columns([TextColumn::make('source_id')->label('Quelle')->sortable(), TextColumn::make('title')->label('Training')->searchable()->wrap()->placeholder(__('Kein fertiger Plan')),
            TextColumn::make('source_username')->label('Ursprünglicher Besitzer')->searchable()->wrap(), TextColumn::make('source_team_name')->label('Ursprüngliches Team')->wrap(),
            TextColumn::make('requires_quality_review')->label('Qualitätsprüfung')->formatStateUsing(fn ($state, $record) => $record->state === 'excluded' ? __('Nicht bewertet') : ($state ? __('Ausstehend') : __('Kriterien vorhanden')))->badge()->color(fn ($state, $record) => $record->state === 'excluded' ? 'gray' : ($state ? 'warning' : 'success')),
            TextColumn::make('state')->label('Übernahme')->badge()->formatStateUsing(fn ($state, $record) => __(['review' => 'Zuordnen', 'excluded' => 'Ausgeschlossen', 'imported' => 'Übernommen'][$record->adopted_plan_id ? 'imported' : $state]))
                ->color(fn ($state, $record) => ['review' => 'warning', 'excluded' => 'gray', 'imported' => 'success'][$record->adopted_plan_id ? 'imported' : $state]),
            TextColumn::make('error_code')->label('Prüfung')->formatStateUsing(fn ($state) => __(['not_success' => 'Fehler/Zwischenstand', 'invalid_contract' => 'Inhalt prüfen'][$state] ?? $state))->placeholder(fn ($record) => $record?->adopted_plan_id ? __('Archiviert') : __('Bereit zur Zuordnung'))])
            ->filters([SelectFilter::make('state')->label('Übernahme')->options(['review' => __('Zuordnen'), 'excluded' => __('Ausgeschlossen'), 'imported' => __('Übernommen')])->query(fn (Builder $query, array $data) => self::filterState($query, $data))])->deferFilters(false)
            ->persistSearchInSession()->persistFiltersInSession()->defaultSort('source_id')->headerActions([
                Action::make('adoptHistorical')->label(__('training.history_auto'))->icon('heroicon-o-arrow-down-tray')->requiresConfirmation()
                    ->visible(fn () => app(HistoricalTrainingPlanAdoption::class)->available($tenant()) && app(\App\ClubUnio\Services\ModulePermissionService::class)->allows($actor(), $tenant(), 'training', 'sync'))
                    ->action(function () use ($actor, $tenant) {
                        $r = app(HistoricalTrainingPlanAdoption::class)->run($actor(), $tenant(), true);
                        Notification::make()->title(__('training.history_done'))->body(__('training.history_counts', ['imported' => $r['imported'], 'existing' => $r['already_imported'], 'unresolved' => $r['unresolved'], 'excluded' => $r['excluded']]))->success()->send();
                    }),
                Action::make('stage')->label('Altbestand prüfen')->icon('heroicon-o-arrow-path')->requiresConfirmation()
                    ->visible(fn () => ! app(HistoricalTrainingPlanAdoption::class)->available($tenant()))->action(function () use ($service, $actor, $tenant) {
                    $counts = $service()->stage($actor(), $tenant());
                    Notification::make()->title(__('Altbestand geprüft'))->body(__(':added neue Prüfeinträge, :changed geänderte Quellen.', ['added' => $counts['added'], 'changed' => $counts['changed']]))->success()->send();
                })])->recordActions([
                    Action::make('inspect')->label('Inhalt ansehen')->icon('heroicon-o-eye')->iconButton()->tooltip(__('Inhalt ansehen'))->slideOver()->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('Schliessen'))->modalContent(fn ($record) => view('clubunio.training.legacy-preview', ['row' => $record])),
                    Action::make('adoptedPlan')->label(__('training.history_open'))->icon('heroicon-o-archive-box')->visible(fn ($record) => (bool) $record->adopted_plan_id)
                        ->url(fn ($record) => route('clubunio.training.show', [$tenant()->slug, $record->adopted_plan_id])),
                    Action::make('import')->label('Zuordnen und übernehmen')->icon('heroicon-o-arrow-down-tray')->visible(fn ($record) => $record->state === 'review' && ! $record->legacy_read_only)
                        ->fillForm(fn ($record) => ['revision' => $record->revision, 'owner_id' => $record->suggested_owner_id, 'team_season_id' => $record->suggested_team_season_id])
                        ->schema([TextInput::make('revision')->label('Version')->readOnly()->required(), Select::make('owner_id')->label('Besitzer')->required()->searchable()->live()->options(fn () => $service()->owners($actor(), $tenant())),
                            Select::make('team_season_id')->label('Mannschaft / Saison')->required()->searchable()->options(fn (Get $get) => $service()->teams($actor(), $tenant(), $get('owner_id') ? (int) $get('owner_id') : null)),
                            Textarea::make('decision_note')->label('Zuordnungsbegründung')->required()->minLength(3)->maxLength(1000),
                            Checkbox::make('schematic_confirmed')->label('Die Originalgrafiken werden durch ausdrücklich schematische Grafiken ersetzt.')->accepted()])
                        ->action(function ($record, array $data, $livewire) use ($service, $actor, $tenant) {
                            $id = FormMutation::run($livewire, fn () => $service()->import($actor(), $tenant(), $record->id, $data));
                            $livewire->redirect(route('clubunio.training.show', [$tenant()->slug, $id]));
                        }),
                ]);
    }
}

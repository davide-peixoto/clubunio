<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\LegacyTrainingPlan;
use App\ClubUnio\Services\LegacyTrainingPlanImporter;
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

    public static function table(Table $table): Table
    {
        $service = fn () => app(LegacyTrainingPlanImporter::class);
        $actor = fn () => Filament::auth()->user();
        $tenant = fn () => Filament::getTenant();

        return $table->columns([TextColumn::make('source_id')->label('Quelle')->sortable(), TextColumn::make('title')->label('Training')->searchable()->wrap()->placeholder('Kein fertiger Plan'),
            TextColumn::make('source_username')->label('Ursprünglicher Besitzer')->searchable()->wrap(), TextColumn::make('source_team_name')->label('Ursprüngliches Team')->wrap(),
            TextColumn::make('requires_quality_review')->label('Qualitätsprüfung')->formatStateUsing(fn ($state, $record) => $record->state === 'excluded' ? 'Nicht bewertet' : ($state ? 'Ausstehend' : 'Kriterien vorhanden'))->badge()->color(fn ($state, $record) => $record->state === 'excluded' ? 'gray' : ($state ? 'warning' : 'success')),
            TextColumn::make('state')->label('Übernahme')->badge()->formatStateUsing(fn ($state) => ['review' => 'Zuordnen', 'excluded' => 'Ausgeschlossen', 'imported' => 'Übernommen'][$state])
                ->color(fn ($state) => ['review' => 'warning', 'excluded' => 'gray', 'imported' => 'success'][$state]),
            TextColumn::make('error_code')->label('Prüfung')->formatStateUsing(fn ($state) => ['not_success' => 'Fehler/Zwischenstand', 'invalid_contract' => 'Inhalt prüfen'][$state] ?? $state)->placeholder('Bereit zur Zuordnung')])
            ->filters([SelectFilter::make('state')->label('Übernahme')->options(['review' => 'Zuordnen', 'excluded' => 'Ausgeschlossen', 'imported' => 'Übernommen'])])->deferFilters(false)
            ->persistSearchInSession()->persistFiltersInSession()->defaultSort('source_id')->headerActions([
                Action::make('stage')->label('Altbestand prüfen')->icon('heroicon-o-arrow-path')->requiresConfirmation()->action(function () use ($service, $actor, $tenant) {
                    $counts = $service()->stage($actor(), $tenant());
                    Notification::make()->title('Altbestand geprüft')->body($counts['added'].' neue Prüfeinträge, '.$counts['changed'].' geänderte Quellen.')->success()->send();
                })])->recordActions([
                    Action::make('inspect')->label('Inhalt ansehen')->icon('heroicon-o-eye')->iconButton()->tooltip('Inhalt ansehen')->slideOver()->modalSubmitAction(false)
                        ->modalCancelActionLabel('Schliessen')->modalContent(fn ($record) => view('clubunio.training.legacy-preview', ['row' => $record])),
                    Action::make('import')->label('Zuordnen und übernehmen')->icon('heroicon-o-arrow-down-tray')->visible(fn ($record) => $record->state === 'review')
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

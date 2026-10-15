<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\Team;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Resources\ContributionRuleResource;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Resources\TeamPlayerResource;
use App\ClubUnio\Resources\TeamSeasonResource;
use App\ClubUnio\Resources\TeamStaffResource;
use App\ClubUnio\Resources\TechnicalLeadResource;
use App\ClubUnio\Resources\TrainingSlotResource;
use App\ClubUnio\Services\ContributionTrainingService;
use App\ClubUnio\Services\FamilyService;
use App\ClubUnio\Services\TeamAssignmentService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Parent-bound actions reuse the same validation and audit as the standalone resources. */
trait AssignmentRelationActions
{
    private function assignmentResource(): ?string
    {
        return match (static::$kind) {
            'teamSeasons' => TeamSeasonResource::class,
            'teamPlayers' => TeamPlayerResource::class,
            'teamStaff' => TeamStaffResource::class,
            'technicalLeads' => TechnicalLeadResource::class,
            'contributionRules' => ContributionRuleResource::class,
            'trainingSlots' => TrainingSlotResource::class,
            default => null,
        };
    }

    private function assignmentParentField(): ?string
    {
        return match (true) {
            $this->getOwnerRecord() instanceof Season => 'season_id',
            $this->getOwnerRecord() instanceof Team => 'team_id',
            $this->getOwnerRecord() instanceof TeamSeason => 'team_season_id',
            $this->getOwnerRecord() instanceof Member => 'member_id',
            default => null,
        };
    }

    private function assignmentSchema(bool $copy = false): array
    {
        $resource = $this->assignmentResource();

        return array_map(function ($component) use ($copy) {
            if ($component->getName() === $this->assignmentParentField()) {
                return Hidden::make($component->getName())->default($this->getOwnerRecord()->id);
            }
            if ($copy) {
                $component->disabled(false);
            }

            return $component;
        }, $resource::form(Schema::make($this))->getComponents(withHidden: true));
    }

    private function assignmentData(Model $record, bool $copy = false): array
    {
        $fields = array_map(fn ($field) => $field->getName(), $this->assignmentSchema());
        $data = $record->only($fields);
        $data['_version'] = FamilyService::version($record);
        if (in_array(static::$kind, ['contributionRules', 'trainingSlots'], true)) {
            unset($data['_version']);
        }
        if ($copy) {
            unset($data['_version'], $data['revision']);
            $data['active'] = true;
        }

        return $data;
    }

    public function saveAssignment(?int $id, array $data): Model
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant && $this->assignmentResource() && $this->assignmentParentField(), 403);

        return DB::connection('clubunio')->transaction(function () use ($tenant, $id, $data) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $actor = Filament::auth()->user()?->fresh();
            abort_unless($actor && $tenant && $actor->canAccessTenant($tenant)
                && (int) $this->getOwnerRecord()->tenant_id === (int) $tenant->id, 403);
            // A submitted identifier must still belong to this exact dossier, not just the tenant.
            if ($id !== null) {
                $this->getRelationship()->where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($id);
            }
            $data[$this->assignmentParentField()] = $this->getOwnerRecord()->id;
            $resource = $this->assignmentResource();
            $service = in_array(static::$kind, ['contributionRules', 'trainingSlots'], true)
                ? ContributionTrainingService::class : TeamAssignmentService::class;

            return app($service)->save($actor, $tenant, $resource::getModel(), $id, $data);
        });
    }

    protected function withAssignmentActions(Table $table): Table
    {
        if (! $this->assignmentResource() || ! $this->assignmentParentField()) {
            return $table;
        }
        $schema = fn () => $this->assignmentSchema();
        $save = fn (?int $id, array $data) => FormMutation::run($this, fn () => $this->saveAssignment($id, $data));

        return $table->headerActions([
            Action::make('addAssignment')->label('Hinzufügen')->icon('heroicon-o-plus')
                ->schema($schema)->action(fn (array $data) => $save(null, $data)),
        ])->recordActions([
            ...$table->getRecordActions(),
            Action::make('editAssignment')->label('Bearbeiten')->icon('heroicon-o-pencil-square')->iconButton()->tooltip('Bearbeiten')
                ->schema($schema)->fillForm(fn (Model $record) => $this->assignmentData($record))
                ->action(fn (Model $record, array $data) => $save($record->id, $data)),
            Action::make('copyAssignment')->label('Kopieren')->icon('heroicon-o-document-duplicate')->iconButton()->tooltip('Kopieren')
                ->schema(fn () => $this->assignmentSchema(true))->fillForm(fn (Model $record) => $this->assignmentData($record, true))
                ->action(fn (array $data) => $save(null, $data)),
            Action::make('deactivateAssignment')->label('Deaktivieren')->icon('heroicon-o-archive-box')->color('danger')->iconButton()->tooltip('Deaktivieren')
                ->visible(fn (Model $record) => $record->active)->requiresConfirmation()
                ->modalDescription('Die Zuordnung wird deaktiviert. Bestehende Belege und die Historie bleiben erhalten.')
                ->schema([Hidden::make('_version'), Hidden::make('revision')])
                ->fillForm(fn (Model $record) => $this->assignmentData($record))
                ->action(function (Model $record, array $data) use ($save) {
                    $values = $this->assignmentData($record);
                    $version = array_key_exists('revision', $values) ? 'revision' : '_version';
                    $values[$version] = $data[$version] ?? null;
                    $save($record->id, ['active' => false] + $values);
                }),
        ]);
    }
}

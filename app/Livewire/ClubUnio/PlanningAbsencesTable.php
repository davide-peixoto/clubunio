<?php

namespace App\Livewire\ClubUnio;

use App\ClubUnio\Models\PlanningAbsence;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Resources\ProfileUi;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\ModulePermissionService;
use App\ClubUnio\Services\StatusAppearance;
use App\ClubUnio\Services\TeamPlanningService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;

class PlanningAbsencesTable extends WorkspaceScopedTable
{
    protected function absences(): Builder
    {
        $tenant = $this->authorizedTenant();

        $service = app(TeamPlanningService::class);
        $query = $service->absences(auth('clubunio')->user()->fresh(), $tenant)->select('clubora.team_absences.*');
        $query->selectSub($service->table($tenant, 'members')
            ->whereColumn('clubora.members.id', 'clubora.team_absences.member_id')
            ->selectRaw("concat(first_name, ' ', last_name)"), 'member_name');

        return PlanningAbsence::query()->setQuery($query);
    }

    protected function context(): array
    {
        $tenant = $this->authorizedTenant();
        $actor = auth('clubunio')->user()->fresh();
        $service = app(TeamPlanningService::class);
        $service->enter($actor, $tenant);
        $own = app(ModulePermissionService::class)->isProfile($actor, $tenant)
            ? collect() : app(MemberAccessService::class)->represented($actor, $tenant)->get();
        $options = $service->options($tenant);
        $profileAllows = fn (string $function) => ProfileUi::profileAllows($actor, $tenant, 'planning', $function);
        $workspaceToken = Crypt::encryptString(json_encode($this->workspaceContext));

        return compact('tenant', 'actor', 'own', 'options', 'profileAllows', 'workspaceToken');
    }

    public function table(Table $table): Table
    {
        $context = $this->context();
        $kinds = collect($context['options']['absence'] ?? [])->pluck('name', 'code');
        $service = app(TeamPlanningService::class);
        $editable = $context['profileAllows']('update')
            ? $service->absenceMembers($context['actor'], $context['tenant'], 'update')
                ->whereIn('clubora.members.id', $service->absences($context['actor'], $context['tenant'])->select('member_id'))->pluck('id')->all()
            : [];

        return $table->query(fn () => $this->absences())
            ->columns([
                TextColumn::make('member_name')->label('Mitglied'),
                TextColumn::make('starts_on')->label('Von')->date('d.m.Y')->sortable(),
                TextColumn::make('ends_on')->label('Bis')->date('d.m.Y')->sortable(),
                TextColumn::make('kind')->label('Art')->badge()->formatStateUsing(fn ($state) => $kinds[$state] ?? $state)->color(fn ($state) => StatusAppearance::palette($kinds[$state] ?? $state)),
                TextColumn::make('note')->label('Bemerkung')->state(fn ($record) => $record->note_encrypted ? Crypt::decryptString($record->note_encrypted) : '')->wrap(),
                ViewColumn::make('actions')->label('')->view('livewire.clubunio.workspace-absence-actions')->viewData($context)
                    ->visible(fn () => $context['own']->isNotEmpty() && $context['profileAllows']('deactivate')),
            ])
            ->headerActions([
                Action::make('create')->label('Absenz erfassen')->icon('heroicon-o-plus')->slideOver()
                    ->visible(fn () => $context['profileAllows']('create')
                        && $service->absenceMembers($context['actor'], $context['tenant'], 'create')->exists())
                    ->schema(fn () => $this->absenceSchema(false))->fillForm(['revision' => 0, 'kind' => 'ABWESEND'])
                    ->action(fn (array $data) => $this->storeAbsence(null, $data)),
            ])->recordActions([
                Action::make('edit')->label('Bearbeiten')->icon('heroicon-o-pencil-square')->iconButton()->tooltip('Absenz bearbeiten')->slideOver()
                    ->visible(fn ($record) => in_array($record->member_id, $editable, true))
                    ->schema(fn () => $this->absenceSchema(true))
                    ->fillForm(fn ($record) => ['member_id' => $record->member_id, 'revision' => $record->revision,
                        'starts_on' => $record->starts_on, 'ends_on' => $record->ends_on, 'kind' => $record->kind,
                        'note' => $record->note_encrypted ? Crypt::decryptString($record->note_encrypted) : ''])
                    ->action(fn ($record, array $data) => $this->storeAbsence($record->id, $data)),
            ])
            ->defaultSort('starts_on', 'desc')->paginationPageOptions([10, 25, 50, 100])->defaultPaginationPageOption(25)
            ->emptyStateHeading('Keine Absenzen vorhanden.')->emptyStateDescription(null);
    }

    private function absenceSchema(bool $edit): array
    {
        $context = $this->context();
        $function = $edit ? 'update' : 'create';
        abort_unless($context['profileAllows']($function), 403);
        $members = fn () => app(TeamPlanningService::class)->absenceMembers($context['actor'], $this->authorizedTenant(), $function);

        return [
            Hidden::make('revision')->required(),
            Select::make('member_id')->label('Mitglied')->required()->searchable()->disabled($edit)->dehydrated()
                ->getSearchResultsUsing(fn (string $search) => $members()->where(fn ($query) => $query
                    ->where('first_name', 'ilike', '%'.mb_substr($search, 0, 100).'%')
                    ->orWhere('last_name', 'ilike', '%'.mb_substr($search, 0, 100).'%'))
                    ->orderBy('last_name')->orderBy('first_name')->limit(50)->get()->pluck('display_name', 'id')->all())
                ->getOptionLabelUsing(fn ($value) => $value ? $members()->whereKey($value)->first()?->display_name : null),
            Select::make('kind')->label('Art')->required()->options(collect($context['options']['absence'] ?? [])->where('active', true)->pluck('name', 'code')->all()),
            DatePicker::make('starts_on')->label('Von')->required(),
            DatePicker::make('ends_on')->label('Bis')->required()->afterOrEqual('starts_on'),
            Textarea::make('note')->label('Bemerkung')->maxLength(1000),
        ];
    }

    private function storeAbsence(?int $id, array $data): void
    {
        $context = $this->context();
        $result = FormMutation::run($this, fn () => app(TeamPlanningService::class)
            ->saveAbsence($context['actor'], $context['tenant'], $id, $data));
        if ($result) {
            $this->flushCachedTableRecords();
            Notification::make()->success()->title('Absenz gespeichert.')
                ->body($result['skipped'] ? $result['skipped'].' fristgesperrte Termine blieben unverändert.' : null)->send();
        }
    }

    public function render()
    {
        $this->context();

        return view('livewire.clubunio.workspace-absences-table');
    }
}

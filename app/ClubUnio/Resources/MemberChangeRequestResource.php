<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\MemberChangeRequest;
use App\ClubUnio\Models\MemberChangeStatus;
use App\ClubUnio\Services\MemberChangeService;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MemberChangeRequestResource extends CatalogResource
{
    protected static ?string $model = MemberChangeRequest::class;

    protected static ?string $modelLabel = 'Mitgliederänderungsantrag';

    protected static ?string $pluralModelLabel = 'Mitgliederänderungsanträge';

    protected static ?string $tenantRelationshipName = 'memberChangeRequests';

    public static function getNavigationGroup(): ?string
    {
        return 'Verein';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $comparison = fn (MemberChangeRequest $record) => view('clubunio.members.change-comparison', ['rows' => app(MemberChangeService::class)->comparison($record, Filament::getTenant()), 'note' => $record->decision_note]);

        return $table->columns([
            TextColumn::make('member.display_name')->label('Mitglied')->searchable(query: fn ($query, $search) => $query->whereHas('member', fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'ilike', '%'.$search.'%')->orWhere('last_name', 'ilike', '%'.$search.'%')->orWhere('company', 'ilike', '%'.$search.'%')))),
            TextColumn::make('requester.display_name')->label('Antragsteller'),
            TextColumn::make('status.name')->label('Status')->badge()->color(fn ($state) => StatusAppearance::palette($state)),
            TextColumn::make('created_at')->label('Eingereicht')->dateTime('d.m.Y H:i', 'Europe/Zurich')->sortable(),
            TextColumn::make('reviewed_at')->label('Entscheid')->dateTime('d.m.Y H:i', 'Europe/Zurich'),
        ])->filters([SelectFilter::make('state')->label('Status')->options(fn () => MemberChangeStatus::where('tenant_id', Filament::getTenant()->id)->orderBy('sort_order')->pluck('name', 'code'))->default('pending')])
            ->recordActions([
                Action::make('compare')->label('Vergleichen')->icon('heroicon-o-eye')->iconButton()->tooltip('Änderungen vergleichen')->slideOver()->modalContent($comparison)->modalSubmitAction(false),
                ...array_map(fn ($approve) => Action::make($approve ? 'approve' : 'reject')->label($approve ? 'Genehmigen' : 'Ablehnen')
                    ->icon($approve ? 'heroicon-o-check' : 'heroicon-o-x-mark')->color($approve ? 'success' : 'danger')->iconButton()->tooltip($approve ? 'Genehmigen' : 'Ablehnen')
                    ->visible(fn ($record) => $record->state === 'pending')->slideOver()->modalContent($comparison)
                    ->fillForm(fn ($record) => ['revision' => $record->revision])
                    ->schema([Hidden::make('revision')->required(), Textarea::make('note')->label('Rückmeldung')->required(! $approve)->maxLength(2000)])
                    ->action(fn ($record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(MemberChangeService::class)->review(Filament::auth()->user(), Filament::getTenant(), $record->id, (int) $data['revision'], $approve, $data['note'] ?? null))), [true, false]),
            ])->defaultSort('created_at', 'desc')->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => MemberChangeRequestResource\Pages\ListRequests::route('/')];
    }
}

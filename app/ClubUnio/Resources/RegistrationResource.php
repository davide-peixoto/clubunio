<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Registration;
use App\ClubUnio\Models\RegistrationStatus;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Services\MemberService;
use App\ClubUnio\Services\RegistrationDataService;
use App\ClubUnio\Services\RegistrationReviewService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RegistrationResource extends CatalogResource
{
    protected static ?string $model = Registration::class;

    protected static ?string $modelLabel = 'Mitgliederanmeldung';

    protected static ?string $pluralModelLabel = 'Mitgliederanmeldungen';

    protected static ?string $tenantRelationshipName = 'registrations';

    public static function getNavigationGroup(): ?string
    {
        return 'Verein';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-inbox';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('legacy_id')->label('Anmeldung-Nr.'), TextEntry::make('status.name')->label('Status')->badge(),
            TextEntry::make('first_name')->label('Vorname'), TextEntry::make('last_name')->label('Nachname'),
            TextEntry::make('birth_date')->label('Geburtsdatum')->date('d.m.Y'), TextEntry::make('city')->label('Ort'),
            TextEntry::make('email')->label('E-Mail'), TextEntry::make('submitted_at')->label('Eingereicht')->dateTime('d.m.Y H:i'),
            TextEntry::make('teamSeason.label')->label('Mannschaft / Saison')->placeholder('Nicht zugeordnet')->columnSpanFull(),
            TextEntry::make('technicalLead.display_name')->label('Technischer Leiter')->placeholder('Nicht zugeordnet'),
            TextEntry::make('reviewed_at')->label('Zuletzt geprüft')->dateTime('d.m.Y H:i'),
            ...array_map(fn ($field, $label) => TextEntry::make('applicant_'.$field)->label($label)
                ->state(fn (Registration $record) => app(RegistrationDataService::class)->formValues($record)[$field] ?? null)->placeholder('–'),
                ['street', 'house_number', 'postal_code', 'mobile', 'mother_name', 'mother_email', 'father_name', 'father_email', 'previous_club', 'notes'],
                ['Strasse', 'Hausnummer', 'PLZ', 'Mobile', 'Mutter', 'E-Mail Mutter', 'Vater', 'E-Mail Vater', 'Vorverein', 'Bemerkung']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->headerActions([
            Action::make('newRegistration')->label('Anmeldung erfassen')->icon('heroicon-o-plus')->slideOver()->schema([Grid::make(2)->schema(RegistrationFields::applicant())])
                ->action(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => app(RegistrationDataService::class)->save(Filament::auth()->user(), Filament::getTenant(), null, $data))),
        ])->columns([
            TextColumn::make('legacy_id')->label('Nr.')->sortable(),
            TextColumn::make('first_name')->label('Vorname')->searchable()->sortable(),
            TextColumn::make('last_name')->label('Nachname')->searchable()->sortable(),
            TextColumn::make('status.name')->label('Status')->badge()->sortable(),
            TextColumn::make('teamSeason.label')->label('Mannschaft / Saison')->wrap()->placeholder('Nicht zugeordnet'),
            TextColumn::make('technicalLead.display_name')->label('Technischer Leiter')->placeholder('Nicht zugeordnet'),
            TextColumn::make('submitted_at')->label('Eingereicht')->dateTime('d.m.Y H:i')->sortable(),
        ])->filters([
            SelectFilter::make('status_id')->label('Status')->options(fn () => RegistrationStatus::where('tenant_id', Filament::getTenant()->id)->orderBy('sort_order')->pluck('name', 'id')),
            SelectFilter::make('team_season_id')->label('Mannschaft / Saison')->searchable()->options(fn () => TeamSeason::where('tenant_id', Filament::getTenant()->id)->orderBy('label')->pluck('label', 'id')),
        ])->recordActions([ViewAction::make()->slideOver()->iconButton()->tooltip('Details'),
            Action::make('messages')->label('Benachrichtigungen')->icon('heroicon-o-envelope')->iconButton()->tooltip('Benachrichtigungen')->slideOver()
                ->modalContent(fn (Registration $record) => view('clubunio.registration-messages', [
                    'events' => DB::connection('clubunio')->table('clubora.registration_events')
                        ->where('tenant_id', Filament::getTenant()->id)->where('registration_id', $record->id)
                        ->orderByDesc('id')->limit(100)->get(['event_key', 'revision', 'delivery_state', 'created_at', 'finished_at', 'error_code']),
                ]))->modalSubmitAction(false)->modalCancelActionLabel('Schliessen'),
            Action::make('documents')->label('Dokumente')->icon('heroicon-o-paper-clip')->iconButton()->tooltip('Dokumente')->slideOver()
                ->modalContent(fn (Registration $record) => view('clubunio.registration-documents', [
                    'tenant' => Filament::getTenant(), 'documents' => DB::connection('clubunio')->table('clubora.registration_documents')
                        ->where('tenant_id', Filament::getTenant()->id)->where('registration_id', $record->id)->where('active', true)->get(),
                ]))->modalSubmitAction(false)->modalCancelActionLabel('Schliessen'),
            Action::make('applicant')->label('Formulardaten')->icon('heroicon-o-user')->iconButton()->tooltip('Formulardaten')->slideOver()
                ->visible(fn (Registration $record) => $record->active && $record->status?->workflow_key !== 'accepted')
                ->fillForm(fn (Registration $record) => app(RegistrationDataService::class)->formValues($record))
                ->schema([Grid::make(2)->schema(RegistrationFields::applicant())])
                ->action(fn (Registration $record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(RegistrationDataService::class)->save(Filament::auth()->user(), Filament::getTenant(), $record->id, $data))),
            Action::make('review')->label('Prüfung bearbeiten')->icon('heroicon-o-pencil-square')->iconButton()->tooltip('Prüfung bearbeiten')->slideOver()
                ->visible(fn (Registration $record) => $record->active && $record->status?->workflow_key !== 'accepted')
                ->fillForm(fn (Registration $record) => $record->only([...RegistrationReviewService::FIELDS, 'revision', 'member_id']) + [
                    'member_version' => $record->member ? MemberService::version($record->member) : null, 'member_type_id' => $record->member?->type_id,
                ])
                ->schema([
                    Select::make('status_id')->label('Status')->required()->options(fn () => RegistrationStatus::where('tenant_id', Filament::getTenant()->id)
                        ->where('active', true)->whereIn('workflow_key', ['review', 'trial', 'accepted', 'rejected', 'pool'])->orderBy('sort_order')->pluck('name', 'id')),
                    Select::make('team_season_id')->label('Mannschaft / Saison')->searchable()->options(fn () => TeamSeason::where('tenant_id', Filament::getTenant()->id)
                        ->where('active', true)->whereHas('team', fn ($q) => $q->where('active', true))->orderBy('label')->pluck('label', 'id')),
                    Select::make('technical_lead_member_id')->label('Technischer Leiter')->searchable()->options(fn () => app(RegistrationReviewService::class)
                        ->leaders(Filament::getTenant())->orderBy('last_name')->get()->pluck('display_name', 'id')),
                    Textarea::make('reason')->label('Prüfvermerk')->required()->minLength(10)->maxLength(500),
                    Hidden::make('revision'),
                    ...RegistrationFields::acceptance(),
                ])
                ->action(fn (Registration $record, array $data, $livewire) => FormMutation::run($livewire,
                    fn () => app(RegistrationReviewService::class)->review(Filament::auth()->user(), Filament::getTenant(), $record->id, $data))),
        ])
            ->deferFilters(false)->persistFiltersInSession()->persistSearchInSession()->defaultSort('legacy_id', 'desc')->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => RegistrationResource\Pages\ListRegistrations::route('/')];
    }
}

<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\BillingRecipient;
use App\ClubUnio\Models\Country;
use App\ClubUnio\Models\Gender;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberStatus;
use App\ClubUnio\Models\MemberType;
use App\ClubUnio\Services\MemberService;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    protected static ?string $modelLabel = 'Mitglied';

    protected static ?string $pluralModelLabel = 'Mitglieder';

    protected static ?string $tenantRelationshipName = 'members';

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-user-group';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Verein';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()?->id ?? -1)->whereNull('archived_at');
    }

    public static function canCreate(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant && (Filament::auth()->user()?->canAccessTenant($tenant) ?? false);
    }

    public static function canEdit(Model $record): bool
    {
        return static::canCreate() && ! $record->archived_at && (int) $record->tenant_id === (int) Filament::getTenant()->id;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $lookup = fn (string $field, string $label, string $model) => Select::make($field)->label($label)->searchable()
            ->options(fn (?Member $record) => static::lookupOptions($model, $record?->$field));

        return $schema->components([
            Hidden::make('_version'),
            Tabs::make('Mitglied')->columnSpanFull()->tabs([
                Tab::make('Person')->columns(2)->schema([
                    TextInput::make('company')->label('Firma')->maxLength(255)->live(onBlur: true)->columnSpanFull(),
                    TextInput::make('first_name')->label('Vorname')->maxLength(255)->required(fn (Get $get) => blank($get('company'))),
                    TextInput::make('last_name')->label('Nachname')->maxLength(255)->required(fn (Get $get) => blank($get('company'))),
                    DatePicker::make('birth_date')->label('Geburtsdatum')->maxDate(now()),
                    $lookup('gender_id', 'Geschlecht', Gender::class),
                    $lookup('nationality_id', 'Nationalität', Country::class),
                    TextInput::make('pass_number')->label('Passnummer')->maxLength(30),
                ]),
                Tab::make('Kontakt')->columns(2)->schema([
                    TextInput::make('street')->label('Strasse')->maxLength(255),
                    TextInput::make('house_number')->label('Hausnummer')->maxLength(20),
                    TextInput::make('postal_code')->label('PLZ')->maxLength(20),
                    TextInput::make('city')->label('Ort')->maxLength(255), $lookup('country_id', 'Land', Country::class),
                    TextInput::make('email')->label('E-Mail')->email()->maxLength(254),
                    TextInput::make('mobile')->label('Mobile')->tel()->maxLength(50),
                    TextInput::make('phone_private')->label('Telefon privat')->tel()->maxLength(50),
                    TextInput::make('phone_business')->label('Telefon Geschäft')->tel()->maxLength(50),
                    TextInput::make('website')->label('Webseite')->url()->maxLength(255),
                ]),
                Tab::make('Eltern & Rechnung')->columns(2)->schema([
                    TextInput::make('mother_name')->label('Name Mutter')->maxLength(255),
                    TextInput::make('father_name')->label('Name Vater')->maxLength(255),
                    TextInput::make('mother_email')->label('E-Mail Mutter')->email()->maxLength(254),
                    TextInput::make('father_email')->label('E-Mail Vater')->email()->maxLength(254),
                    TextInput::make('mother_mobile')->label('Mobile Mutter')->tel()->maxLength(50),
                    TextInput::make('father_mobile')->label('Mobile Vater')->tel()->maxLength(50),
                    $lookup('billing_recipient_id', 'Rechnung an', BillingRecipient::class)->required(fn (?Member $record) => $record === null),
                ]),
                Tab::make('Mitgliedschaft')->columns(2)->schema([
                    $lookup('status_id', 'Status', MemberStatus::class)->required(fn (?Member $record) => $record === null),
                    $lookup('type_id', 'Mitgliederart', MemberType::class)->required(fn (?Member $record) => $record === null),
                    DatePicker::make('exit_date')->label('Austrittsdatum'),
                    Toggle::make('honorary')->label('Ehrenmitglied')->default(false),
                    Toggle::make('free_membership')->label('Freimitglied')->default(false),
                    Toggle::make('supporter')->label('Supporter')->default(false),
                    Toggle::make('sponsor')->label('Sponsor')->default(false),
                    Toggle::make('spots')->label('Spots')->default(false),
                ]),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $entries = [];
        foreach (['member_number' => 'Mitglied-Nr.', 'company' => 'Firma', 'first_name' => 'Vorname', 'last_name' => 'Nachname',
            'status.name' => 'Status', 'type.name' => 'Mitgliederart', 'gender.name' => 'Geschlecht', 'nationality.name' => 'Nationalität',
            'street' => 'Strasse', 'house_number' => 'Hausnummer', 'postal_code' => 'PLZ', 'city' => 'Ort', 'country.name' => 'Land',
            'email' => 'E-Mail', 'mobile' => 'Mobile', 'phone_private' => 'Telefon privat', 'phone_business' => 'Telefon Geschäft',
            'website' => 'Webseite', 'pass_number' => 'Passnummer', 'mother_name' => 'Mutter', 'mother_email' => 'E-Mail Mutter',
            'mother_mobile' => 'Mobile Mutter', 'father_name' => 'Vater', 'father_email' => 'E-Mail Vater', 'father_mobile' => 'Mobile Vater',
            'billingRecipient.name' => 'Rechnung an'] as $field => $label) {
            $entries[] = TextEntry::make($field)->label($label)->placeholder('–');
        }
        foreach (['birth_date' => 'Geburtsdatum', 'exit_date' => 'Austrittsdatum'] as $field => $label) {
            $entries[] = TextEntry::make($field)->label($label)->date('d.m.Y');
        }
        foreach (['honorary' => 'Ehrenmitglied', 'free_membership' => 'Freimitglied', 'supporter' => 'Supporter', 'sponsor' => 'Sponsor', 'spots' => 'Spots'] as $field => $label) {
            $entries[] = IconEntry::make($field)->label($label)->boolean();
        }

        return $schema->columns(2)->components($entries);
    }

    public static function lookupOptions(string $model, ?int $current): array
    {
        return $model::where('tenant_id', Filament::getTenant()?->id ?? -1)
            ->where(fn ($q) => $q->where('active', true)->orWhere('id', $current ?? -1))
            ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function memberSelect(string $field): Select
    {
        return Select::make($field)->label('Mitglied')->searchable()
            ->getSearchResultsUsing(fn (string $search) => Member::where('tenant_id', Filament::getTenant()?->id ?? -1)->whereNull('archived_at')
                ->where(fn ($q) => $q->where('first_name', 'ilike', '%'.$search.'%')->orWhere('last_name', 'ilike', '%'.$search.'%')->orWhere('company', 'ilike', '%'.$search.'%'))
                ->orderBy('last_name')->limit(50)->get()->pluck('display_name', 'id'))
            ->getOptionLabelUsing(fn ($value) => Member::where('tenant_id', Filament::getTenant()?->id ?? -1)->find($value)?->display_name);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('member_number')->label('Mitglied-Nr.')->sortable()->searchable(),
            TextColumn::make('first_name')->label('Vorname')->searchable()->sortable(),
            TextColumn::make('last_name')->label('Nachname')->searchable()->sortable(),
            TextColumn::make('company')->label('Firma')->searchable()->toggleable(),
            TextColumn::make('email')->label('E-Mail')->searchable()->wrap(),
            TextColumn::make('city')->label('Ort')->searchable(),
            TextColumn::make('mobile')->label('Mobile')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('status.name')->label('Status')->badge()->sortable(),
            TextColumn::make('type.name')->label('Mitgliederart')->sortable(),
            TextColumn::make('exit_date')->label('Austritt')->date('d.m.Y')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('status_id')->label('Status')->options(fn () => MemberStatus::where('tenant_id', Filament::getTenant()->id)->orderBy('sort_order')->pluck('name', 'id')),
            SelectFilter::make('type_id')->label('Mitgliederart')->options(fn () => MemberType::where('tenant_id', Filament::getTenant()->id)->orderBy('sort_order')->pluck('name', 'id')),
            SelectFilter::make('country_id')->label('Land')->searchable()->options(fn () => Country::where('tenant_id', Filament::getTenant()->id)->orderBy('name')->pluck('name', 'id')),
        ])->headerActions([
            CreateAction::make()->label('Mitglied erfassen')->slideOver()
                ->using(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => app(MemberService::class)->create(Filament::auth()->user(), Filament::getTenant(), $data))),
        ])->recordActions([
            BexioBillingRecipientAction::make()->iconButton()->tooltip('Bexio-Rechnungsempfänger'),
            ViewAction::make()->iconButton()->tooltip('Mitgliederdossier')->url(fn (Member $record) => static::getUrl('view', ['record' => $record])),
            EditAction::make()->slideOver()->iconButton()->tooltip('Mitglied bearbeiten')
                ->mutateRecordDataUsing(fn (array $data, Member $record) => array_merge($data, ['_version' => MemberService::version($record)]))
                ->using(fn (Member $record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(MemberService::class)->update(Filament::auth()->user(), Filament::getTenant(), $record->id, $data))),
        ])->deferFilters(false)->persistFiltersInSession()->defaultSort('last_name')->paginated([25, 50, 100])->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => MemberResource\Pages\ListMembers::route('/'), 'view' => MemberResource\Pages\ViewMember::route('/{record}')];
    }

    public static function getRelations(): array
    {
        return [Relations\InvoicesRelationManager::class, Relations\ExpensesRelationManager::class,
            Relations\DocumentsRelationManager::class, Relations\TeamPlayersRelationManager::class,
            Relations\TeamStaffRelationManager::class, Relations\TechnicalLeadsRelationManager::class,
            Relations\FamiliesRelationManager::class, Relations\BankAccountsRelationManager::class,
            Relations\RegistrationsRelationManager::class, Relations\FunctionsRelationManager::class,
            Relations\NotesRelationManager::class, Relations\KeysRelationManager::class,
            Relations\SponsorshipsRelationManager::class, Relations\EventGuestsRelationManager::class,
            Relations\MaterialReceiptsRelationManager::class, Relations\LegacyMaterialReceiptsRelationManager::class];
    }
}

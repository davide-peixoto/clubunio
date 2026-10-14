<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\AccountMemberLink;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Services\AccountLinkService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AccountMemberLinkResource extends CatalogResource
{
    protected static ?string $model = AccountMemberLink::class;

    protected static ?string $modelLabel = 'Benutzerzuordnung';

    protected static ?string $pluralModelLabel = 'Benutzerzuordnungen';

    protected static ?string $tenantRelationshipName = 'accountMemberLinks';

    public const STATES = ['pending' => 'Zu prüfen', 'approved' => 'Freigegeben', 'revoked' => 'Widerrufen'];

    public const KINDS = ['self' => 'Eigenes Mitglied', 'parent' => 'Elternvertretung'];

    public static function getNavigationGroup(): ?string
    {
        return 'Verwaltung';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function fields(bool $edit): array
    {
        return [
            Select::make('account_id')->label('Benutzer')->required()->searchable()->disabled($edit)->dehydrated()
                ->getSearchResultsUsing(fn (string $search) => Account::whereHas('tenants', fn ($q) => $q->where('clubora.tenants.id', Filament::getTenant()->id))
                    ->where('username', 'ilike', '%'.$search.'%')->limit(30)->pluck('username', 'id'))
                ->getOptionLabelUsing(fn ($value) => Account::whereHas('tenants', fn ($q) => $q->where('clubora.tenants.id', Filament::getTenant()->id))->whereKey($value)->value('username')),
            Select::make('member_id')->label('Mitglied')->required()->searchable()->disabled($edit)->dehydrated()
                ->getSearchResultsUsing(fn (string $search) => Member::where('tenant_id', Filament::getTenant()->id)->whereNull('archived_at')
                    ->where(fn ($q) => $q->where('first_name', 'ilike', '%'.$search.'%')->orWhere('last_name', 'ilike', '%'.$search.'%'))
                    ->limit(30)->get()->pluck('display_name', 'id'))
                ->getOptionLabelUsing(fn ($value) => Member::where('tenant_id', Filament::getTenant()->id)->find($value)?->display_name),
            Select::make('kind')->label('Zuordnung')->options(self::KINDS)->required()->disabled($edit)->dehydrated(),
            Select::make('state')->label('Freigabe')->options(self::STATES)->default('pending')->required(),
            Textarea::make('reason')->label('Prüfvermerk')->required()->minLength(10)->maxLength(500),
            Hidden::make('revision')->dehydrated($edit),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('account.username')->label('Benutzer')->searchable(),
            TextColumn::make('member.display_name')->label('Mitglied'),
            TextColumn::make('kind')->label('Zuordnung')->formatStateUsing(fn ($state) => self::KINDS[$state]),
            TextColumn::make('state')->label('Freigabe')->badge()->formatStateUsing(fn ($state) => self::STATES[$state]),
            TextColumn::make('source')->label('Herkunft')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('reviewed_at')->label('Geprüft')->dateTime('d.m.Y H:i'),
        ])->filters([SelectFilter::make('state')->label('Freigabe')->options(self::STATES), SelectFilter::make('kind')->label('Zuordnung')->options(self::KINDS)])
            ->headerActions([Action::make('newLink')->label('Zuordnung hinzufügen')->icon('heroicon-o-plus')->schema(self::fields(false))
                ->action(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => app(AccountLinkService::class)->save(Filament::auth()->user(), Filament::getTenant(), null, $data)))])
            ->recordActions([Action::make('review')->label('Prüfen')->icon('heroicon-o-shield-check')->iconButton()->tooltip('Zuordnung prüfen')
                ->fillForm(fn (AccountMemberLink $record) => $record->only(['account_id', 'member_id', 'kind', 'state', 'revision']))->schema(self::fields(true))
                ->action(fn (AccountMemberLink $record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(AccountLinkService::class)->save(Filament::auth()->user(), Filament::getTenant(), $record->id, $data)))])
            ->defaultSort('id', 'desc')->deferFilters(false)->persistFiltersInSession()->persistSearchInSession();
    }

    public static function getPages(): array
    {
        return ['index' => AccountMemberLinkResource\Pages\ListAccountMemberLinks::route('/')];
    }
}

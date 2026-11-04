<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Models\AccountMemberLink;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\TenantAccount;
use App\ClubUnio\Resources\AccountMemberLinkResource;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Resources\TenantAccountResource;
use App\ClubUnio\Services\AccountAdministrationService;
use App\ClubUnio\Services\AccountLinkService;
use App\ClubUnio\Services\MemberAccessService;
use App\ClubUnio\Services\MemberPersonalAccessService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PersonalAccessRelationManager extends RelationManager
{
    use LocalizedRelationTitle;
    protected static string $relationship = 'personalAccessLinks';

    protected static ?string $title = 'Benutzerzugriff';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $tenant = Filament::getTenant();

        return $tenant && $ownerRecord instanceof Member
            && (int) $ownerRecord->tenant_id === (int) $tenant->id
            && (Filament::auth()->user()?->fresh()?->hasUnrestrictedBackofficeAccess($tenant) ?? false);
    }

    private function authorizeMember(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->pageClass ?? ''), 403);
        app(MemberPersonalAccessService::class)->authorize(Filament::auth()->user(), Filament::getTenant(), $this->getOwnerRecord());
    }

    public function mount(): void
    {
        $this->authorizeMember();
        parent::mount();
    }

    public function hydrate(): void
    {
        $this->authorizeMember();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    private function scopedLink(int $id): AccountMemberLink
    {
        $this->authorizeMember();

        return $this->getRelationship()->where('tenant_id', Filament::getTenant()->id)->findOrFail($id);
    }

    private function membership(int $link): TenantAccount
    {
        $record = $this->scopedLink($link);
        abort_unless(app(AccountAdministrationService::class)->allowed(Filament::auth()->user(), Filament::getTenant()), 403);

        return TenantAccount::where('tenant_id', Filament::getTenant()->id)->where('account_id', $record->account_id)->with('account')->firstOrFail();
    }

    public function table(Table $table): Table
    {
        $this->authorizeMember();
        $tenant = Filament::getTenant();
        $member = $this->getOwnerRecord()->fresh();
        $active = app(MemberAccessService::class)->activeMembers($tenant)->whereKey($member->id)->exists();

        return $table->modifyQueryUsing(fn ($query) => app(MemberPersonalAccessService::class)->scope($query, $tenant))
            ->columns([
                TextColumn::make('account.display_name')->label('Name')->searchable(),
                TextColumn::make('account.username')->label('Benutzer')->searchable()->wrap(),
                TextColumn::make('kind')->label('Zuordnung')->formatStateUsing(fn ($state) => __(AccountMemberLinkResource::KINDS[$state] ?? $state)),
                TextColumn::make('state')->label('Freigabe')->badge()->formatStateUsing(fn ($state) => __(AccountMemberLinkResource::STATES[$state] ?? $state)),
                TextColumn::make('capability')->label('Persönlicher Zugriff')
                    ->state(fn ($record) => app(MemberPersonalAccessService::class)->capability($record, $member, $active))->wrap(),
            ])->filters([SelectFilter::make('state')->label('Freigabe')->options(array_map(fn ($name) => __($name), AccountMemberLinkResource::STATES))])
            ->recordActions([
                Action::make('review')->label('Zugriff prüfen')->icon('heroicon-o-shield-check')->iconButton()->tooltip(__('Zuordnung prüfen'))->slideOver()
                    ->schema(AccountMemberLinkResource::fields(true))
                    ->fillForm(fn ($record) => $this->scopedLink($record->id)->only(['account_id', 'member_id', 'kind', 'state', 'revision']))
                    ->action(fn ($record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(AccountLinkService::class)
                        ->save(Filament::auth()->user(), Filament::getTenant(), $this->scopedLink($record->id)->id, $data))),
                Action::make('manage')->label('Benutzer bearbeiten')->icon('heroicon-o-pencil-square')->iconButton()->tooltip(__('Benutzer und Rolle bearbeiten'))->slideOver()
                    ->visible(fn () => app(AccountAdministrationService::class)->allowed(Filament::auth()->user(), Filament::getTenant()))
                    ->schema(TenantAccountResource::fields(true))
                    ->fillForm(function ($record) {
                        $membership = $this->membership($record->id);

                        return ['username' => $membership->account->username, 'display_name' => $membership->account->display_name,
                            'active' => $membership->account->active, 'role' => $membership->role, 'access_profile_id' => $membership->access_profile_id,
                            'portal_enabled' => $membership->portal_enabled, 'revision' => $membership->revision, 'account_revision' => $membership->account->revision];
                    })
                    ->action(fn ($record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(AccountAdministrationService::class)
                        ->save(Filament::auth()->user(), Filament::getTenant(), $this->membership($record->id)->id, $data))),
            ])->defaultSort('id', 'desc')->paginated([10, 25, 50])->emptyStateHeading(__('Keine persönlichen Benutzerzuordnungen'))->emptyStateDescription(null);
    }
}

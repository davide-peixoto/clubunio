<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\Team;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Resources\DossierTables;
use App\ClubUnio\Resources\MemberResource;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

abstract class DossierRelationManager extends RelationManager
{
    use AssignmentRelationActions;

    protected static string $kind;

    #[Locked]
    public ?int $seasonContextId = null;

    #[Locked]
    public ?string $financeContextType = null;

    #[Locked]
    public ?int $financeContextId = null;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (int) $ownerRecord->tenant_id === (int) (Filament::getTenant()?->id ?? -1)
            && (Filament::auth()->user()?->can('view', $ownerRecord) ?? false);
    }

    public function mount(): void
    {
        $this->authorizeOwner();
        parent::mount();
    }

    public function hydrate(): void
    {
        $this->authorizeOwner();
    }

    private function authorizeOwner(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->pageClass ?? ''), 403);
        if ($this->financeContextType !== null) {
            abort_unless(in_array($this->financeContextType, [Member::class, Season::class, Team::class, TeamSeason::class], true)
                && in_array(static::$kind, ['invoiceLines', 'expenseLines'], true), 403);
            $parent = $this->financeContextType::where('tenant_id', Filament::getTenant()->id)->find($this->financeContextId);
            abort_unless($parent && Filament::auth()->user()?->fresh()?->can('view', $parent), 403);
            $relation = $parent->{static::$kind === 'invoiceLines' ? 'invoices' : 'expenses'}();
            abort_unless($relation->where($relation->getRelated()->qualifyColumn('tenant_id'), Filament::getTenant()->id)->whereKey($this->getOwnerRecord()->id)->exists(), 403);
        }
        if ($this->seasonContextId !== null) {
            $owner = $this->getOwnerRecord()->fresh();
            abort_unless(in_array(static::$kind, ['invoiceLines', 'expenseLines'], true)
                && $owner && (int) $owner->season_id === $this->seasonContextId
                && (int) $owner->tenant_id === (int) Filament::getTenant()?->id
                && Filament::auth()->user()?->fresh()?->canAccessTenant(Filament::getTenant()), 403);
        }
    }

    protected function financeLinesChanged(): void
    {
        $this->getOwnerRecord()->refresh();
        if ($this->seasonContextId !== null || $this->financeContextType !== null) {
            $this->dispatch('season-finance-updated');
        }
    }

    public function isReadOnly(): bool
    {
        return ! $this->assignmentResource() || ! $this->assignmentParentField();
    }

    public function table(Table $table): Table
    {
        $table = DossierTables::configure($table, static::$kind)->modifyQueryUsing(function ($query) {
            $query->where($query->getModel()->qualifyColumn('tenant_id'), Filament::getTenant()?->id ?? -1);
            if (static::$kind === 'invoices') {
                $query->withSum('activeLines', 'net_amount');
            }
            if (static::$kind === 'expenses') {
                $query->withSum('activeLines', 'amount');
            }
        })->persistFiltersInSession()->persistSearchInSession();
        if (! $this->getOwnerRecord() instanceof Member && in_array(static::$kind, ['teamPlayers', 'teamStaff', 'technicalLeads', 'expenses', 'invoices', 'familyMemberships'], true)) {
            $table->columns(array_merge([
                TextColumn::make('member.display_name')->label('Mitglied')
                    ->url(fn ($record) => MemberResource::getUrl('view', ['record' => $record->member_id]))
                    ->searchable(query: fn ($query, string $search) => $query->whereHas('member', fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'ilike', '%'.$search.'%')->orWhere('last_name', 'ilike', '%'.$search.'%')->orWhere('company', 'ilike', '%'.$search.'%')))),
            ], array_values($table->getColumns())));
        }

        return $this->withAssignmentActions($table);
    }
}

<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Models\Member;
use App\ClubUnio\Resources\DossierTables;
use App\ClubUnio\Resources\MemberResource;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

abstract class DossierRelationManager extends RelationManager
{
    protected static string $kind;

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
    }

    public function isReadOnly(): bool
    {
        return true;
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

        return $table;
    }
}

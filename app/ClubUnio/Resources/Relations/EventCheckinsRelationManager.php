<?php

namespace App\ClubUnio\Resources\Relations;

use App\ClubUnio\Services\ModulePermissionService;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EventCheckinsRelationManager extends RelationManager
{
    protected static string $relationship = 'checkins';
    protected static ?string $title = 'Confirmed attendance';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Confirmed attendance');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $actor = Filament::auth()->user(); $tenant = Filament::getTenant();
        return config('clubunio-event-checkin.enabled', false) && $actor && $tenant
            && (int) $ownerRecord->tenant_id === (int) $tenant->id
            && app(ModulePermissionService::class)->allows($actor->fresh(), $tenant, 'events', 'view');
    }

    public function isReadOnly(): bool { return true; }

    public function table(Table $table): Table
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), static::class), 403);
        return $table->modifyQueryUsing(fn ($query) => $query->where('tenant_id', Filament::getTenant()->id)->with('member'))
            ->columns([
                TextColumn::make('member.display_name')->label(__('Mitglied')),
                IconColumn::make('confirmed')->label(__('Anwesend'))->state(true)->boolean(),
                TextColumn::make('checked_in_at')->label(__('Attendance confirmed at'))->dateTime('d.m.Y H:i:s', 'Europe/Zurich')->sortable(),
            ])->defaultSort('checked_in_at', 'desc')->poll('15s.visible')->headerActions([])->recordActions([])->toolbarActions([]);
    }
}

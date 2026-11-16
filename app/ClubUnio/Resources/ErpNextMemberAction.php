<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Member;
use App\ClubUnio\Services\AccountingProvider;
use App\ClubUnio\Services\ErpNextConfiguration;
use App\ClubUnio\Services\ErpNextMemberSyncService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

class ErpNextMemberAction
{
    public static function make(): Action
    {
        return Action::make('erpnextMemberSync')->label(trans('erpnext_members.sync'))->icon('heroicon-o-arrow-path')
            ->visible(fn (Member $record) => config('clubunio-erpnext.enabled')
                && ErpNextConfiguration::canManage(Filament::auth()->user(), Filament::getTenant())
                && AccountingProvider::current(Filament::getTenant()) === AccountingProvider::ERPNEXT
                && ProfileUi::allows('members', 'update') && $record->archived_at === null
                && (int) $record->tenant_id === (int) Filament::getTenant()?->id)
            ->modalDescription(trans('erpnext_members.confirm'))
            ->schema([
                Select::make('type')->label(trans('erpnext_members.role'))
                    ->options(['Customer' => trans('erpnext_members.customer'), 'Supplier' => trans('erpnext_members.supplier')])
                    ->required(),
            ])
            ->action(function (Member $record, array $data) {
                app(ErpNextMemberSyncService::class)->sync(Filament::auth()->user(), Filament::getTenant(), $record->id, $data['type']);
                Notification::make()->title(trans('erpnext_members.synced'))->success()->send();
            });
    }
}

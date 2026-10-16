<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Services\ReportExportService;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class StaffReportResource extends TeamStaffResource
{
    protected static ?string $pluralModelLabel = 'Stafflisten';

    public static function getNavigationGroup(): ?string
    {
        return 'Listen';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('tenant');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return parent::table($table)->headerActions([ReportExportService::action('staff')])->recordActions([
            ViewAction::make()->iconButton()->tooltip('Mitgliederdossier')
                ->url(fn ($record) => MemberResource::getUrl('view', ['record' => $record->member_id])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => StaffReportResource\Pages\ListStaff::route('/')];
    }
}

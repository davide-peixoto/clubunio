<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Services\ReportExportService;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;

class MemberReportResource extends MemberResource
{
    protected static ?string $pluralModelLabel = 'Mitgliederlisten';

    public static function getNavigationGroup(): ?string
    {
        return 'Listen';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return ProfileUi::allows('reports');
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return parent::table($table)->headerActions([ReportExportService::action('member_reports')])->recordActions([
            ViewAction::make()->iconButton()->tooltip('Mitgliederdossier')
                ->url(fn ($record) => MemberResource::getUrl('view', ['record' => $record])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => MemberReportResource\Pages\ListMemberReports::route('/')];
    }
}

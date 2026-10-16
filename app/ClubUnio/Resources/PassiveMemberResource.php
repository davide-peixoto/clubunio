<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Services\MembershipReportService;
use App\ClubUnio\Services\ReportExportService;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PassiveMemberResource extends MemberReportResource
{
    protected static ?string $pluralModelLabel = 'Passivmitglieder';

    public static function table(Table $table): Table
    {
        return parent::table($table)->headerActions([ReportExportService::action('passive')]);
    }

    public static function getEloquentQuery(): Builder
    {
        return app(MembershipReportService::class)->passive(Filament::auth()->user(), Filament::getTenant())->with('tenant');
    }

    public static function getPages(): array
    {
        return ['index' => PassiveMemberResource\Pages\ListPassiveMembers::route('/')];
    }
}

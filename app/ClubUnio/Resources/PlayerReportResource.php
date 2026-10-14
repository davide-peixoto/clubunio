<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Services\ReportExportService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PlayerReportResource extends TeamPlayerResource
{
    protected static ?string $pluralModelLabel = 'Spielerlisten';

    public static function getNavigationGroup(): ?string
    {
        return 'Listen';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $table = parent::table($table);
        $columns = array_values($table->getColumns());
        foreach (['email' => 'E-Mail Spieler', 'mobile' => 'Mobile Spieler', 'mother_name' => 'Mutter', 'mother_email' => 'E-Mail Mutter',
            'mother_mobile' => 'Mobile Mutter', 'father_name' => 'Vater', 'father_email' => 'E-Mail Vater', 'father_mobile' => 'Mobile Vater'] as $field => $label) {
            $columns[] = TextColumn::make('member.'.$field)->label($label)->toggleable(isToggledHiddenByDefault: true);
        }
        $table->columns($columns);

        return $table->filters([...array_values($table->getFilters()),
            TernaryFilter::make('without_contribution')->label('Ohne Beitragsposition')
                ->queries(true: fn ($q) => $q->whereNull('invoice_line_id'), false: fn ($q) => $q->whereNotNull('invoice_line_id'), blank: fn ($q) => $q),
        ])->headerActions([ReportExportService::action('players'),
            Action::make('exportContacts')->label('Kontaktliste exportieren')->icon('heroicon-o-arrow-down-tray')->requiresConfirmation()
                ->action(fn ($livewire) => app(ReportExportService::class)->download(Filament::auth()->user(), Filament::getTenant(), 'player_contacts', $livewire->getFilteredSortedTableQuery())),
        ])->recordActions([ViewAction::make()->iconButton()->tooltip('Mitgliederdossier')
            ->url(fn ($record) => MemberResource::getUrl('view', ['record' => $record->member_id]))]);
    }

    public static function getPages(): array
    {
        return ['index' => PlayerReportResource\Pages\ListPlayers::route('/')];
    }
}

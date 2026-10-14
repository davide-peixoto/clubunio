<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\InvoiceCategory;
use App\ClubUnio\Models\InvoiceWorkflowStatus;
use App\ClubUnio\Services\StatusAppearance;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoiceResource extends FinanceResource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $modelLabel = 'Rechnung';

    protected static ?string $pluralModelLabel = 'Rechnungen';

    protected static ?string $tenantRelationshipName = 'invoices';

    protected static string $kind = 'invoices';

    public static function table(Table $table): Table
    {
        $table = parent::table($table);

        return $table->columns(array_merge(array_values($table->getColumns()), [
            TextColumn::make('workflowStatus.name')->label('Bearbeitungsstatus')->placeholder('Importiert')->badge()
                ->color(fn ($state) => StatusAppearance::palette($state)),
            TextColumn::make('category.name')->label('Kategorie')->placeholder('Nicht zugeordnet')->searchable(),
        ]))->filters(array_merge(array_values($table->getFilters()), [
            SelectFilter::make('workflow_status_id')->label('Bearbeitungsstatus')->options(fn () => InvoiceWorkflowStatus::where('tenant_id', Filament::getTenant()->id)->pluck('name', 'id')),
            SelectFilter::make('invoice_category_id')->label('Kategorie')->options(fn () => InvoiceCategory::where('tenant_id', Filament::getTenant()->id)->pluck('name', 'id')),
        ]));
    }

    public static function getPages(): array
    {
        return ['index' => InvoiceResource\Pages\ListInvoices::route('/'), 'view' => InvoiceResource\Pages\ViewInvoice::route('/{record}')];
    }

    public static function getRelations(): array
    {
        return [Relations\InvoiceLinesRelationManager::class];
    }
}

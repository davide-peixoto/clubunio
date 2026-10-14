<?php

namespace App\ClubUnio\Resources\InvoiceResource\Pages;

use App\ClubUnio\Models\InvoiceCategory;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Resources\Concerns\HasSavedViews;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Resources\InvoiceResource;
use App\ClubUnio\Services\InvoiceWorkflowService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    use HasSavedViews { getHeaderActions as protected savedHeaderActions; }

    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [...$this->savedHeaderActions(), Action::make('createInvoice')->label('Rechnung erstellen')->icon('heroicon-o-plus')
            ->schema([
                Select::make('member_id')->label('Mitglied / Empfänger')->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Member::where('tenant_id', Filament::getTenant()->id)
                        ->whereNull('archived_at')->where(function ($q) use ($search) {
                            $q->where('first_name', 'ilike', '%'.$search.'%')->orWhere('last_name', 'ilike', '%'.$search.'%')->orWhere('company', 'ilike', '%'.$search.'%');
                        })->limit(50)->get()->mapWithKeys(fn ($m) => [$m->id => $m->display_name])->all())
                    ->getOptionLabelUsing(fn ($value) => Member::where('tenant_id', Filament::getTenant()->id)->find($value)?->display_name),
                Select::make('invoice_category_id')->label('Kategorie')->required()
                    ->options(fn () => InvoiceCategory::where('tenant_id', Filament::getTenant()->id)->where('active', true)->orderBy('sort_order')->pluck('name', 'id')),
                Select::make('tax_mode')->label('Steuerbehandlung')->required()
                    ->options(['exempt' => 'Steuerbefreit', 'inclusive' => 'Endpreise inklusive Steuer']),
                Textarea::make('notes')->label('Bemerkungen')->maxLength(4000),
            ])->action(function (array $data) {
                $invoice = FormMutation::run($this, fn () => app(InvoiceWorkflowService::class)
                    ->create(Filament::auth()->user(), Filament::getTenant(), $data));
                $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice->id]));
            })];
    }
}

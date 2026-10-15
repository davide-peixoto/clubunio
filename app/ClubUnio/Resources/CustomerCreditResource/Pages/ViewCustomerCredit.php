<?php

namespace App\ClubUnio\Resources\CustomerCreditResource\Pages;

use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Resources\CustomerCreditResource;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\CustomerCreditService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomerCredit extends ViewRecord
{
    protected static string $resource = CustomerCreditResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('correctReference')->label('Referenz korrigieren')->icon('heroicon-o-pencil-square')
            ->visible(fn () => ! $this->getRecord()->allocations()->exists() && BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))
            ->fillForm(fn () => ['revision' => $this->getRecord()->revision, 'reference' => $this->getRecord()->external_reference])
            ->schema([TextInput::make('revision')->label('Version')->readOnly()->required(), TextInput::make('reference')->label('Bexio-Gutschriftnummer')->required()->regex('/^GS-[0-9]{1,20}$/iD')])
            ->action(function ($data) {
                FormMutation::run($this, fn () => app(CustomerCreditService::class)->correctReference(Filament::auth()->user(), Filament::getTenant(), $this->getRecord()->id, (int) $data['revision'], $data['reference']));
                $this->redirect(CustomerCreditResource::getUrl('view', ['record' => $this->getRecord()->id]));
            }), Action::make('allocate')->label('Bexio-Verrechnung abgleichen')->icon('heroicon-o-arrows-right-left')
            ->visible(fn () => $this->getRecord()->remaining_cents > 0 && BexioConnectionService::canManage(Filament::auth()->user(), Filament::getTenant()))
            ->fillForm(fn () => ['revision' => $this->getRecord()->revision])
            ->schema([TextInput::make('revision')->label('Version')->readOnly()->required(),
                Select::make('invoice_id')->label('Rechnung')->searchable()->required()->options(fn () => $this->invoiceOptions())
                    ->getSearchResultsUsing(fn (string $search) => $this->invoiceOptions($search))
                    ->getOptionLabelUsing(fn ($value) => $this->invoiceOptions('', (int) $value)[$value] ?? null),
                Checkbox::make('attestation')->label('Verrechnung in Bexio durchgeführt; nur den Nachweis übernehmen.')->accepted()])
            ->action(function ($data) {
                FormMutation::run($this, fn () => app(CustomerCreditService::class)->allocate(Filament::auth()->user(), Filament::getTenant(), $this->getRecord()->id, (int) $data['revision'], (int) $data['invoice_id']));
                $this->redirect(CustomerCreditResource::getUrl('view', ['record' => $this->getRecord()->id]));
            })];
    }

    private function invoiceOptions(string $search = '', ?int $id = null): array
    {
        return Invoice::where('tenant_id', Filament::getTenant()->id)->where('active', true)->whereNull('legacy_id')->whereNotNull('bexio_invoice_id')
            ->when($id, fn ($q) => $q->whereKey($id))->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->whereRaw('cast(id as text) like ?', ['%'.$search.'%'])
            ->orWhereHas('member', fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'ilike', '%'.$search.'%')->orWhere('last_name', 'ilike', '%'.$search.'%')->orWhere('company', 'ilike', '%'.$search.'%')))))
            ->with('member')->orderByDesc('id')->limit(50)->get()->mapWithKeys(fn ($i) => [$i->id => '#'.$i->id.' · '.$i->member?->display_name])->all();
    }
}

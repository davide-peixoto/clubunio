<?php

namespace App\ClubUnio\Resources\InvoiceResource\Pages;

use App\ClubUnio\Models\InvoiceCategory;
use App\ClubUnio\Resources\FinancialAdjustmentActions;
use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Resources\InvoiceResource;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioInvoiceTransferService;
use App\ClubUnio\Services\InvoiceWorkflowService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        $actor = fn () => Filament::auth()->user();
        $tenant = fn () => Filament::getTenant();
        $editable = fn () => app(InvoiceWorkflowService::class)->editable($actor(), $tenant(), $this->getRecord());
        $mutate = function (string $operation, array $data) use ($actor, $tenant) {
            FormMutation::run($this, fn () => app(InvoiceWorkflowService::class)
                ->change($actor(), $tenant(), $this->getRecord()->id, (int) $data['revision'], $operation, $data));
            $this->redirect(InvoiceResource::getUrl('view', ['record' => $this->getRecord()->id]));
        };
        $revision = fn () => TextInput::make('revision')->label('Version')->readOnly()->required();

        return [
            Action::make('sendBexio')->label('An Bexio übertragen')->icon('heroicon-o-paper-airplane')
                ->visible(fn () => $this->getRecord()->active && ! $this->getRecord()->legacy_id && ! $this->getRecord()->bexio_invoice_id
                    && $this->getRecord()->workflowStatus?->code === 'released' && BexioConnectionService::canManage($actor(), $tenant()))
                ->modalHeading('An Bexio-Sandbox übertragen')->modalSubmitActionLabel('An Bexio übertragen')
                ->modalDescription('Ziel: SVS Api Test. Anonymisierter Testkontakt; Rechnungstexte und Beträge werden übertragen. Anlage als Entwurf, ohne E-Mail oder Zahlung.')
                ->fillForm(fn () => ['revision' => $this->getRecord()->revision])
                ->schema([$revision(), Checkbox::make('sandbox_confirmed')->label('Übertragung dieser Testrechnung in die Sandbox bestätigen')->required()->accepted()])
                ->action(function (array $data) use ($actor, $tenant) {
                    try {
                        $row = FormMutation::run($this, fn () => app(BexioInvoiceTransferService::class)->transfer($actor(), $tenant(), $this->getRecord()->id, (int) $data['revision']));
                    } catch (ValidationException $e) {
                        Notification::make()->title('Rechnungsdaten prüfen')->body(collect($e->errors())->flatten()->first())->warning()->send();

                        return;
                    } catch (HttpException $e) {
                        Notification::make()->title('Übertragung nicht ausgeführt')->body($e->getStatusCode() === 409 ? ($e->getMessage() ?: 'Rechnung neu laden und bestehenden Sandbox-Auftrag prüfen.') : 'Berechtigung oder Zuordnung prüfen.')->warning()->send();

                        return;
                    } catch (\RuntimeException $e) {
                        report($e);
                        Notification::make()->title('Sandbox nicht verfügbar')->body('Verbindung und bestehenden Auftrag prüfen. Keine unbestätigte Übertragung erneut anlegen.')->warning()->send();

                        return;
                    }
                    Notification::make()->title($row->state === 'accepted' ? 'In Bexio-Sandbox angelegt' : 'Übertragung nicht bestätigt')
                        ->body($row->state === 'accepted' ? 'Bexio-ID: '.$row->remote_id : 'Den bestehenden Auftrag unter Bexio-Sandbox abgleichen. Keine erneute Anlage.')
                        ->status($row->state === 'accepted' ? 'success' : 'warning')->send();
                    $this->redirect(InvoiceResource::getUrl('view', ['record' => $this->getRecord()->id]));
                }),
            FinancialAdjustmentActions::credit(fn () => $this->getRecord()->id)
                ->visible(fn () => ! $this->getRecord()->legacy_id && $this->getRecord()->active && in_array($this->getRecord()->workflowStatus?->code, ['released', 'cancelled'], true)),
            FinancialAdjustmentActions::refund(fn () => $this->getRecord()->id)
                ->visible(fn () => (bool) $this->getRecord()->bexio_invoice_id && ! $this->getRecord()->legacy_id && BexioConnectionService::canManage($actor(), $tenant())),
            Action::make('archive')->label('Archivieren')->icon('heroicon-o-archive-box')
                ->visible(fn () => $this->getRecord()->active && ! $this->getRecord()->legacy_id && ! $this->getRecord()->bexio_invoice_id && $this->getRecord()->workflowStatus?->code === 'cancelled')
                ->fillForm(fn () => ['revision' => $this->getRecord()->revision])
                ->schema([$revision(), Textarea::make('reason')->label('Begründung')->required()->minLength(3)->maxLength(1000)])
                ->action(fn (array $data) => $mutate('archive', $data)),
            Action::make('restore')->label('Aus Archiv wiederherstellen')->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn () => ! $this->getRecord()->active && ! $this->getRecord()->legacy_id && ! $this->getRecord()->bexio_invoice_id && $this->getRecord()->workflowStatus?->code === 'cancelled')
                ->fillForm(fn () => ['revision' => $this->getRecord()->revision])
                ->schema([$revision(), Textarea::make('reason')->label('Begründung')->required()->minLength(3)->maxLength(1000)])
                ->action(fn (array $data) => $mutate('restore', $data)),
            Action::make('pdf')->label('PDF-Vorschau')->icon('heroicon-o-document')->slideOver()->modalWidth('5xl')
                ->visible(fn () => $this->getRecord()->active && (bool) ($this->getRecord()->bexio_invoice_id || $this->getRecord()->workflow_status_id))
                ->modalSubmitAction(false)->modalCancelActionLabel('Schliessen')->modalContent(fn () => view('clubunio.invoices.preview', [
                    'viewerUrl' => route('clubunio.invoices.preview', ['tenant' => $tenant()->slug, 'invoice' => $this->getRecord()->id,
                        'source' => $this->getRecord()->bexio_invoice_id ? 'bexio' : 'local']),
                    'url' => route('clubunio.invoices.pdf', ['tenant' => $tenant()->slug, 'invoice' => $this->getRecord()->id,
                        'source' => $this->getRecord()->bexio_invoice_id ? 'bexio' : 'local'])])),
            Action::make('editHeader')->label('Bearbeiten')->icon('heroicon-o-pencil-square')->visible($editable)
                ->fillForm(fn () => $this->getRecord()->only(['revision', 'notes', 'tax_mode', 'invoice_category_id']))
                ->schema([$revision(), Select::make('invoice_category_id')->label('Kategorie')
                    ->options(fn () => InvoiceCategory::where('tenant_id', $tenant()->id)->where('active', true)->pluck('name', 'id')),
                    Select::make('tax_mode')->label('Steuerbehandlung')->required()->options(['exempt' => 'Steuerbefreit', 'inclusive' => 'Endpreise inklusive Steuer']),
                    Textarea::make('notes')->label('Bemerkungen')->maxLength(4000)])
                ->action(fn (array $data) => $mutate('header', $data)),
            Action::make('release')->label('Intern freigeben')->icon('heroicon-o-check')->visible($editable)
                ->modalDescription('Interne Freigabe ohne Bexio-Übertragung oder Versand. Danach sind Positionen gesperrt.')
                ->fillForm(fn () => ['revision' => $this->getRecord()->revision])->schema([$revision()])
                ->action(fn (array $data) => $mutate('release', $data)),
            Action::make('cancel')->label('Stornieren')->color('danger')->icon('heroicon-o-x-mark')
                ->visible(fn () => $editable() || ($this->getRecord()->active && ! $this->getRecord()->legacy_id && ! $this->getRecord()->bexio_invoice_id && $this->getRecord()->workflowStatus?->code === 'released'))
                ->fillForm(fn () => ['revision' => $this->getRecord()->revision])
                ->schema([$revision(), Textarea::make('reason')->label('Begründung')->required()->minLength(3)->maxLength(1000)])
                ->action(fn (array $data) => $mutate('cancel', $data)),
        ];
    }

    public function getTitle(): string
    {
        return 'Rechnung #'.($this->getRecord()->legacy_id ?? $this->getRecord()->id);
    }
}

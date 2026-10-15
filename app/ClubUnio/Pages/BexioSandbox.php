<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Services\BexioConnectionService;
use App\ClubUnio\Services\BexioDispatchTrialService;
use App\ClubUnio\Services\BexioFinancialActionService;
use App\ClubUnio\Services\BexioGeneralSandboxFixtureService;
use App\ClubUnio\Services\BexioInvoiceActionService;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BexioSandbox extends Page
{
    protected string $view = 'clubunio.bexio-sandbox';

    protected static ?string $title = 'Bexio-Sandbox';

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return 'Finanzen';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-beaker';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() && BexioConnectionService::canManage(Filament::auth()->user()?->fresh(), Filament::getTenant());
    }

    public function prepare(string $kind): void
    {
        $this->run(fn ($service) => $service->prepare(Filament::auth()->user(), Filament::getTenant(), $kind));
    }

    public function prepareParent(): void
    {
        $this->run(function ($service) {
            $actor = Filament::auth()->user();
            $tenant = Filament::getTenant();
            $invoiceId = app(BexioGeneralSandboxFixtureService::class)->prepareParent($actor, $tenant);
            if (! DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')->where('tenant_id', $tenant->id)->where('invoice_id', $invoiceId)->exists()) {
                $trial = app(BexioDispatchTrialService::class)->preview($actor, $tenant, 'invoice', $invoiceId);
                $service->prepareTrial($actor, $tenant, $trial);
            }
        });
    }

    public function invoiceAction(int $invoiceId, string $operation): void
    {
        $this->run(fn () => app(BexioInvoiceActionService::class)->execute(Filament::auth()->user(), Filament::getTenant(), $invoiceId, $operation));
    }

    public function invoiceResult(int $invoiceId): void
    {
        $this->run(fn () => app(BexioInvoiceActionService::class)->reconcile(Filament::auth()->user(), Filament::getTenant(), $invoiceId));
    }

    public function financeAction(string $kind, int $objectId, string $operation): void
    {
        $this->run(fn () => app(BexioFinancialActionService::class)->execute(Filament::auth()->user(), Filament::getTenant(), $kind, $objectId, $operation));
    }

    public function financeResult(string $kind, int $objectId): void
    {
        $this->run(fn () => app(BexioFinancialActionService::class)->reconcile(Filament::auth()->user(), Filament::getTenant(), $kind, $objectId));
    }

    public function send(int $id): void
    {
        $this->run(fn ($service) => $service->send(Filament::auth()->user(), Filament::getTenant(), $id));
    }

    public function reconcile(int $id): void
    {
        $this->run(fn ($service) => $service->reconcile(Filament::auth()->user(), Filament::getTenant(), $id));
    }

    public function verifyReconnection(): void
    {
        $this->run(fn ($service) => $service->verifyReconnection(Filament::auth()->user(), Filament::getTenant()));
    }

    public function resumeUpload(int $id): void
    {
        $this->run(fn ($service) => $service->resumeRejectedUpload(Filament::auth()->user(), Filament::getTenant(), $id));
    }

    private function run(callable $action): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            $action(app(BexioSandboxDispatchService::class));
            Notification::make()->title('Prüfung abgeschlossen')->body('Der aktuelle Stand ist im Übertragungsprotokoll sichtbar.')->send();
        } catch (\Throwable $error) {
            // Provider responses and secrets never enter Livewire state or flash messages.
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : (int) $error->getCode();
            Notification::make()->title('Aktion nicht abgeschlossen')->body('Verbindung, Testbeleg oder Berechtigung prüfen. Prüfcode: '.$status)->danger()->send();
        }
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $rows = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')
            ->where('tenant_id', Filament::getTenant()->id)->orderBy('id')->get();

        return ['rows' => $rows,
            'eligibleInvoices' => Invoice::where('tenant_id', Filament::getTenant()->id)->whereIn('id', $rows->pluck('invoice_id')->filter())->whereHas('workflowStatus', fn ($q) => $q->where('code', 'released'))->pluck('id')->all(),
            'eligibleExpenses' => Expense::where('tenant_id', Filament::getTenant()->id)->whereIn('id', $rows->pluck('expense_id')->filter())
                ->whereHas('status', fn ($q) => $q->where('code', 'Z'))->whereNotNull('payment_released_on')->pluck('id')->all(),
            'financialActions' => DB::connection('clubunio')->table('clubora.bexio_financial_actions')->whereIn('dispatch_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('dispatch_id'),
            'balances' => DB::connection('clubunio')->table('clubora.bexio_balances')->where('tenant_id', Filament::getTenant()->id)->get()->keyBy(fn ($balance) => $balance->object_type.':'.$balance->object_id),
            'actions' => DB::connection('clubunio')->table('clubora.bexio_invoice_actions')->whereIn('dispatch_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('dispatch_id'), 'steps' => DB::connection('clubunio')->table('clubora.bexio_sandbox_steps')
                ->whereIn('dispatch_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('dispatch_id')];
    }
}

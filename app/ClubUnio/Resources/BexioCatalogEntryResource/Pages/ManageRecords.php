<?php

namespace App\ClubUnio\Resources\BexioCatalogEntryResource\Pages;

use App\ClubUnio\Resources\BexioCatalogEntryResource;
use App\ClubUnio\Services\BexioCatalogFixtureService;
use App\ClubUnio\Services\BexioCatalogService;
use App\ClubUnio\Services\BexioDispatchTrialService;
use App\ClubUnio\Services\BexioSandboxDispatchService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ManageRecords extends \Filament\Resources\Pages\ManageRecords
{
    protected static string $resource = BexioCatalogEntryResource::class;

    public function syncCatalog(): void
    {
        $this->runCatalog(false);
    }

    public function testArticle(): void
    {
        $this->runCatalog(true);
    }

    public function testInvoice(): void
    {
        $this->runCatalog(false, true);
    }

    private function runCatalog(bool $fixture, bool $invoice = false): void
    {
        abort_unless(BexioCatalogEntryResource::canManageCatalog($fixture || $invoice ? 'create' : 'sync'), 403);
        try {
            if ($invoice) {
                $count = app(BexioCatalogFixtureService::class)->prepareInvoice(Filament::auth()->user(), Filament::getTenant());
                $existing = DB::connection('clubunio')->table('clubora.bexio_sandbox_dispatches')
                    ->where('tenant_id', Filament::getTenant()->id)->where('invoice_id', $count)->exists();
                if (! $existing) {
                    $trial = app(BexioDispatchTrialService::class)->preview(Filament::auth()->user(), Filament::getTenant(), 'invoice', $count);
                    app(BexioSandboxDispatchService::class)->prepareTrial(Filament::auth()->user(), Filament::getTenant(), $trial);
                }
            } else {
                $count = $fixture ? app(BexioCatalogFixtureService::class)->create(Filament::auth()->user(), Filament::getTenant())
                    : app(BexioCatalogService::class)->sync(Filament::auth()->user(), Filament::getTenant());
            }
            Notification::make()->title($invoice ? 'Artikeltestrechnung vorbereitet' : ($fixture ? 'Sandbox-Testartikel bestätigt' : $count.' Katalogeinträge abgeglichen'))->success()->send();
            $this->resetTable();
        } catch (\Throwable $error) {
            $reference = (string) Str::uuid();
            Log::warning('clubunio.bexio.catalog_failed', ['reference' => $reference,
                'actor_id' => Filament::auth()->id(), 'tenant_id' => Filament::getTenant()?->id,
                'exception' => get_class($error), 'code' => $error->getCode()]);
            Notification::make()->title('Katalogabgleich fehlgeschlagen')->body('Diagnose: '.$reference)->danger()->send();
        }
    }
}

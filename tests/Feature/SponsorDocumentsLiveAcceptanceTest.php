<?php

namespace Tests\Feature;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\Tenant;
use App\ClubUnio\Pages\DocumentSeries;
use App\ClubUnio\Pages\SponsorRunBilling;
use App\ClubUnio\Pages\SponsorRuns;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Read-only real-provider checks of the explicitly prepared synthetic acceptance journal. */
class SponsorDocumentsLiveAcceptanceTest extends TestCase
{
    private array $fixture;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CLUBUNIO_LIVE_ACCEPTANCE') !== '1') {
            $this->markTestSkipped('Explicit live acceptance required; ordinary tests never call providers.');
        }
        $this->assertSame('db_acdf8d_dclubora', DB::connection('clubunio')->getDatabaseName());
        $file = storage_path('app/private/sponsor-documents-acceptance-20261025.json');
        $this->assertFileExists($file);
        $this->fixture = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('20261025-sponsor-documents-v1', $this->fixture['run']);
        $this->tenant = Tenant::where('slug', 'sv-sissach')->findOrFail($this->fixture['tenant']);
        $actor = Account::where('username', 'davide.peixoto@svsissach.ch')->sole();
        $this->actingAs($actor, 'clubunio');
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        Filament::setTenant($this->tenant);
        session(['clubunio_context.'.$actor->id => ['tenant' => $this->tenant->id, 'revision' => 'live-acceptance-read-only']]);
    }

    public function test_real_azure_original_through_authenticated_web_route(): void
    {
        $response = $this->get(route('clubunio.document-series.original', [$this->tenant->slug, $this->fixture['series'], $this->fixture['recipient']]));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $sha = DB::connection('clubunio')->table('clubora.document_series_recipients')->where('id', $this->fixture['recipient'])->value('original_sha256');
        $this->assertSame($sha, hash('sha256', $response->getContent()));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_real_sandbox_pdf_through_workspace_web_route(): void
    {
        $response = $this->get(route('clubunio.sponsor.billing.pdf', ['invoice' => $this->fixture['bill']]));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertGreaterThan(20000, strlen($response->getContent()));
    }

    public function test_prepared_filled_web_pages_render_without_new_writes(): void
    {
        Livewire::test(DocumentSeries::class)->call('openSeries', $this->fixture['series'])->assertSuccessful()->assertSee('QA-Sponsor-Dokumente-20261025')->assertSee('Nur privates Dossier');
        Livewire::test(SponsorRuns::class)->call('openEvent', $this->fixture['event'])->call('openParticipant', $this->fixture['participant'])->assertSuccessful()->assertSee('QA-Abnahme 20261025')->assertSee('class="cu-sr"', false)->assertSee('CHF 1.00')->assertSee('CHF 3.00')->assertDontSee('Rappen');
        Livewire::test(SponsorRunBilling::class)->set('event', $this->fixture['event'])->assertSuccessful()->assertSee('Versendet')->assertSee('QA-Abnahme 20261025');
    }
}

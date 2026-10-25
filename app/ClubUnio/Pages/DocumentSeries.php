<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Resources\ProfileUi;
use App\ClubUnio\Services\DocumentSeriesRenderer;
use App\ClubUnio\Services\DocumentSeriesService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

class DocumentSeries extends Page
{
    use WithFileUploads;

    protected string $view = 'clubunio.document-series.page';

    protected static ?string $title = 'Dokumentenserien';

    protected static ?string $slug = 'document-series';

    public ?int $templateId = null;

    public array $teams = [];

    public string $target = 'players';

    public string $date = '';

    public bool $signatureRequired = true;

    public bool $portalVisible = true;

    public array $common = ['number' => '', 'base' => '', 'responsible' => ''];

    public array $individual = [];

    public array $selected = [];

    public string $teamSearch = '';

    public string $recipientSearch = '';

    public string $historySearch = '';

    public int $historyPage = 1;

    public array $editor = ['name' => '', 'title' => '', 'text' => '', 'type_id' => null, 'signature_required' => true, 'items' => []];

    public bool $editing = false;

    public bool $acknowledge = false;

    public $signedFile;

    public bool $returnChecked = false;

    #[Locked]
    public ?int $previousTemplate = null;

    #[Locked]
    public array $review = [];

    #[Locked]
    public array $candidates = [];

    #[Locked]
    public ?int $seriesId = null;

    #[Locked]
    public ?int $returnRecipient = null;

    #[Locked]
    public string $requestKey = '';

    public static function canAccess(): bool
    {
        return ProfileUi::allows('documents');
    }

    public function mount(): void
    {
        $this->service()->authorize(Filament::auth()->user(), Filament::getTenant());
        $this->date = now()->toDateString();
        $this->requestKey = (string) Str::uuid();
    }

    private function service(): DocumentSeriesService
    {
        return app(DocumentSeriesService::class);
    }

    public function updated(string $field): void
    {
        if (in_array(explode('.', $field)[0], ['templateId', 'teams', 'target', 'date', 'signatureRequired', 'portalVisible', 'common', 'individual', 'selected'])) {
            $this->review = [];
            $this->acknowledge = false;
            $this->requestKey = (string) Str::uuid();
        }
        if (in_array(explode('.', $field)[0], ['templateId', 'teams', 'target'])) {
            $this->candidates = [];
            $this->selected = [];
            $this->individual = [];
        }
        if ($field === 'templateId' && $this->templateId) {
            $template = $this->service()->templates(Filament::auth()->user(), Filament::getTenant())->firstWhere('id', $this->templateId);
            abort_unless($template, 404);
            $this->signatureRequired = $template->definition['signature_required'];
        }
        if ($field === 'historySearch') {
            $this->historyPage = 1;
        }
    }

    public function defaults(): void
    {
        $this->service()->installDefaults(Filament::auth()->user(), Filament::getTenant());
        Notification::make()->title('Standardvorlagen vorhanden')->success()->send();
    }

    public function editTemplate(bool $new = false): void
    {
        $this->service()->authorize(Filament::auth()->user(), Filament::getTenant(), $new ? 'create' : 'update');
        if ($new) {
            $this->editor = ['name' => '', 'title' => '', 'text' => '', 'type_id' => null, 'signature_required' => true, 'items' => []];
            $this->previousTemplate = null;
        } else {
            $row = $this->service()->templates(Filament::auth()->user(), Filament::getTenant())->firstWhere('id', $this->templateId);
            abort_unless($row, 404);
            $this->editor = $row->definition;
            $this->previousTemplate = $row->id;
        }
        $this->editing = true;
    }

    public function addItem(): void
    {
        abort_unless(count($this->editor['items']) < 40, 422);
        $this->editor['items'][] = ['description' => '', 'reference' => '', 'quantity' => 1, 'price_cents' => 0, 'size' => '', 'initials' => '', 'brand' => ''];
    }

    public function removeItem(int $index): void
    {
        unset($this->editor['items'][$index]);
        $this->editor['items'] = array_values($this->editor['items']);
    }

    public function saveTemplate(): void
    {
        $row = $this->service()->saveTemplate(Filament::auth()->user(), Filament::getTenant(), $this->editor, $this->previousTemplate);
        $this->templateId = $row->id;
        $this->signatureRequired = $row->definition['signature_required'];
        $this->editing = false;
        $this->updated('templateId');
    }

    private function extras(array $values): array
    {
        $result = [];
        foreach (['number' => 'Schluessel.Nummer', 'base' => 'Schluessel.Grundnummer', 'responsible' => 'Dokument.Verantwortlicher'] as $key => $name) {
            if (($values[$key] ?? '') !== '') {
                $result[$name] = $values[$key];
            }
        }

        return $result;
    }

    private function input(): array
    {
        $input = ['template_id' => $this->templateId, 'teams' => $this->teams, 'target' => $this->target, 'date' => $this->date,
            'signature_required' => $this->signatureRequired, 'portal_visible' => $this->portalVisible, 'common' => $this->extras($this->common),
            'individual' => array_map(fn ($values) => $this->extras($values), $this->individual)];
        if ($this->candidates) {
            $input['members'] = array_map('intval', array_keys(array_filter($this->selected)));
        }

        return $input;
    }

    public function checkPreview(): void
    {
        $preview = $this->service()->preview(Filament::auth()->user(), Filament::getTenant(), $this->input());
        if (! $this->candidates) {
            foreach ($preview['snapshot']['recipients'] as $person) {
                $this->candidates[] = ['id' => $person['member_id'], 'name' => $person['member_name'], 'teams' => $person['values']['Team.Name']];
                $this->selected[$person['member_id']] = true;
            }
            // Freeze the same explicit selection that will be submitted on create.
            $preview = $this->service()->preview(Filament::auth()->user(), Filament::getTenant(), $this->input());
        }
        $this->review = $preview;
        $this->acknowledge = false;
    }

    public function createSeries(): void
    {
        abort_unless($this->acknowledge && ($this->review['can_create'] ?? false), 422);
        $row = $this->service()->create(Filament::auth()->user(), Filament::getTenant(), $this->input(), $this->review['hash'], $this->requestKey);
        $this->seriesId = $row->id;
        Notification::make()->title('Serie erstellt')->success()->send();
    }

    public function openSeries(int $id): void
    {
        $this->seriesId = $this->service()->series(Filament::auth()->user(), Filament::getTenant(), $id)->id;
        $this->returnRecipient = null;
        $this->signedFile = null;
        $this->returnChecked = false;
    }

    public function processNext(): void
    {
        abort_unless($this->seriesId, 422);
        try {
            $result = $this->service()->process(Filament::auth()->user(), Filament::getTenant(), $this->seriesId);
            Notification::make()->title($result['done'] ? 'Serie vollständig' : (! empty($result['busy']) ? 'Verarbeitung läuft bereits' : 'Dokument erstellt'))->success()->send();
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title('Verarbeitung offen')->body('Erneut versuchen. Bereits gespeicherte Uploads bleiben erhalten.')->warning()->send();
        }
    }

    public function chooseReturn(int $recipient): void
    {
        $this->service()->authorize(Filament::auth()->user(), Filament::getTenant(), 'upload', 'update');
        abort_unless($this->seriesId && $this->service()->recipients(Filament::auth()->user(), Filament::getTenant(), $this->seriesId)->contains('id', $recipient), 404);
        $this->returnRecipient = $recipient;
        $this->signedFile = null;
        $this->returnChecked = false;
    }

    public function uploadReturn(): void
    {
        abort_unless($this->seriesId && $this->returnRecipient, 422);
        $row = $this->service()->recipients(Filament::auth()->user(), Filament::getTenant(), $this->seriesId)->firstWhere('id', $this->returnRecipient);
        abort_unless($row, 404);
        $this->validate(['signedFile' => 'required|file|mimetypes:application/pdf|max:10240', 'returnChecked' => 'accepted']);
        $this->service()->signedReturn(Filament::auth()->user(), Filament::getTenant(), $this->seriesId, $row->id, $this->signedFile, $this->returnChecked, $row->original_sha256);
        $this->returnRecipient = null;
        $this->signedFile = null;
        $this->returnChecked = false;
    }

    public function retryReturn(int $recipient): void
    {
        abort_unless($this->seriesId, 422);
        $this->service()->finishSignedReturn(Filament::auth()->user(), Filament::getTenant(), $this->seriesId, $recipient);
    }

    public function historyMove(int $direction): void
    {
        $this->historyPage = max(1, $this->historyPage + ($direction > 0 ? 1 : -1));
    }

    protected function getViewData(): array
    {
        $actor = Filament::auth()->user();
        $tenant = Filament::getTenant();
        $lookups = $this->service()->lookups($actor, $tenant);
        $lookups['teams'] = $lookups['teams']->filter(fn ($t) => $this->teamSearch === '' || str_contains(mb_strtolower($t->team->name.' '.$t->season->name), mb_strtolower($this->teamSearch)));
        $candidates = collect($this->candidates)->filter(fn ($p) => $this->recipientSearch === '' || str_contains(mb_strtolower($p['name'].' '.$p['teams']), mb_strtolower($this->recipientSearch)));
        $previewHtml = '';
        foreach ($this->review['snapshot']['recipients'] ?? [] as $person) {
            if (! isset($person['error'])) {
                $previewHtml = app(DocumentSeriesRenderer::class)->html($person);
                break;
            }
        }

        return ['lookups' => $lookups, 'filteredCandidates' => $candidates, 'previewHtml' => $previewHtml,
            'history' => $this->service()->history($actor, $tenant, $this->historySearch, $this->historyPage),
            'recipients' => $this->seriesId ? $this->service()->recipients($actor, $tenant, $this->seriesId) : collect(),
        ];
    }
}

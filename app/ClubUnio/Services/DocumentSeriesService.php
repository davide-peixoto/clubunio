<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\DocumentSeries;
use App\ClubUnio\Models\DocumentSeriesRecipient;
use App\ClubUnio\Models\DocumentSeriesTemplate;
use App\ClubUnio\Models\DocumentType;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberDocument;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentSeriesService
{
    public function authorize(Account $actor, Tenant $tenant, string ...$operations): void
    {
        $actor = $actor->fresh();
        abort_unless($actor && $actor->active, 403);
        foreach (array_unique(['view', ...$operations]) as $operation) {
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'documents', $operation);
        }
    }

    private function transaction(Tenant $tenant, callable $action): mixed
    {
        return DB::connection('clubunio')->transaction(function () use ($tenant, $action) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            return $action();
        });
    }

    public function templates(Account $actor, Tenant $tenant)
    {
        $this->authorize($actor, $tenant);

        return DocumentSeriesTemplate::where('tenant_id', $tenant->id)->orderBy('name')->orderByDesc('version')->get();
    }

    public function saveTemplate(Account $actor, Tenant $tenant, array $input, ?int $previous = null): DocumentSeriesTemplate
    {
        return $this->transaction($tenant, function () use ($actor, $tenant, $input, $previous) {
            $this->authorize($actor, $tenant, $previous ? 'update' : 'create');
            $d = app(DocumentSeriesRenderer::class)->definition($input);
            DocumentType::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($d['type_id']);
            $family = $previous ? DocumentSeriesTemplate::where('tenant_id', $tenant->id)->findOrFail($previous)->family_key : (string) Str::uuid();

            return $this->insertTemplate($actor, $tenant, $family, $d);
        });
    }

    private function insertTemplate(Account $actor, Tenant $tenant, string $family, array $d): DocumentSeriesTemplate
    {
        $row = new DocumentSeriesTemplate;
        $row->forceFill(['tenant_id' => $tenant->id, 'created_by' => $actor->id, 'family_key' => $family,
            'version' => 1 + (int) DocumentSeriesTemplate::where('tenant_id', $tenant->id)->where('family_key', $family)->max('version'),
            'name' => $d['name'], 'definition' => $d, 'definition_hash' => DocumentSeriesRenderer::hash($d)])->save();
        $this->audit($actor, $tenant, 'document_series_templates', $row->id, 'new_version');

        return $row;
    }

    public function installDefaults(Account $actor, Tenant $tenant): void
    {
        $this->transaction($tenant, function () use ($actor, $tenant) {
            $this->authorize($actor, $tenant, 'create');
            foreach (DocumentSeriesDefaults::all() as $family => $d) {
                if (DocumentSeriesTemplate::where('tenant_id', $tenant->id)->where('family_key', $family)->exists()) {
                    continue;
                }
                $type = DocumentType::where('tenant_id', $tenant->id)->where('active', true)->where('code', $d['type_code'])->first();
                if (! $type) {
                    throw ValidationException::withMessages(['template' => 'Aktiver Dokumenttyp '.$d['type_code'].' fehlt. Bitte in den Stammdaten durch eine berechtigte Person ergänzen lassen oder eine eigene Vorlage mit einem vorhandenen Typ erstellen.']);
                }
                $d['type_id'] = $type->id;
                unset($d['type_code']);
                $this->insertTemplate($actor, $tenant, $family, app(DocumentSeriesRenderer::class)->definition($d));
            }
        });
    }

    public function lookups(Account $actor, Tenant $tenant): array
    {
        $this->authorize($actor, $tenant);

        return ['templates' => $this->templates($actor, $tenant),
            'types' => DocumentType::where('tenant_id', $tenant->id)->where('active', true)->orderBy('name')->pluck('name', 'id'),
            'teams' => TeamSeason::where('tenant_id', $tenant->id)->where('active', true)->with(['team', 'season'])->orderByDesc('season_id')->get(),
        ];
    }

    public function preview(Account $actor, Tenant $tenant, array $input): array
    {
        $this->authorize($actor, $tenant);
        $input = Validator::make($input, [
            'template_id' => 'required|integer|min:1', 'teams' => 'required|array|min:1|max:30', 'teams.*' => 'required|integer|min:1|distinct',
            'target' => 'required|in:players,staff,both', 'date' => 'required|date_format:Y-m-d',
            'signature_required' => 'required|boolean', 'members' => 'sometimes|array|min:1|max:500', 'members.*' => 'integer|min:1|distinct',
            'common' => 'sometimes|array', 'individual' => 'sometimes|array|max:500', 'portal_visible' => 'required|boolean',
        ])->validate();
        $template = DocumentSeriesTemplate::where('tenant_id', $tenant->id)->findOrFail($input['template_id']);
        abort_unless(hash_equals($template->definition_hash, DocumentSeriesRenderer::hash($template->definition)), 409);
        $d = $template->definition;
        $d['signature_required'] = (bool) $input['signature_required'];
        DocumentType::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($d['type_id']);
        $teams = TeamSeason::where('tenant_id', $tenant->id)->where('active', true)->whereIn('id', $input['teams'])->with(['team', 'season'])->orderBy('id')->get();
        abort_unless($teams->count() === count($input['teams']), 422);
        $labels = $teams->mapWithKeys(fn ($t) => [$t->id => $t->team->name.' / '.$t->season->name])->all();
        $links = collect();
        foreach ($input['target'] === 'both' ? ['team_players', 'team_staff'] : [$input['target'] === 'players' ? 'team_players' : 'team_staff'] as $table) {
            $links = $links->concat(DB::connection('clubunio')->table('clubora.'.$table)->where('tenant_id', $tenant->id)->where('active', true)
                ->whereIn('team_season_id', $input['teams'])->orderBy('team_season_id')->get(['member_id', 'team_season_id']));
        }
        $people = Member::where('tenant_id', $tenant->id)->whereNull('archived_at')->whereIn('id', $links->pluck('member_id')->unique())->orderBy('id')->get();
        abort_if($people->count() > 500 || $people->isEmpty(), 422, '1 bis 500 Empfänger erforderlich.');
        $members = array_map('intval', $input['members'] ?? $people->modelKeys());
        sort($members);
        abort_unless(count(array_diff($members, $people->modelKeys())) === 0, 422, 'Fremde Empfänger.');
        $common = $this->extras($input['common'] ?? []);
        $individual = $input['individual'] ?? [];
        foreach ($individual as $id => $extra) {
            abort_unless(in_array((int) $id, $people->modelKeys(), true) && is_array($extra), 422);
            $this->extras($extra);
        }
        $snapshots = [];
        $valid = true;
        foreach ($people->whereIn('id', $members) as $member) {
            $names = $links->where('member_id', $member->id)->pluck('team_season_id')->unique()->sort()->map(fn ($id) => $labels[$id])->values()->all();
            $values = ['Mitglied.Vorname' => (string) $member->first_name, 'Mitglied.Nachname' => (string) $member->last_name,
                'Mitglied.Adresse' => implode(', ', array_filter([trim($member->street.' '.$member->house_number), trim($member->postal_code.' '.$member->city)])),
                'Mitglied.Email' => (string) $member->email, 'Team.Name' => implode(', ', $names), 'Dokument.Datum' => date('d.m.Y', strtotime($input['date']))];
            $values = array_replace($values, $common, $individual[$member->id] ?? []);
            $snapshot = ['member_id' => (int) $member->id, 'member_name' => $member->display_name, 'club' => $tenant->name,
                'date' => $input['date'], 'values' => $values, 'portal_visible' => (bool) $input['portal_visible']];
            try {
                $snapshot['definition'] = app(DocumentSeriesRenderer::class)->personalize($d, $values);
            } catch (ValidationException $e) {
                $valid = false;
                $snapshot['error'] = $e->validator->errors()->first();
            }
            $snapshots[] = $snapshot;
        }
        $result = ['template_id' => (int) $template->id, 'template_hash' => $template->definition_hash, 'recipients' => $snapshots];

        return ['can_create' => $valid, 'hash' => $valid ? DocumentSeriesRenderer::hash($result) : null, 'snapshot' => $result];
    }

    private function extras(array $values): array
    {
        foreach ($values as $key => $value) {
            abort_unless(in_array($key, DocumentSeriesRenderer::EXTRA, true) && is_string($value) && mb_strlen($value) <= 160, 422, 'Individuelle Angabe ungültig.');
        }

        return $values;
    }

    public function create(Account $actor, Tenant $tenant, array $input, string $hash, string $requestKey): DocumentSeries
    {
        abort_unless(preg_match('/^[a-zA-Z0-9-]{16,64}$/D', $requestKey) && preg_match('/^[a-f0-9]{64}$/D', $hash), 422);
        $requestHash = DocumentSeriesRenderer::hash(['input' => $input, 'preview' => $hash]);

        return $this->transaction($tenant, function () use ($actor, $tenant, $input, $hash, $requestKey, $requestHash) {
            $this->authorize($actor, $tenant, 'create', 'upload', 'send');
            $old = DocumentSeries::where('tenant_id', $tenant->id)->where('request_key', $requestKey)->first();
            if ($old) {
                abort_unless((int) $old->created_by === (int) $actor->id && hash_equals($old->request_hash, $requestHash), 409);

                return $old;
            }
            $preview = $this->preview($actor, $tenant, $input);
            abort_unless($preview['can_create'] && hash_equals($preview['hash'], $hash), 409, 'Vorschau veraltet. Bitte erneut prüfen.');
            $snapshot = $preview['snapshot'];
            $people = $snapshot['recipients'];
            unset($snapshot['recipients']);
            $series = new DocumentSeries;
            $series->forceFill(['tenant_id' => $tenant->id, 'created_by' => $actor->id, 'template_id' => $snapshot['template_id'],
                'request_key' => $requestKey, 'request_hash' => $requestHash, 'snapshot' => $snapshot, 'snapshot_hash' => DocumentSeriesRenderer::hash($snapshot)])->save();
            foreach ($people as $person) {
                $row = new DocumentSeriesRecipient;
                $row->forceFill(['tenant_id' => $tenant->id, 'series_id' => $series->id, 'member_id' => $person['member_id'],
                    'snapshot' => $person, 'snapshot_hash' => DocumentSeriesRenderer::hash($person)])->save();
            }
            $this->audit($actor, $tenant, 'document_series', $series->id, 'created');

            return $series;
        });
    }

    public function series(Account $actor, Tenant $tenant, int $id): DocumentSeries
    {
        $this->authorize($actor, $tenant);
        $series = DocumentSeries::where('tenant_id', $tenant->id)->findOrFail($id);
        abort_unless(hash_equals($series->snapshot_hash, DocumentSeriesRenderer::hash($series->snapshot)), 409);

        return $series;
    }

    public function recipients(Account $actor, Tenant $tenant, int $series)
    {
        return $this->series($actor, $tenant, $series)->recipients()->where('tenant_id', $tenant->id)->with('receipt')->orderBy('id')->get();
    }

    private function recipient(Account $actor, Tenant $tenant, int $series, int $id): DocumentSeriesRecipient
    {
        $row = $this->series($actor, $tenant, $series)->recipients()->where('tenant_id', $tenant->id)->findOrFail($id);
        abort_unless(hash_equals($row->snapshot_hash, DocumentSeriesRenderer::hash($row->snapshot)), 409);

        return $row;
    }

    /** Claim and stage durably, publish outside the transaction, then create one receipt. */
    public function process(Account $actor, Tenant $tenant, int $series): array
    {
        $this->authorize($actor, $tenant, 'create', 'upload', 'send');
        $claim = $this->transaction($tenant, function () use ($actor, $tenant, $series) {
            $this->authorize($actor, $tenant, 'create', 'upload', 'send');
            $row = $this->series($actor, $tenant, $series)->recipients()->where('tenant_id', $tenant->id)->whereNull('document_id')->orderBy('id')->lockForUpdate()->first();
            if (! $row) {
                return null;
            }
            if ($row->lease_until?->isFuture()) {
                return false;
            }
            $row = $this->recipient($actor, $tenant, $series, $row->id);
            $token = (string) Str::uuid();
            if (! $row->upload_id) {
                $bytes = app(DocumentSeriesRenderer::class)->pdf($row->snapshot);
                $file = $this->temporaryPdf($bytes);
                try {
                    $upload = app(DocumentUploadService::class)->stage($actor, $tenant, 'member', $row->member_id,
                        ['type' => $row->snapshot['definition']['type_id'], 'portal_visible' => $row->snapshot['portal_visible'],
                            'document_series_recipient' => $row->id], $file);
                } finally {
                    @unlink($file->getPathname());
                }
                $row->forceFill(['upload_id' => $upload, 'original_sha256' => hash('sha256', $bytes)]);
            }
            $row->forceFill(['lease_token' => $token, 'lease_until' => now()->addMinutes(10), 'last_error' => null])->save();

            return [$row, $token];
        });
        if ($claim === null || $claim === false) {
            return ['done' => $claim === null, 'busy' => $claim === false];
        }
        [$row, $token] = $claim;
        try {
            // Never put the Azure call inside the receipt transaction: stage survives failure.
            $documentId = app(SyncWorkService::class)->runDocument($actor, $tenant, $row->upload_id);

            return $this->transaction($tenant, function () use ($actor, $tenant, $series, $row, $token, $documentId) {
                $this->authorize($actor, $tenant, 'create', 'upload', 'send');
                $fresh = $this->recipient($actor, $tenant, $series, $row->id);
                abort_unless($fresh->lease_token === $token, 409);
                $this->document($tenant, $fresh, $documentId, $fresh->original_sha256);
                $snapshot = $fresh->snapshot;
                $d = $snapshot['definition'];
                $items = array_map(fn ($i) => ['description' => $i['description'], 'quantity' => $i['quantity'],
                    'reference' => mb_substr(trim($i['reference'].' '.$i['size'].' '.$i['initials']), 0, 120),
                    'condition' => 'CHF '.number_format($i['price_cents'] / 100, 2, '.', '').' / Stk.'], $d['items']);
                if (! $items) {
                    $items = [['description' => $d['title'], 'quantity' => 1, 'reference' => $snapshot['values']['Schluessel.Nummer'] ?? '', 'condition' => 'Gemaess Originaldokument']];
                }
                $receipt = null;
                // Existing issued material receipts are always portal-visible. A private-only
                // dossier document must not accidentally publish a second portal record.
                if ($snapshot['portal_visible']) {
                    $receipts = app(MaterialReceiptService::class);
                    $receipt = $receipts->save($actor, $tenant, null, ['revision' => 0, 'member_id' => $fresh->member_id,
                        'title' => $d['title'], 'handover_on' => $snapshot['date'], 'items' => $items,
                        'note' => mb_substr($d['text'], 0, 3000)]);
                    $receipt = $receipts->transition($actor, $tenant, $receipt->id, $receipt->revision, 'issue');
                    // The existing portal renderer/acceptance consumes this standard snapshot.
                    // Replace mutable issue-time identity with the approved series snapshot.
                    $evidence = $receipt->snapshot;
                    $evidence['club'] = $snapshot['club'];
                    $evidence['member'] = $snapshot['member_name'];
                    $evidence['note'] = $d['text'];
                    $evidence['series_original_document_id'] = $documentId;
                    $evidence['series_definition'] = $d;
                    $receipt->forceFill(['note' => $d['text'], 'snapshot' => $evidence, 'snapshot_hash' => DocumentSeriesRenderer::hash($evidence)])->save();
                }
                $fresh->forceFill(['document_id' => $documentId, 'receipt_id' => $receipt?->id, 'lease_token' => null, 'lease_until' => null, 'last_error' => null])->save();
                $this->audit($actor, $tenant, 'document_series_recipients', $fresh->id, 'issued');

                return ['done' => ! $this->series($actor, $tenant, $series)->recipients()->whereNull('document_id')->exists(), 'recipient_id' => $fresh->id, 'receipt_id' => $receipt?->id];
            });
        } catch (\Throwable $e) {
            DocumentSeriesRecipient::where('tenant_id', $tenant->id)->whereKey($row->id)->where('lease_token', $token)
                ->update(['lease_token' => null, 'lease_until' => null, 'last_error' => 'retry_required']);
            throw $e;
        }
    }

    private function document(Tenant $tenant, DocumentSeriesRecipient $row, int $id, string $hash): MemberDocument
    {
        $doc = MemberDocument::where('tenant_id', $tenant->id)->where('member_id', $row->member_id)->where('active', true)->findOrFail($id);
        abort_unless(hash_equals($hash, $doc->sha256) && (int) $doc->document_type_id === (int) $row->snapshot['definition']['type_id'], 409);

        return $doc;
    }

    private function temporaryPdf(string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'clubunio-series-');
        abort_unless($path !== false, 503);
        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            @unlink($path);
            abort(503);
        }

        return new UploadedFile($path, 'Dokument.pdf', 'application/pdf', null, true);
    }

    public function signedReturn(Account $actor, Tenant $tenant, int $series, int $recipient, UploadedFile $file, bool $checked, string $originalHash): DocumentSeriesRecipient
    {
        $this->authorize($actor, $tenant, 'upload', 'update');
        Validator::make(['file' => $file, 'checked' => $checked], ['file' => 'required|file|max:10240|mimetypes:application/pdf', 'checked' => 'accepted'])->validate();
        $sha = hash('sha256', $file->get());
        $row = $this->transaction($tenant, function () use ($actor, $tenant, $series, $recipient, $file, $originalHash, $sha) {
            $this->authorize($actor, $tenant, 'upload', 'update');
            $row = $this->recipient($actor, $tenant, $series, $recipient);
            abort_unless($row->document_id && $row->snapshot['definition']['signature_required'] && hash_equals($row->original_sha256, $originalHash), 409);
            abort_if(hash_equals($row->original_sha256, $sha), 422, 'Rückgabe ist identisch mit dem Original.');
            if ($row->signed_upload_id) {
                abort_unless(hash_equals($row->signed_sha256, $sha), 409, 'Andere Rückgabe bereits vorhanden.');

                return $row;
            }
            $upload = app(DocumentUploadService::class)->stage($actor, $tenant, 'member', $row->member_id,
                ['type' => $row->snapshot['definition']['type_id'], 'portal_visible' => $row->snapshot['portal_visible'],
                    'document_series_signed_recipient' => $row->id], $file);
            $row->forceFill(['signed_upload_id' => $upload, 'signed_sha256' => $sha, 'checked_by' => $actor->id, 'checked_at' => now()])->save();
            $this->audit($actor, $tenant, 'document_series_recipients', $row->id, 'checked_return_staged');

            return $row;
        });

        return $this->finishSignedReturn($actor, $tenant, $series, $row->id);
    }

    public function finishSignedReturn(Account $actor, Tenant $tenant, int $series, int $recipient): DocumentSeriesRecipient
    {
        $this->authorize($actor, $tenant, 'upload', 'update');
        $row = $this->recipient($actor, $tenant, $series, $recipient);
        abort_unless($row->signed_upload_id, 409);
        if ($row->signed_document_id) {
            return $row;
        }
        $document = app(SyncWorkService::class)->runDocument($actor, $tenant, $row->signed_upload_id);

        return $this->transaction($tenant, function () use ($actor, $tenant, $series, $recipient, $document) {
            $this->authorize($actor, $tenant, 'upload', 'update');
            $row = $this->recipient($actor, $tenant, $series, $recipient);
            $doc = $this->document($tenant, $row, $document, $row->signed_sha256);
            abort_unless((int) $doc->created_by === (int) $row->checked_by, 409, 'Uploadernachweis stimmt nicht.');
            if (! $row->signed_document_id) {
                $row->forceFill(['signed_document_id' => $document])->save();
                $this->audit($actor, $tenant, 'document_series_recipients', $row->id, 'checked_return_published');
            }

            return $row;
        });
    }

    /** Private route hook; portal callers use existing represented-member scope. */
    public function download(Account $actor, Tenant $tenant, int $series, int $recipient, bool $signed = false, bool $portal = false)
    {
        if ($portal) {
            $access = app(MemberAccessService::class);
            abort_unless($actor->fresh() && $access->canEnter($actor->fresh(), $tenant), 403);
            $row = DocumentSeriesRecipient::where('tenant_id', $tenant->id)->where('series_id', $series)
                ->whereIn('member_id', $access->represented($actor->fresh(), $tenant)->select('clubora.members.id'))->findOrFail($recipient);
            $parent = DocumentSeries::where('tenant_id', $tenant->id)->findOrFail($series);
            abort_unless(hash_equals($parent->snapshot_hash, DocumentSeriesRenderer::hash($parent->snapshot))
                && hash_equals($row->snapshot_hash, DocumentSeriesRenderer::hash($row->snapshot)), 409);
            abort_unless($row->snapshot['portal_visible'], 404);
        } else {
            $this->authorize($actor, $tenant, 'download');
            $row = $this->recipient($actor, $tenant, $series, $recipient);
        }
        $id = $signed ? $row->signed_document_id : $row->document_id;
        abort_unless($id, 404);
        $hash = $signed ? $row->signed_sha256 : $row->original_sha256;
        $this->document($tenant, $row, $id, $hash);
        $response = $portal ? app(MemberDocumentService::class)->openPortal($actor, $tenant, $id) : app(MemberDocumentService::class)->open($actor, $tenant, $id);
        abort_unless(hash_equals($hash, hash('sha256', $response->getContent())), 409, 'Dateipruefsumme stimmt nicht.');

        return $response;
    }

    public function history(Account $actor, Tenant $tenant, string $search = '', int $page = 1)
    {
        $this->authorize($actor, $tenant);

        return DocumentSeries::where('tenant_id', $tenant->id)->withCount(['recipients', 'recipients as completed_count' => fn ($q) => $q->whereNotNull('document_id')])
            ->when($search !== '', fn ($q) => $q->whereIn('template_id', DocumentSeriesTemplate::where('tenant_id', $tenant->id)
                ->whereRaw('name ILIKE ? ESCAPE \'!\'', ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%'])->select('id')))
            ->orderByDesc('id')->paginate(25, ['*'], 'seriesPage', max(1, $page));
    }

    private function audit(Account $actor, Tenant $tenant, string $type, int $id, string $action): void
    {
        DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
            'object_type' => $type, 'object_id' => $id, 'after_value' => json_encode(['action' => $action], JSON_THROW_ON_ERROR)]);
    }
}

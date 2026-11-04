<?php
namespace App\ClubUnio\Mobile;

use App\ClubUnio\Models\{Account, ExpenseLine, Tenant};
use Illuminate\Support\Facades\{Crypt, DB};

/** Only a successful owned provider result can certify an OCR amount. Rule suggestions are separate. */
final class MobileExpenseOcrProvenance
{
    public static function units(string $amount): int
    {
        if (! preg_match('/^\d{1,9}(?:\.\d{1,4})?$/D', $amount)) throw new \InvalidArgumentException('Invalid fixed precision amount');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return (int) $whole * 10000 + (int) str_pad($fraction, 4, '0');
    }

    private function table(Tenant $tenant) { return DB::connection('clubunio')->table('clubora.mobile_expense_ocr_provenance')->where('tenant_id', $tenant->id); }

    private function sourceAttached(Tenant $tenant, ExpenseLine $line, array $proof): bool
    {
        return $this->sourcesAttached($tenant, [(int) $line->id => ['line' => $line, 'proof' => $proof]])[(int) $line->id];
    }

    /** Batch source metadata only; pending payload existence is a SQL boolean. */
    private function sourcesAttached(Tenant $tenant, array $pairs): array
    {
        if (!$pairs) return [];
        $draftIds = array_values(array_unique(array_map(fn ($pair) => $pair['proof']['draft_id'], $pairs)));
        $drafts = app(MobileExpenseReceipts::class)->table($tenant)->whereIn('id', $draftIds)
            ->get(['id','account_id','state','bound_line_id','sha256','bound_upload_id','revision'])->keyBy('id');
        $uploads = DB::connection('clubunio')->table('clubora.document_uploads')->where('tenant_id', $tenant->id)->where('kind', 'expense')
            ->whereIn('id', $drafts->pluck('bound_upload_id')->filter()->unique())
            ->get(['id','object_id','state','sha256','folder','filename',new \Illuminate\Database\Query\Expression('encrypted_bytes IS NOT NULL AS has_bytes')])->keyBy('id');
        $result = [];
        foreach ($pairs as $id => $pair) {
            $line = $pair['line']; $proof = $pair['proof']; $draft = $drafts[$proof['draft_id']] ?? null;
            $upload = $draft ? ($uploads[$draft->bound_upload_id] ?? null) : null;
            $matches = $draft && (int) $draft->account_id === (int) $proof['ocr_account_id'] && $draft->state === 'bound'
                && (int) $draft->bound_line_id === (int) $line->id && (int) $draft->revision === (int) $proof['draft_revision'] + 1
                && hash_equals($draft->sha256, $proof['source_sha256']) && $upload && (int) $upload->object_id === (int) $line->id
                && hash_equals($upload->sha256, $proof['source_sha256']);
            $result[$id] = $matches && ($upload->state === 'pending' ? $upload->has_bytes === true
                : ($upload->state === 'published' && in_array($upload->folder.$upload->filename,
                    array_filter(array_map('trim', explode(',', (string) $line->storage_reference))), true)));
        }
        return $result;
    }

    public function claim(Account $actor, Tenant $tenant, array $input): ?array
    {
        if (! isset($input['ocr_work_id'])) return null;
        abort_unless(config('clubunio-expense-provenance.enabled', false), 503, 'OCR-Betragsnachweis noch nicht freigeschaltet.');
        try { $draft = app(MobileExpenseReceipts::class)->owned($actor, $tenant, $input['receipt_token']); }
        catch (\Illuminate\Database\RecordNotFoundException) { abort(404); }
        abort_unless($draft->state === 'clean' && (int) $draft->revision === (int) $input['receipt_revision']
            && (int) $draft->revision === (int) $input['ocr_source_revision'] && $draft->scanned_at && $draft->scanner_version !== ''
            && now()->isBefore($draft->expires_at), 409, 'OCR-Belegquelle ist nicht mehr aktuell.');
        $work = DB::connection('clubunio')->table('clubora.mobile_ai_work')->where('tenant_id', $tenant->id)->where('account_id', $actor->id)
            ->where('id', $input['ocr_work_id'])->sharedLock()->first();
        abort_unless($work && $work->operation === 'expense_ocr' && $work->provider === 'azure_document_intelligence'
            && $work->model === AzureExpenseOcrProvider::MODEL.'@'.AzureExpenseOcrProvider::VERSION
            && $work->state === 'succeeded' && $work->result_encrypted && (int) $work->revision === (int) $draft->revision, 409, 'Kein passender erfolgreicher OCR-Auftrag.');
        try { $value = json_decode(Crypt::decryptString($work->result_encrypted), true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { abort(409, 'OCR-Ergebnis kann nicht bestaetigt werden.'); }
        $source = $value['_source'] ?? null;
        abort_unless(is_array($source) && ($source['draft_id'] ?? null) === $draft->id && ($source['draft_revision'] ?? null) === (int) $draft->revision
            && is_string($source['sha256'] ?? null) && hash_equals($draft->sha256, $source['sha256'])
            && ($source['scanner_version'] ?? null) === $draft->scanner_version
            && ($source['scanned_at'] ?? null) === \Carbon\CarbonImmutable::parse($draft->scanned_at)->utc()->toIso8601String()
            && ($value['meta']['provider'] ?? null) === 'azure_document_intelligence' && ($value['meta']['model'] ?? null) === AzureExpenseOcrProvider::MODEL
            && ($value['meta']['pages'] ?? null) === 1, 409, 'OCR-Quelle stimmt nicht mit dem gescannten Beleg ueberein.');
        $suggested = $value['suggestion']['Betrag'] ?? null;
        abort_unless(is_int($suggested) || is_float($suggested), 409, 'OCR enthaelt keinen belegten Betrag.');
        abort_unless(is_finite((float) $suggested) && $suggested > 0 && $suggested <= 999999999.99
            && abs($suggested * 100 - round($suggested * 100)) < 0.000001, 409);
        $amount = number_format((float) $suggested, 4, '.', '');
        abort_unless(self::units((string) $input['ai_suggested_amount']) === self::units($amount), 409, 'Angezeigter OCR-Betrag stimmt nicht ueberein.');
        return ['ocr_account_id' => (int) $actor->id, 'work_id' => $work->id, 'draft_id' => $draft->id,
            'draft_revision' => (int) $draft->revision, 'source_sha256' => $draft->sha256, 'suggested_amount' => $amount, 'source_replaced' => false];
    }

    public function record(Account $actor, Tenant $tenant, ExpenseLine $line, ?array $claim, bool $newReceipt): void
    {
        if (! config('clubunio-expense-provenance.enabled', false) || ! $line->active) return;
        if ($claim !== null) {
            $bound = app(MobileExpenseReceipts::class)->table($tenant)->where('id', $claim['draft_id'])->where('account_id', $claim['ocr_account_id'])->first();
            abort_unless($bound && $bound->state === 'bound' && (int) $bound->bound_line_id === (int) $line->id
                && (int) $bound->revision === $claim['draft_revision'] + 1 && hash_equals($bound->sha256, $claim['source_sha256']), 409, 'OCR-Beleg wurde nicht an diese Position gebunden.');
            abort_unless($this->sourceAttached($tenant, $line, $claim), 409, 'Der bestaetigte OCR-Beleg ist dieser Position nicht zugeordnet.');
        } else {
            $previous = $this->table($tenant)->where('line_id', $line->id)->orderByDesc('line_revision')->first();
            if (! $previous) return;
            $claim = array_intersect_key((array) $previous, array_flip(['ocr_account_id', 'work_id', 'draft_id', 'draft_revision', 'source_sha256', 'suggested_amount', 'source_replaced']));
            $claim['source_replaced'] = $newReceipt || (bool) $previous->source_replaced;
        }
        $claim['source_replaced'] = $claim['source_replaced'] || ! $this->sourceAttached($tenant, $line, $claim);
        $accepted = ! $claim['source_replaced'] && self::units((string) $line->amount) === self::units((string) $claim['suggested_amount']);
        $this->table($tenant)->insert($claim + ['tenant_id' => $tenant->id, 'line_id' => $line->id, 'line_revision' => $line->revision,
            'created_by' => $actor->id, 'saved_amount' => (string) $line->amount, 'accepted' => $accepted]);
    }

    public function fields(Tenant $tenant, ExpenseLine $line): array
    {
        return $this->fieldsForLines($tenant, [$line])[(int) $line->id] ?? ['AiBetragUebernommen' => null];
    }

    public function fieldsForLines(Tenant $tenant, iterable $lines): array
    {
        $lines = collect($lines)->filter(fn ($line) => (int) $line->id > 0)->keyBy('id');
        $result = $lines->map(fn () => ['AiBetragUebernommen' => null])->all();
        if (!config('clubunio-expense-provenance.enabled', false) || $lines->isEmpty()) return $result;
        $latest = $this->table($tenant)->whereIn('line_id', $lines->keys())->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('clubora.mobile_expense_ocr_provenance as newer')
                ->whereColumn('newer.tenant_id', 'clubora.mobile_expense_ocr_provenance.tenant_id')
                ->whereColumn('newer.line_id', 'clubora.mobile_expense_ocr_provenance.line_id')
                ->whereColumn('newer.line_revision', '>', 'clubora.mobile_expense_ocr_provenance.line_revision');
        })->get();
        $pairs = [];
        foreach ($latest as $row) if (!$row->source_replaced) {
            $pairs[(int) $row->line_id] = ['line' => $lines[$row->line_id], 'proof' => (array) $row];
        }
        $attached = $this->sourcesAttached($tenant, $pairs);
        foreach ($latest as $row) {
            $line = $lines[$row->line_id]; $current = $attached[(int) $row->line_id] ?? false;
            $result[(int) $row->line_id] = ['AiBetragUebernommen' => $current && self::units((string) $line->amount) === self::units((string) $row->suggested_amount),
                'AiVorschlagBetrag' => $current ? (string) $row->suggested_amount : null];
        }
        return $result;
    }
}

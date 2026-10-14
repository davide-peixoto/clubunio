<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\BexioCatalogEntry;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\Tenant;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class InvoiceTaxService
{
    public function invoiceDate(Invoice $invoice): string
    {
        return CarbonImmutable::parse($invoice->created_at)->setTimezone('Europe/Zurich')->toDateString();
    }

    public function options(Tenant $tenant, ?string $date = null): array
    {
        $hash = app(BexioSandboxFixtureService::class)->optionalConnectionHash($tenant);
        if ($hash === null) {
            return [];
        }

        return BexioCatalogEntry::where('tenant_id', $tenant->id)->where('kind', 'tax')->where('active', true)->where('provider_active', true)
            ->where('connection_hash', $hash)
            ->when($date, fn ($q) => $q->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $date)))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    public function snapshot(Tenant $tenant, int $id, string $date): array
    {
        $row = BexioCatalogEntry::where('tenant_id', $tenant->id)->where('kind', 'tax')->where('active', true)->where('provider_active', true)
            ->where('connection_hash', app(BexioSandboxFixtureService::class)->connectionHash($tenant))->find($id);
        if (! $row || $row->rate_basis_points === null || ! in_array($row->tax_type, ['sales_tax', 'not_taxable_turnover'], true)
            || ($row->valid_from && $date < $row->valid_from->toDateString()) || ($row->valid_until && $date > $row->valid_until->toDateString())) {
            throw ValidationException::withMessages(['bexio_catalog_tax_id' => 'Steuerzuordnung ist für diese Rechnung nicht gültig.']);
        }

        return ['remote_id' => (int) $row->remote_id, 'rate_basis_points' => (int) $row->rate_basis_points,
            'type' => $row->tax_type, 'code' => $row->code, 'connection_hash' => $row->connection_hash];
    }

    public function calculate(array $lines, bool $inclusive, bool $cashRounding = false): array
    {
        $total = 0;
        $tax = BigDecimal::zero();
        $complete = true;
        foreach ($lines as $line) {
            $cents = $line['cents'];
            if (! is_int($cents) || $cents < 0 || $cents > 99999999999) {
                throw ValidationException::withMessages(['gross_amount' => 'Ungültiger Endbetrag.']);
            }
            $total += $cents;
            if (! $inclusive) {
                continue;
            }
            $snapshot = $line['tax'] ?? null;
            if (! is_array($snapshot)) {
                $complete = false;

                continue;
            }
            $rate = $snapshot['rate_basis_points'] ?? null;
            if (! is_int($rate) || $rate < 0 || $rate > 10000 || ! in_array($snapshot['type'] ?? '', ['sales_tax', 'not_taxable_turnover'], true)) {
                throw ValidationException::withMessages(['bexio_catalog_tax_id' => 'Steuersnapshot ist ungültig.']);
            }
            $tax = $tax->plus(BigDecimal::of($cents)->dividedBy(100, 2)->multipliedBy($rate)->dividedBy(10000 + $rate, 6, RoundingMode::HALF_UP));
        }
        $rounding = $inclusive || $cashRounding ? intdiv($total + 2, 5) * 5 - $total : 0;
        // Bexio gross pricing allocates cash rounding proportionally to the included VAT.
        $theoretical = $tax;
        if ($inclusive && $total > 0) {
            $tax = $tax->multipliedBy($total + $rounding)->dividedBy($total, 6, RoundingMode::HALF_UP);
        }

        return ['unrounded_cents' => $total, 'rounding_cents' => $rounding, 'total_cents' => $total + $rounding,
            'tax' => (string) $tax->toScale(6), 'theoretical_tax' => (string) $theoretical->toScale(6),
            'exclusive' => (string) BigDecimal::of($total)->dividedBy(100, 2)->minus($tax)->toScale(6), 'complete' => $complete];
    }

    public function summary(Invoice $invoice, bool $strict = false): array
    {
        if ($invoice->tax_mode !== 'inclusive' && ! $invoice->cash_rounding) {
            return $this->calculate([['cents' => app(BexioBalanceService::class)->cents((string) $invoice->activeLines()->sum('net_amount'))]], false);
        }
        $money = app(InvoiceWorkflowService::class);
        $summary = $this->calculate($invoice->activeLines()->get()->map(fn ($line) => [
            'cents' => $money->cents((string) ($line->net_amount ?? '0')), 'tax' => $line->tax_snapshot])->all(), $invoice->tax_mode === 'inclusive', (bool) $invoice->cash_rounding);
        if ($strict && ! $summary['complete']) {
            throw ValidationException::withMessages(['revision' => 'Jede Position benötigt eine ausdrückliche Steuerzuordnung, auch bei 0 %.']);
        }

        return $summary;
    }

    public function assertRemote(Invoice $invoice, array $payload, array $remote): void
    {
        $summary = $this->summary($invoice, true);
        $inclusive = $invoice->tax_mode === 'inclusive';
        abort_unless((int) ($remote['mwst_type'] ?? -1) === ($inclusive ? 0 : 2)
            && (int) ($payload['mwst_type'] ?? -1) === ($inclusive ? 0 : 2), 409, 'Steuermodus stimmt nicht überein.');
        abort_unless(app(BexioBalanceService::class)->cents($remote['total'] ?? null) === $summary['total_cents'], 409, 'Endbetrag stimmt nicht überein.');
        try {
            $actual = BigDecimal::of((string) ($remote['total_taxes'] ?? '-1'));
            $difference = $actual->minus($summary['tax'])->abs();
            abort_unless($difference->isLessThanOrEqualTo('0.0002'), 409, 'Steuerbetrag stimmt nicht überein.');
            if ($inclusive) {
                abort_unless(($remote['mwst_is_net'] ?? null) === false
                    && app(BexioBalanceService::class)->cents($remote['total_gross'] ?? null) === $summary['unrounded_cents']
                    && (BigDecimal::of((string) ($remote['total_rounding_difference'] ?? '999'))->isZero()
                        || BigDecimal::of((string) ($remote['total_rounding_difference'] ?? '999'))->multipliedBy(100)->isEqualTo($summary['rounding_cents'])),
                    409, 'Rundung oder Preisart stimmt nicht überein.');
                $remotePositions = array_values(array_filter($remote['positions'] ?? [], fn ($position) => in_array($position['type'] ?? '', ['KbPositionCustom', 'KbPositionArticle'], true)));
                abort_unless(count($remotePositions) === count($payload['positions']), 409, 'Rechnungspositionen wurden extern geändert.');
                foreach ($payload['positions'] as $index => $position) {
                    abort_unless((int) ($remotePositions[$index]['tax_id'] ?? 0) === (int) $position['tax_id']
                        && ($remotePositions[$index]['type'] ?? '') === $position['type']
                        && BigDecimal::of((string) ($remotePositions[$index]['amount'] ?? '-1'))->isEqualTo($position['amount'])
                        && BigDecimal::of((string) ($remotePositions[$index]['discount_in_percent'] ?? '0'))->isZero()
                        && BigDecimal::of((string) ($remotePositions[$index]['unit_price'] ?? '-1'))->isEqualTo($position['unit_price']),
                        409, 'Steuer- oder Preiszuordnung wurde extern geändert.');
                }
            }
        } catch (MathException) {
            abort(409, 'Ungültiger Steuerbetrag.');
        }
    }

    public function verifyProvider(Invoice $invoice, string $token): void
    {
        if ($invoice->tax_mode !== 'inclusive') {
            return;
        }
        $taxes = app(BexioSandboxClient::class)->get($token, '/3.0/taxes');
        foreach ($invoice->activeLines()->get() as $line) {
            $snapshot = $line->tax_snapshot;
            $matches = array_values(array_filter($taxes, fn ($tax) => (int) ($tax['id'] ?? 0) === (int) ($snapshot['remote_id'] ?? -1)));
            abort_unless(count($matches) === 1 && ($matches[0]['is_active'] ?? false) === true
                && ($matches[0]['type'] ?? '') === ($snapshot['type'] ?? '') && ($matches[0]['code'] ?? '') === ($snapshot['code'] ?? ''),
                409, 'Steuerzuordnung wurde in Bexio geändert.');
            abort_unless(BigDecimal::of((string) $matches[0]['value'])->multipliedBy(100)->isEqualTo($snapshot['rate_basis_points']), 409, 'Steuersatz wurde in Bexio geändert.');
            $definition = app(BexioCatalogService::class)->normalizeTax($matches[0]);
            $date = $this->invoiceDate($invoice);
            abort_unless((! $definition['valid_from'] || $date >= $definition['valid_from'])
                && (! $definition['valid_until'] || $date <= $definition['valid_until']), 409, 'Steuergültigkeit wurde in Bexio geändert.');
        }
    }
}

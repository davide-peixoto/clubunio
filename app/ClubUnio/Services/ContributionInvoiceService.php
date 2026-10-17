<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Account;
use App\ClubUnio\Models\ContributionRule;
use App\ClubUnio\Models\FamilyMember;
use App\ClubUnio\Models\Invoice;
use App\ClubUnio\Models\InvoiceCategory;
use App\ClubUnio\Models\InvoiceLine;
use App\ClubUnio\Models\InvoiceLineStatus;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\TeamPlayer;
use App\ClubUnio\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContributionInvoiceService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['contribution' => $message]);
    }

    private function cents(string $amount): int
    {
        if (! preg_match('/^(\d{1,12})(?:\.(\d{1,4}))?$/', $amount, $m)) {
            $this->fail('Ungültiger Betrag in den Stammdaten.');
        }
        $fraction = str_pad($m[2] ?? '', 4, '0');

        return (int) $m[1] * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);
    }

    public function preview(Account $actor, Tenant $tenant, int $memberId, int $seasonId): array
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'contributions', 'view');
        $member = app(MemberAccessService::class)->activeMembers($tenant)->findOrFail($memberId);
        $season = Season::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($seasonId);
        $players = $member->teamPlayers()->where('active', true)->whereHas('teamSeason', fn ($q) => $q->where('active', true)->where('season_id', $seasonId))->get(['invoice_line_id']);
        if ($players->isEmpty()) {
            $this->fail('Keine aktive Spielerzuordnung in dieser Saison vorhanden.');
        }
        if (! $member->birth_date || ! $member->gender_id) {
            $this->fail('Geburtsdatum und Geschlecht müssen für die Beitragsberechnung gepflegt sein.');
        }
        $rules = ContributionRule::where('tenant_id', $tenant->id)->where('active', true)
            ->where('season_id', $seasonId)->where('gender_id', $member->gender_id)
            ->where('year_from', '<=', $member->birth_date->year)->where('year_to', '>=', $member->birth_date->year)
            ->whereHas('price', fn ($q) => $q->where('active', true))->with('price')->get();
        $families = FamilyMember::where('tenant_id', $tenant->id)->where('member_id', $memberId)->where('active', true)
            ->whereHas('family', fn ($q) => $q->where('active', true))->get();
        $existing = InvoiceLine::where('tenant_id', $tenant->id)->where('active', true)->where('contribution', true)
            ->whereHas('invoice', fn ($q) => $q->where('active', true)->where('member_id', $memberId)->where('season_id', $seasonId))->get();

        return $this->result($member, $season, $rules, $families, $existing, $players);
    }

    public function previewMany(Account $actor, Tenant $tenant, int $seasonId, array $memberIds): array
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'contributions', 'view');
        abort_if(count($memberIds) > 25, 422);
        if (! $memberIds) {
            return [];
        }
        $season = Season::where('tenant_id', $tenant->id)->where('active', true)->findOrFail($seasonId);
        $members = app(MemberAccessService::class)->activeMembers($tenant)->whereIn('id', $memberIds)->get()->keyBy('id');
        $players = TeamPlayer::where('tenant_id', $tenant->id)->where('active', true)->whereIn('member_id', $memberIds)
            ->whereHas('teamSeason', fn ($q) => $q->where('active', true)->where('season_id', $seasonId))
            ->get(['member_id', 'invoice_line_id'])->groupBy('member_id');
        $rules = ContributionRule::where('tenant_id', $tenant->id)->where('active', true)->where('season_id', $seasonId)
            ->whereHas('price', fn ($q) => $q->where('active', true))->with('price')->get();
        $families = FamilyMember::where('tenant_id', $tenant->id)->where('active', true)->whereIn('member_id', $memberIds)
            ->whereHas('family', fn ($q) => $q->where('active', true))->get()->groupBy('member_id');
        $existing = InvoiceLine::query()->join('clubora.invoices as ci', fn ($join) => $join->on('ci.id', '=', 'clubora.invoice_lines.invoice_id')->on('ci.tenant_id', '=', 'clubora.invoice_lines.tenant_id'))
            ->where('clubora.invoice_lines.tenant_id', $tenant->id)->where('clubora.invoice_lines.active', true)->where('clubora.invoice_lines.contribution', true)
            ->where('ci.active', true)->where('ci.season_id', $seasonId)->whereIn('ci.member_id', $memberIds)
            ->get(['clubora.invoice_lines.*', 'ci.member_id as contribution_member_id'])->groupBy('contribution_member_id');
        $rows = [];
        foreach ($memberIds as $id) {
            // Authorization is fresh for each result, not cached with shared calculation data.
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'contributions', 'view');
            $member = $members->get($id);
            if (! $member) {
                throw (new ModelNotFoundException)->setModel(Member::class, [$id]);
            }
            try {
                $matching = $rules->filter(fn ($rule) => $member->birth_date && (int) $rule->gender_id === (int) $member->gender_id
                    && $rule->year_from <= $member->birth_date->year && $rule->year_to >= $member->birth_date->year)->values();
                $rows[] = $this->result($member, $season, $matching, $families->get($id, collect()), $existing->get($id, collect()), $players->get($id, collect()));
            } catch (ValidationException $error) {
                $rows[] = ['member_id' => $id, 'member' => $member->display_name, 'error' => $error->validator->errors()->first()];
            }
        }

        return $rows;
    }

    private function result(Member $member, Season $season, Collection $rules, Collection $families, Collection $existing, Collection $players): array
    {
        if ($players->isEmpty()) {
            $this->fail('Keine aktive Spielerzuordnung in dieser Saison vorhanden.');
        }
        if (! $member->birth_date || ! $member->gender_id) {
            $this->fail('Geburtsdatum und Geschlecht müssen für die Beitragsberechnung gepflegt sein.');
        }
        if ($rules->count() !== 1) {
            $this->fail($rules->isEmpty() ? 'Kein passender Beitragssatz gefunden.' : 'Mehrere passende Beitragssätze: bitte die Regeln bereinigen.');
        }
        if ($families->count() > 1) {
            $this->fail('Mehrere aktive Familienzuordnungen: bitte den Familienrabatt eindeutig klären.');
        }
        $rule = $rules->sole();
        $gross = $this->cents((string) $rule->price->amount);
        $exempt = $member->honorary || $member->free_membership;
        $discount = $exempt ? $gross : min($gross, $this->cents((string) ($families->first()?->discount ?? '0')));
        if ($existing->count() > 1) {
            $this->fail('Mehrere bestehende Beitragspositionen in dieser Saison: bitte zuerst prüfen.');
        }
        if ($existing->isEmpty() && $players->contains(fn ($player) => $player->invoice_line_id !== null)) {
            $this->fail('Eine Spielerzuordnung hat bereits eine abweichende Rechnungsposition. Bitte zuerst prüfen.');
        }
        $result = ['member_id' => $member->id, 'member' => $member->display_name, 'season_id' => $season->id, 'season' => $season->name,
            'rule_id' => $rule->id, 'price_id' => $rule->price_id, 'price' => $rule->price->name,
            'gross_cents' => $gross, 'discount_cents' => $discount, 'net_cents' => $gross - $discount,
            'discount_reason' => $exempt ? 'Ehren-/Freimitglied' : 'Familienrabatt',
            'bexio_product_id' => $rule->price->bexio_article_id,
            'existing_invoice_id' => $existing->first()?->invoice_id];
        $result['fingerprint'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));

        return $result;
    }

    public function create(Account $actor, Tenant $tenant, int $memberId, int $seasonId, string $fingerprint): Invoice
    {
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'contributions', 'create');
        app(ModulePermissionService::class)->authorize($actor, $tenant, 'invoices', 'create');

        return DB::connection('clubunio')->transaction(function () use ($actor, $tenant, $memberId, $seasonId, $fingerprint) {
            // Catalog writers lock the tenant; member edits also need the member lock.
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'contributions', 'create');
            app(ModulePermissionService::class)->authorize($actor, $tenant, 'invoices', 'create');
            Member::where('tenant_id', $tenant->id)->whereKey($memberId)->lockForUpdate()->firstOrFail();
            $preview = $this->preview($actor, $tenant, $memberId, $seasonId);
            if ($preview['existing_invoice_id']) {
                return Invoice::where('tenant_id', $tenant->id)->findOrFail($preview['existing_invoice_id']);
            }
            if (! hash_equals($preview['fingerprint'], $fingerprint)) {
                $this->fail('Die Berechnungsgrundlagen wurden geändert. Bitte erneut berechnen.');
            }
            // Draft only: no external submission, notification, or payment assertion.
            $status = InvoiceLineStatus::where('tenant_id', $tenant->id)->where('active', true)->where('code', 'P')->first();
            if (! $status) {
                $this->fail('Der aktive Positionsstatus P (Pendent) fehlt.');
            }
            $invoice = new Invoice;
            $invoice->forceFill(['tenant_id' => $tenant->id, 'member_id' => $memberId, 'season_id' => $seasonId,
                'invoice_category_id' => InvoiceCategory::where('tenant_id', $tenant->id)->where('code', 'contribution')->where('active', true)->value('id'),
                'workflow_status_id' => app(InvoiceWorkflowService::class)->status($tenant, 'draft')])->save();
            $money = fn (int $cents) => intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
            $line = new InvoiceLine;
            $line->forceFill(['tenant_id' => $tenant->id, 'invoice_id' => $invoice->id, 'description' => 'Beitrag Saison '.$preview['season'],
                'gross_amount' => $money($preview['gross_cents']), 'discount' => $money($preview['discount_cents']),
                'net_amount' => $money($preview['net_cents']), 'contribution' => true, 'status_id' => $status->id,
                'bexio_product_id' => $preview['bexio_product_id']])->save();
            $players = TeamPlayer::where('tenant_id', $tenant->id)->where('member_id', $memberId)->where('active', true)
                ->whereNull('invoice_line_id')->whereHas('teamSeason', fn ($q) => $q->where('active', true)->where('season_id', $seasonId))->lockForUpdate()->get();
            foreach ($players as $player) {
                $player->forceFill(['invoice_line_id' => $line->id])->save();
                DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                    'object_type' => 'team_players', 'object_id' => $player->id, 'before_value' => json_encode(['invoice_line_id' => null]),
                    'after_value' => json_encode(['invoice_line_id' => $line->id])]);
            }
            DB::connection('clubunio')->table('clubora.object_changes')->insert(['tenant_id' => $tenant->id, 'actor_id' => $actor->id,
                'object_type' => 'invoices', 'object_id' => $invoice->id,
                'after_value' => json_encode(['action' => 'contribution_draft', 'line_id' => $line->id, 'calculation' => $preview], JSON_THROW_ON_ERROR)]);

            return $invoice->refresh();
        });
    }
}

<?php

namespace App\ClubUnio\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class MemberDossierImporter
{
    private $db;

    private int $tenant;

    private array $maps = [];

    public function run(): void
    {
        $this->db = DB::connection('clubunio');
        if ($this->db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new \LogicException('Unexpected target');
        }
        $this->db->transaction(function () {
            $this->db->select('SELECT pg_advisory_xact_lock(20261011)');
            if ($this->db->table('clubora.imports')->where('name', 'member-dossier-v1')->exists()) {
                return;
            }
            $this->tenant = (int) $this->db->table('clubora.tenants')->where('slug', 'sv-sissach')->value('id');
            if (! $this->tenant) {
                throw new \LogicException('Foundation required');
            }
            $this->maps = [];
            foreach (['members', 'seasons', 'teams', 'team_seasons'] as $table) {
                $this->map($table);
            }
            $this->copy('Institut', 'banks', 'InstitutID', fn ($r) => ['name' => $r->Bank ?: $r->Kurzbezeichnung ?: 'Bank '.$r->InstitutID, 'city' => $r->Ort, 'swift' => $r->SWIFT]);
            $this->copy('Funktionen', 'club_functions', 'FunktionsID', fn ($r) => ['name' => $r->Bezeichnung]);
            $this->copy('StaffQualifikationen', 'qualifications', 'QualiID', fn ($r) => ['name' => $r->Qualifikation]);
            $this->copy('SpesenTyp', 'expense_types', 'SpesenTypID', fn ($r) => ['name' => $r->SpesenTyp, 'receipt_required' => $r->BelegZwingend, 'bexio_account_id' => $r->BexioBookingAccountId]);
            $metadata = json_decode(file_get_contents(base_path('deploy/pmp-relations.json')), true, 512, JSON_THROW_ON_ERROR);
            foreach ([['Spesenbelege', 'Status', 'expense_statuses'], ['Spesenbelege', 'Runde', 'expense_rounds'], ['SpesenPositionen', 'Spieltyp', 'game_types'], ['Positionen', 'Status', 'invoice_line_statuses']] as [$table,$field,$target]) {
                $values = collect($metadata['tables'][$table])->firstWhere('FldName', $field)['FldTagValues'] ?? '';
                foreach (explode('|', $values) as $value) {
                    [$code,$label] = str_getcsv($value, ',', '"', '');
                    $this->db->table('clubora.'.$target)->insert(['tenant_id' => $this->tenant, 'code' => $code, 'name' => $label]);
                }
                $this->map($target, 'code');
            }
            foreach ($this->db->table('legacy_prod.BexioStatusMapping')->get() as $r) {
                $this->db->table('clubora.invoice_statuses')->insert(['tenant_id' => $this->tenant, 'code' => $r->BexioStatus, 'name' => $r->StatusName, 'position_code' => $r->PositionStatus]);
            }
            $this->map('invoice_statuses', 'code');
            $this->copy('BankkontoStaff', 'member_bank_accounts', 'BankkontoStaffID', fn ($r) => [
                'member_id' => $this->ref('members', $r->MitgliederID), 'bank_id' => $this->ref('banks', $r->InstitutID), 'iban_encrypted' => Crypt::encryptString($r->IBAN)]);
            $this->copy('Bemerkungen', 'member_notes', 'BemerkungsID', fn ($r) => ['member_id' => $this->ref('members', $r->MitgliederID), 'body' => $r->Bemerkung]);
            $this->copy('Funktionaere', 'member_functions', 'FunktionaerID', fn ($r) => ['member_id' => $this->ref('members', $r->MitgliederID), 'club_function_id' => $this->ref('club_functions', $r->FunktionsID), 'legacy_qualifications' => $r->QualiID]);
            foreach ($this->db->table('legacy_prod.Funktionaere')->get() as $r) {
                foreach (array_unique(array_filter(array_map('trim', explode(',', (string) $r->QualiID)), fn ($v) => $v !== '')) as $id) {
                    $this->db->table('clubora.member_function_qualifications')->insert(['tenant_id' => $this->tenant, 'member_function_id' => $this->ref('member_functions', $r->FunktionaerID), 'qualification_id' => $this->ref('qualifications', $id)]);
                }
            }
            $this->copy('Schluesselliste', 'member_keys', 'SchuesselID', fn ($r) => ['member_id' => $this->ref('members', $r->MitgliederID), 'number' => $r->SchluesselNummer, 'name' => $r->SchluesselBezeichnung, 'color' => $r->{'Schlüsselfarbe'}]);
            $this->copy('SPONSOR', 'sponsorships', 'SponsirID', fn ($r) => ['member_id' => $this->ref('members', $r->MitgliederID), 'name' => $r->SponsorName, 'amount' => $r->Betrag, 'expires_on' => $r->Laufzeit, 'notes' => $r->Bemerkungen]);
            $this->copy('Events', 'club_events', 'EventID', fn ($r) => ['name' => $r->Beschreibung, 'location' => $r->Ort, 'season_id' => $this->ref('seasons', $r->SaisonID), 'team_id' => $this->ref('teams', $r->TeamID)]);
            $this->copy('Gaeste', 'event_guests', 'VisitorID', fn ($r) => ['member_id' => $this->ref('members', $r->MitgliederID), 'club_event_id' => $this->ref('club_events', $r->EventID)]);
            $this->copy('Rechnung', 'invoices', 'RechnungsID', fn ($r) => [
                'member_id' => $this->ref('members', $r->MitgliederID), 'season_id' => $this->ref('seasons', $r->SaisonID),
                'status_id' => $this->code('invoice_statuses', $r->bexioStatus), 'notes' => $r->Bemerkung, 'bexio_invoice_id' => $r->bexioInvoiceId, 'bexio_updated_at' => $r->bexioUpdatedAt]);
            $this->copy('Positionen', 'invoice_lines', 'PositionsID', fn ($r) => [
                'invoice_id' => $this->ref('invoices', $r->RechnungsID), 'description' => $r->Bezeichnung, 'gross_amount' => $r->BruttoBetrag,
                'discount' => $r->Rabatt, 'net_amount' => $r->NettoBetrag, 'notes' => $r->Bemerkung, 'status_id' => $this->code('invoice_line_statuses', $r->Status),
                'contribution' => $r->Beitrag === null ? null : $r->Beitrag === 'J', 'bexio_product_id' => $r->bexioProductId]);
            $this->copy('Spesenbelege', 'expenses', 'SpesenID', fn ($r) => [
                'member_id' => $this->ref('members', $r->MitgliederID), 'season_id' => $this->ref('seasons', $r->SaisonID),
                'team_season_id' => $this->ref('team_seasons', $r->TeamSaisonID), 'bank_account_id' => $this->ref('member_bank_accounts', $r->BankkontoStaffID),
                'status_id' => $this->code('expense_statuses', $r->Status), 'round_id' => $this->code('expense_rounds', $r->Runde),
                'notes' => $r->Bemerkungen, 'released_on' => $r->FreigegebenAm, 'payment_released_on' => $r->ZurZahlungFreigegebenAm, 'paid_on' => $r->BezahltAm,
                'released_by' => $r->FreigegebenVon, 'payment_released_by' => $r->ZurZahlungFreigegebenVon, 'paid_by' => $r->BezahltVon, 'bexio_bill_id' => $r->bexioBillId]);
            $this->copy('SpesenPositionen', 'expense_lines', 'SpesenPositionID', fn ($r) => [
                'expense_id' => $this->ref('expenses', $r->SpesenID), 'expense_type_id' => $this->ref('expense_types', $r->SpesenTypID),
                'game_type_id' => $this->code('game_types', $r->Spieltyp), 'opponent' => $r->Gegner, 'amount' => $r->Betrag, 'suggested_amount' => $r->AiVorschlagBetrag,
                'suggestion_accepted' => $r->AiBetragUebernommen, 'notes' => $r->BemerkungenPos, 'filename' => $r->BelegFileName,
                'storage_reference' => $r->FileUploadName, 'file_size' => $r->BelegLength, 'content_type' => $r->BelegContentType]);
            foreach ($this->db->table('legacy_prod.MitgliederAnmeldungen')->get() as $r) {
                $this->db->table('clubora.registrations')->where('tenant_id', $this->tenant)->where('legacy_id', $r->AnmeldungID)
                    ->update(['member_id' => $this->ref('members', $r->MitgliederID), 'active' => $r->sdeletedAt === null]);
            }
            // TeamList.PositionsID is a financial reference, not a sporting position.
            $this->db->statement('UPDATE clubora.team_players p SET invoice_line_id=l.id FROM clubora.invoice_lines l WHERE p.tenant_id=? AND l.tenant_id=p.tenant_id AND l.legacy_id=p.legacy_invoice_position_id', [$this->tenant]);
            $this->db->table('clubora.imports')->insert(['name' => 'member-dossier-v1']);
        });
    }

    private function map(string $table, string $key = 'legacy_id'): void
    {
        $this->maps[$table] = $this->db->table('clubora.'.$table)->where('tenant_id', $this->tenant)->pluck('id', $key)->all();
    }

    private function ref(string $table, mixed $key): ?int
    {
        if ($key === null || $key === '') {
            return null;
        }

        return $this->maps[$table][$key] ?? throw new \LogicException('Unresolved reference: '.$table.' #'.$key);
    }

    private function code(string $table, mixed $code): ?int
    {
        if ($code === null || trim((string) $code) === '') {
            return null;
        }
        if (! isset($this->maps[$table][$code])) {
            // Preserve historic codes explicitly, never silently map to a current status.
            $this->maps[$table][$code] = $this->db->table('clubora.'.$table)->insertGetId(['tenant_id' => $this->tenant, 'code' => $code, 'name' => 'Unbekannt ('.$code.')', 'active' => false]);
        }

        return $this->maps[$table][$code];
    }

    private function copy(string $source, string $target, string $key, callable $convert): void
    {
        if ($this->db->table('clubora.'.$target)->where('tenant_id', $this->tenant)->exists()) {
            throw new \LogicException('Target populated: '.$target);
        }
        $this->db->table('legacy_prod.'.$source)->orderBy($key)->chunk(200, function ($rows) use ($target, $key, $convert) {
            $batch = [];
            foreach ($rows as $row) {
                $batch[] = array_merge(['tenant_id' => $this->tenant, 'legacy_id' => $row->$key, 'active' => ($row->sdeletedAt ?? null) === null], $convert($row));
            }
            $this->db->table('clubora.'.$target)->insert($batch);
        });
        $this->map($target);
    }
}

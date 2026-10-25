<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new LogicException('Reviewed ClubUnio target required');
        }
        $db->statement("SET LOCAL lock_timeout TO '5s'");
        $db->statement("SET LOCAL statement_timeout TO '60s'");
        // Fixed reviewed web references only; unrelated API schema is excluded.
        $links = [
            ['bexio_sandbox_dispatches', 'source_trial_id', 'bexio_dispatch_trials'],
            ['bus_keybox_codes', 'keybox_id', 'bus_keyboxes'], ['bus_keybox_codes', 'reservation_id', 'bus_reservations'],
            ['bus_keyboxes', 'bus_id', 'buses'], ['credit_note_lines', 'credit_note_id', 'credit_notes'],
            ['credit_note_lines', 'invoice_line_id', 'invoice_lines'], ['credit_notes', 'invoice_id', 'invoices'],
            ['credit_notes', 'member_id', 'members'], ['credit_notes', 'status_id', 'financial_adjustment_statuses'],
            ['customer_credit_allocations', 'customer_credit_id', 'customer_credits'],
            ['document_series_recipients', 'signed_upload_id', 'document_uploads'],
            ['document_series_recipients', 'upload_id', 'document_uploads'],
            ['invoice_lines', 'bexio_catalog_article_id', 'bexio_catalog_entries'],
            ['invoice_lines', 'bexio_catalog_tax_id', 'bexio_catalog_entries'],
            ['invoices', 'invoice_category_id', 'invoice_categories'],
            ['legacy_material_receipts', 'original_document_id', 'member_documents'],
            ['legacy_material_receipts', 'signed_document_id', 'member_documents'],
            ['legacy_training_plans', 'plan_id', 'training_plans'],
            ['legacy_training_plans', 'suggested_team_season_id', 'team_seasons'],
            ['legacy_training_plans', 'team_season_id', 'team_seasons'],
            ['refunds', 'credit_note_id', 'credit_notes'], ['refunds', 'invoice_id', 'invoices'],
            ['refunds', 'status_id', 'financial_adjustment_statuses'],
            ['sponsor_run_public_outbox', 'pending_id', 'sponsor_run_public_pending'],
            ['sponsor_run_public_pending', 'link_id', 'sponsor_run_links'],
            ['training_plans', 'legacy_source_id', 'legacy_training_plans'],
        ];
        foreach ($links as [$source, $column, $target]) {
            $bad = $db->table('clubora.'.$source.' as s')->join('clubora.'.$target.' as t', 't.id', '=', 's.'.$column)
                ->whereColumn('s.tenant_id', '<>', 't.tenant_id')->exists();
            if ($bad) {
                throw new LogicException("Cross-tenant reference: {$source}.{$column}; manual review required, no data repair attempted.");
            }
        }
        foreach (array_unique(array_column($links, 2)) as $table) {
            $existing = $db->selectOne(<<<'SQL'
SELECT count(*) AS n FROM pg_index i
WHERE i.indrelid=CAST(? AS regclass) AND i.indisunique AND i.indisvalid
 AND i.indpred IS NULL AND i.indexprs IS NULL AND i.indnkeyatts=2
 AND (SELECT array_agg(a.attname::text ORDER BY a.attname) FROM pg_attribute a
      WHERE a.attrelid=i.indrelid AND a.attnum=ANY(i.indkey))=ARRAY['id','tenant_id']::text[]
SQL, ['clubora.'.$table]);
            if (! $existing->n) {
                $db->statement("CREATE UNIQUE INDEX tenant_boundary_{$table}_uidx ON clubora.{$table}(tenant_id,id)");
            }
        }
        foreach ($links as [$source, $column, $target]) {
            $name = 'tb_'.substr(hash('sha256', $source.'.'.$column), 0, 24);
            $db->statement("ALTER TABLE clubora.{$source} ADD CONSTRAINT {$name} FOREIGN KEY(tenant_id,{$column}) REFERENCES clubora.{$target}(tenant_id,id)");
        }
    }

    public function down(): void
    {
        throw new LogicException('Security constraints are retained; rollback requires a separately reviewed change.');
    }
};

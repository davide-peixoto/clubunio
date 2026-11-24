<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (! App\ClubUnio\Services\StageMigrationTarget::allows($db)
            && ! (PHP_SAPI === 'cli' && config('app.url') === 'https://dev.clubunio.ch' && $db->getDatabaseName() === 'clubunio_dev')) {
            throw new LogicException('Reviewed ClubUnio target required');
        }
        $db->unprepared($this->sql());
    }

    public function sql(): string
    {
        return <<<'SQL'
CREATE TABLE clubora.veo_recording_adjustments (
 tenant_id bigint NOT NULL, match_id bigint NOT NULL, field_id bigint NOT NULL,
 starts_at timestamptz NOT NULL, ends_at timestamptz NOT NULL,
 basis_hash varchar(64) NOT NULL, revision integer NOT NULL CHECK (revision > 0),
 updated_by bigint NOT NULL, updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (tenant_id, match_id), CHECK (ends_at > starts_at),
 FOREIGN KEY (tenant_id, match_id) REFERENCES clubora.sfv_schedule(tenant_id, match_id),
 FOREIGN KEY (tenant_id, field_id) REFERENCES clubora.training_options(tenant_id, id),
 FOREIGN KEY (tenant_id, updated_by) REFERENCES clubora.tenant_accounts(tenant_id, account_id)
);
CREATE INDEX veo_recording_window ON clubora.veo_recording_adjustments(tenant_id, starts_at);
REVOKE ALL ON clubora.veo_recording_adjustments FROM PUBLIC;
SQL;
    }

    public function down(): void
    {
        throw new LogicException('Retain recording adjustments; reviewed restore required');
    }
};

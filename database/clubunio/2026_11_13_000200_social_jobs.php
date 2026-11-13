<?php

use App\ClubUnio\Services\StageMigrationTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDriverName() !== 'pgsql' || ! StageMigrationTarget::allows($db)) {
            throw new LogicException('Reviewed ClubUnio PostgreSQL required');
        }
        $db->unprepared($this->sql());
    }

    public function sql(): string
    {
        return <<<'SQL'
CREATE TABLE clubunio.social_jobs (
    id uuid PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id),
    name varchar(120) NOT NULL, kind varchar(8) NOT NULL CHECK (kind IN ('day','result')),
    local_time time NOT NULL, team_ids jsonb NOT NULL, targets jsonb NOT NULL,
    template_id uuid NOT NULL, template_revision integer NOT NULL, locale varchar(8) NOT NULL,
    caption varchar(2000) NOT NULL, enabled boolean NOT NULL DEFAULT false,
    auto_publish boolean NOT NULL DEFAULT false, revision integer NOT NULL DEFAULT 1,
    actor_id bigint NOT NULL REFERENCES clubunio.accounts(id), updated_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE(tenant_id,id), FOREIGN KEY(tenant_id,template_id,template_revision)
        REFERENCES clubunio.social_template_versions(tenant_id,template_id,revision)
);
CREATE INDEX social_jobs_enabled ON clubunio.social_jobs(tenant_id,id) WHERE enabled;
CREATE TABLE clubunio.social_job_runs (
    tenant_id bigint NOT NULL, job_id uuid NOT NULL, slot varchar(40) NOT NULL,
    state varchar(24) NOT NULL, post_id uuid, run_date date NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY(tenant_id,job_id,slot),
    FOREIGN KEY(tenant_id,job_id) REFERENCES clubunio.social_jobs(tenant_id,id),
    FOREIGN KEY(tenant_id,post_id) REFERENCES clubunio.social_posts(tenant_id,id)
);
CREATE INDEX social_job_runs_recent ON clubunio.social_job_runs(tenant_id,updated_at DESC);
SQL;
    }

    public function down(): void
    {
        throw new LogicException('Retain scheduled editorial history.');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function sql(): string
    {
        return <<<'SQL'
CREATE TABLE clubunio.social_connections (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id),
 platform varchar(12) NOT NULL CHECK(platform IN ('instagram','facebook')), external_id varchar(80) NOT NULL,
 name varchar(200) NOT NULL, token_encrypted text, expires_at timestamptz NOT NULL,
 connected_by bigint NOT NULL, revision integer NOT NULL DEFAULT 1, active boolean NOT NULL DEFAULT true,
 last_synced_at timestamptz, created_at timestamptz NOT NULL DEFAULT now(),
 UNIQUE(tenant_id,id), UNIQUE(tenant_id,platform,external_id),
 FOREIGN KEY(tenant_id,connected_by) REFERENCES clubunio.tenant_accounts(tenant_id,account_id)
);
CREATE TABLE clubunio.social_imported_posts (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, tenant_id bigint NOT NULL, connection_id bigint NOT NULL,
 external_id varchar(100) NOT NULL, caption text NOT NULL, permalink text, media_type varchar(30) NOT NULL,
 published_at timestamptz NOT NULL, last_observed_at timestamptz NOT NULL,
 metrics jsonb NOT NULL, UNIQUE(tenant_id,connection_id,external_id), UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,connection_id) REFERENCES clubunio.social_connections(tenant_id,id)
);
CREATE INDEX social_imported_posts_date ON clubunio.social_imported_posts(tenant_id,published_at DESC);
CREATE TABLE clubunio.social_metric_snapshots (
 tenant_id bigint NOT NULL, connection_id bigint NOT NULL, post_id bigint NOT NULL,
 day date NOT NULL, sampled_at timestamptz NOT NULL, metrics jsonb NOT NULL,
 PRIMARY KEY(tenant_id,connection_id,post_id,day),
 FOREIGN KEY(tenant_id,connection_id) REFERENCES clubunio.social_connections(tenant_id,id),
 FOREIGN KEY(tenant_id,post_id) REFERENCES clubunio.social_imported_posts(tenant_id,id)
);
CREATE TABLE clubunio.social_account_snapshots (
 tenant_id bigint NOT NULL, connection_id bigint NOT NULL, day date NOT NULL,
 sampled_at timestamptz NOT NULL, followers bigint, PRIMARY KEY(tenant_id,connection_id,day),
 FOREIGN KEY(tenant_id,connection_id) REFERENCES clubunio.social_connections(tenant_id,id)
);
SQL;
    }

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if (! \App\ClubUnio\Services\CampaignMigrationTarget::allows($db)) throw new LogicException('Unexpected social migration target');
        $db->unprepared($this->sql());
    }

    public function down(): void
    {
        throw new LogicException('Retain account and metric history; reviewed restore required');
    }
};

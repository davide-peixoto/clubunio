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
CREATE TABLE clubunio.social_media (
    id uuid PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id),
    name varchar(120) NOT NULL, path varchar(255) NOT NULL, archived boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX social_media_tenant ON clubunio.social_media(tenant_id, archived, created_at);
CREATE TABLE clubunio.social_templates (
    id uuid PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id),
    name varchar(120) NOT NULL, revision integer NOT NULL, archived boolean NOT NULL DEFAULT false,
    updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE (tenant_id, id)
);
CREATE INDEX social_templates_tenant ON clubunio.social_templates(tenant_id, archived, updated_at);
CREATE TABLE clubunio.social_template_versions (
    tenant_id bigint NOT NULL, template_id uuid NOT NULL, revision integer NOT NULL,
    design jsonb NOT NULL, actor_id bigint NOT NULL REFERENCES clubunio.accounts(id),
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (tenant_id, template_id, revision),
    FOREIGN KEY (tenant_id, template_id) REFERENCES clubunio.social_templates(tenant_id, id)
);
CREATE TABLE clubunio.social_results (
    tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id), competition_id bigint NOT NULL,
    host_score smallint NOT NULL CHECK (host_score BETWEEN 0 AND 999),
    guest_score smallint NOT NULL CHECK (guest_score BETWEEN 0 AND 999),
    fixture jsonb NOT NULL, actor_id bigint NOT NULL REFERENCES clubunio.accounts(id),
    updated_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY (tenant_id, competition_id),
    FOREIGN KEY (tenant_id, competition_id) REFERENCES clubunio.competitions(tenant_id, id)
);
CREATE TABLE clubunio.social_posts (
    id uuid PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id),
    kind varchar(16) NOT NULL CHECK (kind IN ('day','result','free')),
    title varchar(120) NOT NULL, caption varchar(2200) NOT NULL, design jsonb NOT NULL,
    snapshot jsonb NOT NULL, targets jsonb NOT NULL, image_path varchar(255) NOT NULL,
    revision integer NOT NULL DEFAULT 1,
    state varchar(16) NOT NULL DEFAULT 'draft' CHECK (state IN ('draft','approved','archived')),
    actor_id bigint NOT NULL REFERENCES clubunio.accounts(id), approved_by bigint REFERENCES clubunio.accounts(id),
    approved_at timestamptz, updated_at timestamptz NOT NULL DEFAULT now(), UNIQUE (tenant_id, id)
);
CREATE INDEX social_posts_tenant ON clubunio.social_posts(tenant_id, updated_at DESC);
CREATE TABLE clubunio.social_deliveries (
    tenant_id bigint NOT NULL, post_id uuid NOT NULL,
    channel varchar(12) NOT NULL CHECK (channel IN ('instagram','facebook')),
    state varchar(16) NOT NULL CHECK (state IN ('sending','published','unknown','failed')),
    target varchar(32) NOT NULL, remote_id varchar(100), error_code varchar(40),
    actor_id bigint NOT NULL REFERENCES clubunio.accounts(id), updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (tenant_id, post_id, channel),
    FOREIGN KEY (tenant_id, post_id) REFERENCES clubunio.social_posts(tenant_id, id)
);
SQL;
    }

    public function down(): void
    {
        throw new LogicException('Keep editorial and publication history; roll back the application only.');
    }
};

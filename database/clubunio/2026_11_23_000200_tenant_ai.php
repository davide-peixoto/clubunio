<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    protected $connection = 'clubunio';

    public function sql(): string
    {
        return <<<'SQL'
CREATE TABLE clubunio.tenant_ai_connections (
 tenant_id bigint PRIMARY KEY REFERENCES clubunio.tenants(id),
 mode varchar(12) NOT NULL CHECK(mode IN ('own','clubunio')),
 project_id varchar(100) UNIQUE, encrypted_key text, key_hash char(64) UNIQUE,
 enabled boolean NOT NULL DEFAULT false, privacy_reviewed boolean NOT NULL DEFAULT false,
 monthly_limit_microusd bigint NOT NULL DEFAULT 30000000 CHECK(monthly_limit_microusd BETWEEN 1 AND 1000000000),
 verified_fingerprint char(64), revision integer NOT NULL DEFAULT 1,
 created_at timestamptz NOT NULL DEFAULT now(), updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TABLE clubunio.tenant_ai_stores (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubunio.tenant_ai_connections(tenant_id),
 name varchar(160) NOT NULL, purpose varchar(20) NOT NULL CHECK(purpose IN ('training','club')),
 remote_id varchar(100) UNIQUE, state varchar(20) NOT NULL DEFAULT 'creating',
 usage_bytes bigint NOT NULL DEFAULT 0, created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,id)
);
CREATE TABLE clubunio.tenant_ai_documents (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, tenant_id bigint NOT NULL, store_id bigint NOT NULL,
 name varchar(255) NOT NULL, folder text, filename varchar(100), mime_type varchar(100) NOT NULL,
 size_bytes bigint NOT NULL, sha256 char(64) NOT NULL, remote_file_id varchar(100) UNIQUE,
 state varchar(20) NOT NULL DEFAULT 'stored', error_code varchar(60), revision integer NOT NULL DEFAULT 1,
 created_at timestamptz NOT NULL DEFAULT now(), UNIQUE(tenant_id,id),
 FOREIGN KEY(tenant_id,store_id) REFERENCES clubunio.tenant_ai_stores(tenant_id,id)
);
CREATE TABLE clubunio.tenant_ai_usage (
 id uuid PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubunio.tenant_ai_connections(tenant_id),
 task varchar(30) NOT NULL, mode varchar(12) NOT NULL, project_id varchar(100) NOT NULL,
 model varchar(100), state varchar(20) NOT NULL DEFAULT 'running',
 reserved_microusd bigint NOT NULL CHECK(reserved_microusd>=0), actual_microusd bigint,
 input_tokens bigint, output_tokens bigint, search_calls integer NOT NULL DEFAULT 0,
 error_code varchar(60), created_at timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX tenant_ai_usage_month ON clubunio.tenant_ai_usage(tenant_id,created_at);
CREATE TABLE clubunio.tenant_ai_events (
 id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, tenant_id bigint NOT NULL REFERENCES clubunio.tenants(id),
 actor_id bigint, action varchar(60) NOT NULL, object_id bigint, created_at timestamptz NOT NULL DEFAULT now()
);
SQL;
    }

    public function up(): void
    {
        if (!in_array(DB::connection('clubunio')->getDatabaseName(), ['clubunio_dev','clubunio_stage','clubunio_prod'], true)) {
            throw new LogicException('Unexpected tenant AI target');
        }
        DB::connection('clubunio')->unprepared($this->sql());
    }

    public function down(): void { throw new LogicException('Retain private originals, usage and credential history; reviewed restore required'); }
};

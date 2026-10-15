<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        abort_unless(DB::connection('clubunio')->getDatabaseName() === 'db_acdf8d_dclubora', 409);
        Schema::connection('clubunio')->create('clubora.document_uploads', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('tenant_id');
            $t->string('kind', 20);
            $t->bigInteger('object_id');
            $t->string('deduplication_key', 64);
            $t->string('state', 20)->default('pending');
            $t->string('sha256', 64);
            $t->bigInteger('byte_count');
            $t->string('mime_type', 80);
            $t->string('folder', 160);
            $t->string('filename', 60);
            $t->text('encrypted_metadata');
            $t->text('encrypted_bytes')->nullable();
            $t->bigInteger('created_by')->nullable();
            $t->uuid('public_request_id')->nullable();
            $t->bigInteger('result_id')->nullable();
            $t->timestampTz('provider_started_at')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('published_at')->nullable();
            $t->unique(['tenant_id', 'deduplication_key']);
            $t->index(['tenant_id', 'kind', 'object_id', 'state']);
        });
        DB::connection('clubunio')->statement('ALTER TABLE clubora.sync_work_items ALTER COLUMN created_by DROP NOT NULL');
        DB::connection('clubunio')->statement('ALTER TABLE clubora.sync_work_events ALTER COLUMN actor_id DROP NOT NULL');
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required; staged documents must not be dropped');
    }
};

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
        Schema::connection('clubunio')->create('clubora.sync_work_items', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('tenant_id');
            $t->string('operation', 60);
            $t->bigInteger('object_id');
            $t->string('deduplication_key', 64);
            $t->text('encrypted_payload');
            $t->string('connection_hash', 64)->nullable();
            $t->string('state', 20)->default('pending');
            $t->integer('revision')->default(1);
            $t->integer('attempts')->default(0);
            $t->uuid('lease_token')->nullable();
            $t->timestampTz('lease_until')->nullable();
            $t->string('error_code', 60)->nullable();
            $t->bigInteger('created_by');
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent();
            $t->unique(['tenant_id', 'deduplication_key']);
            $t->index(['tenant_id', 'state', 'updated_at']);
        });
        Schema::connection('clubunio')->create('clubora.sync_work_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('work_item_id')->constrained('clubora.sync_work_items')->restrictOnDelete();
            $t->bigInteger('actor_id');
            $t->string('event', 40);
            $t->string('code', 60)->nullable();
            $t->text('encrypted_note')->nullable();
            $t->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};

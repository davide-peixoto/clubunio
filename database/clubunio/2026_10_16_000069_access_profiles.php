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
        Schema::connection('clubunio')->create('clubora.access_profiles', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('tenant_id');
            $t->string('name', 120);
            $t->text('description')->nullable();
            $t->jsonb('permissions')->default('[]');
            $t->boolean('active')->default(true);
            $t->integer('revision')->default(1);
            $t->timestampsTz();
            $t->unique(['tenant_id', 'name']);
            $t->foreign('tenant_id')->references('id')->on('clubora.tenants');
        });
    }

    public function down(): void
    {
        throw new LogicException('Access profiles require a reviewed rollback.');
    }
};

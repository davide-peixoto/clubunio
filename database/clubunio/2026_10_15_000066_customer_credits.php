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
        $schema = Schema::connection('clubunio');
        $schema->create('clubora.customer_credits', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('tenant_id');
            $t->bigInteger('member_id');
            $t->bigInteger('credit_note_id')->unique();
            $t->string('connection_hash', 64);
            $t->string('contact_id', 30);
            $t->string('external_reference', 120);
            $t->string('voucher_id', 30)->nullable();
            $t->bigInteger('amount_cents');
            $t->integer('currency_id');
            $t->integer('revision')->default(1);
            $t->bigInteger('created_by');
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent();
            $t->unique(['tenant_id', 'connection_hash', 'external_reference'], 'customer_credit_reference_unique');
            $t->unique(['tenant_id', 'connection_hash', 'voucher_id'], 'customer_credit_voucher_unique');
            $t->index(['tenant_id', 'member_id']);
        });
        $schema->create('clubora.customer_credit_allocations', function (Blueprint $t) {
            $t->id();
            $t->bigInteger('tenant_id');
            $t->foreignId('customer_credit_id')->constrained('clubora.customer_credits')->restrictOnDelete();
            $t->bigInteger('invoice_id');
            $t->string('connection_hash', 64);
            $t->string('voucher_id', 30);
            $t->bigInteger('amount_cents');
            $t->jsonb('evidence');
            $t->bigInteger('created_by');
            $t->timestampTz('created_at')->useCurrent();
            $t->timestampTz('updated_at')->useCurrent();
            $t->unique(['tenant_id', 'connection_hash', 'voucher_id', 'invoice_id'], 'customer_credit_invoice_unique');
        });
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};

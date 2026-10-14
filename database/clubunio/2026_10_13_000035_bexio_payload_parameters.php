<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'clubunio';

    public function up(): void
    {
        $db = DB::connection('clubunio');
        if ($db->getDatabaseName() !== 'db_acdf8d_dclubora') {
            throw new LogicException('Unexpected target');
        }
        $tenant = $db->table('clubora.tenants')->where('slug', 'sv-sissach')->value('id');
        if (! $tenant) {
            throw new LogicException('Missing tenant');
        }
        foreach (['USER_ID', 'LANGUAGE_ID', 'BANK_ACCOUNT_ID', 'CURRENCY_ID', 'PAYMENT_TYPE_ID', 'UNIT_ID', 'TAX_ID', 'MWST_TYPE', 'INVOICE_DUE_DAYS', 'BILL_CONTACT_PARTNER_ID', 'BILL_DUE_DAYS'] as $key) {
            $db->table('clubora.tenant_settings')->insertOrIgnore(['tenant_id' => $tenant, 'area' => 'bexio', 'key' => 'BEXIO_'.$key,
                'label' => 'Bexio '.$key, 'encrypted_value' => Crypt::encryptString(''), 'value_type' => 'string', 'is_secret' => false, 'enabled' => true]);
        }
    }

    public function down(): void
    {
        throw new LogicException('Reviewed restore required');
    }
};

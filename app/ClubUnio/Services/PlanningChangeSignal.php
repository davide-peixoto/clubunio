<?php
namespace App\ClubUnio\Services;
use App\ClubUnio\Models\Tenant;
use Illuminate\Support\Facades\{Cache,DB};
use Illuminate\Support\Str;

class PlanningChangeSignal
{
    public function version(Tenant $tenant): string { return (string)Cache::get('clubunio:planning-version:'.$tenant->id, 'initial'); }
    public function changed(Tenant $tenant): void
    {
        $write=fn()=>Cache::put('clubunio:planning-version:'.$tenant->id,(string)Str::uuid(),now()->addDays(7));
        if(DB::connection('clubunio')->transactionLevel())DB::connection('clubunio')->afterCommit($write);else $write();
    }
}

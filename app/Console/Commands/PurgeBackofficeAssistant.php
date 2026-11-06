<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PurgeBackofficeAssistant extends Command
{
    protected $signature = 'clubunio:assistant:purge {--apply : Delete expired private conversation payloads}';

    protected $description = 'Preview or purge expired assistant transcripts; retain cost and business audit records';

    public function handle(): int
    {
        $db = DB::connection('clubunio');
        $count = $db->transaction(function () use ($db) {
            $rows = $db->table('clubora.assistant_conversations as c')
                ->where('c.updated_at', '<', now()->subDays(max(1, (int) config('clubunio-assistant.retention_days', 30)))->toIso8601String())
                ->whereNull('c.busy_run')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('clubora.assistant_actions as a')
                ->whereColumn('a.conversation_id', 'c.id')->whereIn('a.state', ['running', 'attention']))
                ->orderBy('c.id')->limit(500)->lockForUpdate()->get(['c.id']);
            if ($this->option('apply') && $rows->isNotEmpty()) {
                $db->table('clubora.assistant_conversations')->whereIn('id', $rows->pluck('id'))->delete();
            }

            return $rows->count();
        });
        $this->line(($this->option('apply') ? __('assistant.purged') : __('assistant.purge_preview')).' '.$count);

        return self::SUCCESS;
    }
}

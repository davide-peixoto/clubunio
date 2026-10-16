<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\SyncWorkService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;

class Synchronisation extends Page
{
    use WithPagination;

    protected string $view = 'clubunio.synchronisation';

    protected static ?string $title = 'Synchronisation';

    public string $state = 'open';

    public string $provider = 'all';

    public string $note = '';

    public static function getNavigationGroup(): ?string
    {
        return 'Verwaltung';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-arrows-right-left';
    }

    public static function canAccess(): bool
    {
        return app(BackofficeReadContext::class)->allowsReadPage(Filament::auth()->user(), Filament::getTenant(), adminOnly: true);
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function updatedProvider(): void
    {
        $this->resetPage();
    }

    public function collectOutstanding(): void
    {
        abort_unless(static::canAccess(), 403);
        $count = app(SyncWorkService::class)->importOutstanding(Filament::auth()->user(), Filament::getTenant());
        Notification::make()->title($count.' neue Prüfaufträge übernommen')->success()->send();
    }

    public function retry(int $id, int $revision): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            app(SyncWorkService::class)->retry(Filament::auth()->user(), Filament::getTenant(), $id, $revision);
            $row = app(SyncWorkService::class)->table()->where('tenant_id', Filament::getTenant()->id)->find($id);
            Notification::make()->title($row->state === 'succeeded' ? 'Abgleich abgeschlossen' : 'Manuelle Prüfung erforderlich')
                ->status($row->state === 'succeeded' ? 'success' : 'warning')->send();
        } catch (\Throwable) {
            Notification::make()->title('Abgleich nicht abgeschlossen')->body('Auftrag und Protokoll bleiben erhalten. Verbindung, Berechtigungen oder Quelldaten prüfen.')->warning()->send();
        }
    }

    public function closeItem(int $id, int $revision): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['note' => 'required|string|min:5|max:1000']);
        app(SyncWorkService::class)->close(Filament::auth()->user(), Filament::getTenant(), $id, $revision, $this->note);
        $this->note = '';
        Notification::make()->title('Auftrag mit Begründung abgeschlossen')->send();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['state' => 'in:open,succeeded,closed,all', 'provider' => 'in:all,bexio,sfv,ai,azure']);
        $base = app(SyncWorkService::class)->table()->where('tenant_id', Filament::getTenant()->id);
        $counts = (clone $base)->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state');
        $rows = (clone $base)->when($this->state === 'open', fn ($q) => $q->whereIn('state', ['pending', 'attention', 'running']))
            ->when(in_array($this->state, ['succeeded', 'closed'], true), fn ($q) => $q->where('state', $this->state))
            ->when($this->provider === 'sfv', fn ($q) => $q->where('operation', 'sfv_schedule'))
            ->when($this->provider === 'bexio', fn ($q) => $q->whereNotIn('operation', ['sfv_schedule', 'training_review', 'azure_upload']))
            ->when($this->provider === 'ai', fn ($q) => $q->where('operation', 'training_review'))
            ->when($this->provider === 'azure', fn ($q) => $q->where('operation', 'azure_upload'))
            ->orderByDesc('updated_at')->orderByDesc('id')->paginate(20);
        $events = DB::connection('clubunio')->table('clubora.sync_work_events')->whereIn('work_item_id', $rows->pluck('id'))
            ->orderByDesc('id')->get()->groupBy('work_item_id');
        foreach ($rows as $row) {
            $payload = json_decode(Crypt::decryptString($row->encrypted_payload), true, 512, JSON_THROW_ON_ERROR);
            $row->context = $row->operation === 'sfv_schedule' ? ($payload['from'].' – '.$payload['to'])
                : ($row->operation === 'training_review' ? ($payload['input']['goal'] ?? '') : '');
            if ($row->operation === 'azure_upload') {
                $row->context = (['member' => 'Mitglied', 'registration' => 'Anmeldung', 'expense' => 'Spesenposition'][$payload['kind']] ?? 'Dokument').' #'.$payload['owner_id'];
            }
        }
        foreach ($events as $items) {
            foreach ($items as $event) {
                $event->note = $event->encrypted_note ? Crypt::decryptString($event->encrypted_note) : null;
            }
        }

        return compact('rows', 'counts', 'events') + ['labels' => SyncWorkService::LABELS];
    }
}

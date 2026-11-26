<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Models\MaterialReceipt;
use App\ClubUnio\Services\MaterialReturnService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class MaterialReturns extends Page
{
    use WithPagination;

    protected string $view = 'clubunio.material.returns';

    public ?int $member = null;
    public bool $overdue = false;
    public bool $completed = false;
    #[Locked]
    public ?int $receipt = null;
    #[Locked]
    public ?int $position = null;
    #[Locked]
    public string $bookingKey = '';
    public array $booking = [];
    public ?string $dueOn = null;

    public static function canAccess(): bool
    {
        return app(MaterialReturnService::class)->allows(Filament::auth()->user(), Filament::getTenant(), 'view');
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Verein';
    }

    public static function getNavigationLabel(): string
    {
        return trans('material_returns.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['member', 'overdue', 'completed'], true)) {
            $this->resetPage();
        }
    }

    public function select(int $receipt, int $position): void
    {
        abort_unless(static::canAccess(), 403);
        $record = MaterialReceipt::where('tenant_id', Filament::getTenant()->id)
            ->whereIn('state', ['issued', 'confirmed', 'cancelled'])->findOrFail($receipt);
        abort_unless($position > 0 && isset($record->snapshot['items'][$position - 1]), 404);
        $this->receipt = $receipt;
        $this->position = $position;
        $this->bookingKey = (string) Str::uuid();
        $this->booking = ['quantity' => 1, 'returned_on' => now('Europe/Zurich')->toDateString(), 'condition' => ''];
        $this->dueOn = \Illuminate\Support\Facades\DB::connection('clubunio')->table('clubunio.material_return_deadlines')
            ->where('tenant_id', Filament::getTenant()->id)->where('receipt_id', $receipt)->where('position', $position)->value('due_on');
        $this->resetErrorBag();
    }

    public function book(MaterialReturnService $service): void
    {
        abort_unless($this->receipt && $this->position, 422);
        $service->book(Filament::auth()->user(), Filament::getTenant(), $this->receipt, $this->position, $this->booking, $this->bookingKey);
        $this->select($this->receipt, $this->position);
        Notification::make()->title(trans('material_returns.saved'))->success()->send();
    }

    public function deadline(MaterialReturnService $service): void
    {
        abort_unless($this->receipt && $this->position, 422);
        $service->deadline(Filament::auth()->user(), Filament::getTenant(), $this->receipt, $this->position, $this->dueOn ?: null);
        $this->resetErrorBag();
        Notification::make()->title(trans('material_returns.saved'))->success()->send();
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $actor = Filament::auth()->user();
        $tenant = Filament::getTenant();
        $service = app(MaterialReturnService::class);
        $members = MaterialReceipt::where('tenant_id', $tenant->id)->whereNotNull('snapshot')
            ->selectRaw("member_id, max(snapshot->>'member') as member_name")->groupBy('member_id')
            ->orderBy('member_name')->pluck('member_name', 'member_id');
        return [
            'rows' => $service->positions($actor, $tenant, $this->member, $this->overdue, $this->completed),
            'members' => $members,
            'canWrite' => $service->allows($actor, $tenant, 'update') && MaterialReceipt::where('tenant_id', $tenant->id)
                ->whereIn('state', ['issued', 'confirmed'])->whereKey($this->receipt)->exists(),
            'history' => $this->receipt && $this->position ? $service->history($actor, $tenant, $this->receipt, $this->position) : null,
        ];
    }
}

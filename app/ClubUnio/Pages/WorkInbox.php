<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\SyncWorkItem;
use App\ClubUnio\Resources\ExpenseResource;
use App\ClubUnio\Services\ModulePermissionService;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class WorkInbox extends Page
{
    protected string $view = 'clubunio.work-inbox';

    public static function getNavigationLabel(): string
    {
        return __('work-inbox.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public static function getNavigationGroup(): ?string
    {
        return Synchronisation::getNavigationGroup();
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-inbox-stack';
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant && ! request()->attributes->has('clubunio_impersonation')
            && (Filament::auth()->user()?->hasUnrestrictedBackofficeAccess($tenant) ?? false);
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $tenant = Filament::getTenant();
        $actor = Filament::auth()->user();
        $permissions = app(ModulePermissionService::class);
        $expenses = null;
        $expenseCount = 0;
        if ($permissions->allows($actor, $tenant, 'expenses', 'view') && $permissions->allows($actor, $tenant, 'expenses', 'approve')) {
            $query = Expense::query()->where('tenant_id', $tenant->id)->where('active', true)
                ->whereHas('status', fn ($query) => $query->where('code', 'F'));
            $expenseCount = (clone $query)->count();
            $expenses = $query
                ->with('member')->orderByDesc('id')->limit(10)->get()
                ->map(fn (Expense $expense) => [
                    'label' => $expense->member?->display_name ?? '#'.$expense->id,
                    'url' => ExpenseResource::getUrl('view', ['record' => $expense]),
                ]);
        }

        $bexio = Synchronisation::canAccess()
            ? SyncWorkItem::query()->where('tenant_id', $tenant->id)->where('state', 'attention')
                ->whereNotIn('operation', ['sfv_schedule', 'training_review', 'azure_upload'])->count()
            : null;

        return compact('expenses', 'expenseCount', 'bexio');
    }
}

<?php

namespace App\ClubUnio\Resources\Concerns;

use App\ClubUnio\Models\{Member, Invoice, Expense};
use App\ClubUnio\Services\{ModulePresentation, ReactUi};

trait HasModuleOverview
{
    public function getHeader(): ?\Illuminate\Contracts\View\View
    {
        if (! app(ReactUi::class)->enabled()) { return parent::getHeader(); }
        $record = method_exists($this, 'getRecord') ? $this->getRecord() : null;
        if ($record instanceof Member || $record instanceof Invoice || $record instanceof Expense) {
            abort_unless(static::getResource()::canView($record), 403);
            $metrics = ModulePresentation::recordFacts($record);
            $scope = null;
        } else {
            abort_unless(static::getResource()::canViewAny(), 403);
            $records = $this->getTableRecords();
            $metrics = ModulePresentation::financePage(collect(method_exists($records, 'items') ? $records->items() : $records), static::getResource()::getModel() === Invoice::class);
            $scope = trans('module_ui.scope_page');
        }

        return view('clubunio.modules.record-header', compact('record', 'metrics', 'scope'));
    }
}

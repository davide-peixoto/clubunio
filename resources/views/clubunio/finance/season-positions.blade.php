<div>
    @livewire($component, ['ownerRecord' => $record, 'pageClass' => $pageClass, 'financeContextType' => $contextType, 'financeContextId' => $contextId], key('finance-positions-'.$contextType.'-'.$contextId.'-'.$component.'-'.$record->id))
</div>

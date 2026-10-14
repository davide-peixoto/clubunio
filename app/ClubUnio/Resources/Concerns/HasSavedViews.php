<?php

namespace App\ClubUnio\Resources\Concerns;

use App\ClubUnio\Resources\FormMutation;
use App\ClubUnio\Services\SavedViewService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait HasSavedViews
{
    protected function savedFilterRules(): array
    {
        return [];
    }

    protected function viewQuery()
    {
        return app(SavedViewService::class)->query(Filament::auth()->user(), Filament::getTenant(), static::getResource()::getSlug());
    }

    protected function viewSessionKey(string $kind): string
    {
        return 'clubunio.views.'.Filament::getTenant()?->id.'.'.Filament::auth()->id().'.'.static::getResource()::getSlug().'.'.$kind;
    }

    public function getTableFiltersSessionKey(): string
    {
        return $this->viewSessionKey('filters');
    }

    public function getTableSearchSessionKey(): string
    {
        return $this->viewSessionKey('search');
    }

    public function getTableSortSessionKey(): string
    {
        return $this->viewSessionKey('sort');
    }

    public function getTableColumnsSessionKey(): string
    {
        return $this->viewSessionKey('columns');
    }

    public function bootedInteractsWithTable(): void
    {
        $initial = $this->shouldMountInteractsWithTable;
        parent::bootedInteractsWithTable();
        if (! $initial || request()->hasAny(['filters', 'search', 'sort', 'tab', 'grouping'])) {
            return;
        }
        $default = $this->viewQuery()->where('is_default', true)->first();
        if ($default) {
            try {
                $this->applyViewState($default->state_encrypted);
            } catch (ValidationException $e) {
                Notification::make()->title('Standardansicht nicht geladen')->body('Die gespeicherte Auswahl ist nicht mehr gültig. Bitte die Ansicht aktualisieren.')->warning()->send();
            }
        }
    }

    protected function checkedViewState(array $state): array
    {
        $this->viewQuery(); // Recheck access on each Livewire action.
        $invalid = fn () => throw ValidationException::withMessages(['view_id' => 'Die gespeicherte Auswahl ist nicht mehr gültig.']);
        if (($state['version'] ?? null) !== 1 || ! is_array($state['filters'] ?? null) || ! is_array($state['columns'] ?? null)) {
            $invalid();
        }
        foreach ($state['filters'] as $name => $data) {
            $filter = $this->getTable()->getFilter($name);
            $rules = $this->savedFilterRules()[$name] ?? null;
            if ($rules !== null) {
                if (! $filter || ! is_array($data) || array_diff(array_keys($data), array_keys($rules)) || Validator::make($data, $rules)->fails()) {
                    $invalid();
                }

                continue;
            }
            if (! $filter || ! is_array($data) || array_diff(array_keys($data), ['value'])) {
                $invalid();
            }
            $value = $data['value'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if ($filter instanceof TernaryFilter) {
                if (! in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                    $invalid();
                }
            } elseif ($filter instanceof SelectFilter) {
                if (! is_scalar($value) || ! array_key_exists((string) $value, $filter->getOptions())) {
                    $invalid();
                }
            } else {
                $invalid();
            }
        }
        if (! is_string($state['search'] ?? '') || mb_strlen($state['search'] ?? '') > 200) {
            $invalid();
        }
        if (! empty($state['sort'])) {
            [$column, $direction] = array_pad(explode(':', $state['sort'], 2), 2, 'asc');
            if (! $this->getTable()->getColumn($column)?->isSortable() || ! in_array($direction, ['asc', 'desc'], true)) {
                $invalid();
            }
        }
        if (! empty($state['tab']) && ! array_key_exists($state['tab'], $this->getCachedTabs())) {
            $invalid();
        }
        foreach ($state['columns'] as $name => $visible) {
            $column = $this->getTable()->getColumn($name);
            if (! $column || ! $column->isToggleable() || ! is_bool($visible)) {
                $invalid();
            }
        }

        return $state;
    }

    protected function captureViewState(): array
    {
        $columns = [];
        foreach ($this->tableColumns as $column) {
            if (($column['isToggleable'] ?? false) && ! ($column['isHidden'] ?? false)) {
                $columns[$column['name']] = (bool) $column['isToggled'];
            }
        }

        return $this->checkedViewState(['version' => 1, 'filters' => $this->tableFilters ?? [],
            'search' => $this->tableSearch, 'sort' => $this->tableSort, 'tab' => $this->activeTab, 'columns' => $columns]);
    }

    protected function applyViewState(array $state): void
    {
        $state = $this->checkedViewState($state);
        $this->tableFilters = $state['filters'];
        $this->getTableFiltersForm()->fill($state['filters']);
        $this->updatedTableFilters();
        $this->tableSearch = $state['search'] ?? '';
        $this->updatedTableSearch();
        $this->tableSort = $state['sort'] ?? null;
        $this->updatedTableSort();
        $this->activeTab = $state['tab'] ?? $this->getDefaultActiveTab();
        $columns = $this->getDefaultTableColumnState();
        foreach ($columns as &$column) {
            if ($column['isToggleable'] && ! $column['isHidden'] && array_key_exists($column['name'], $state['columns'])) {
                $column['isToggled'] = $state['columns'][$column['name']];
            }
        }
        unset($column);
        $this->applyTableColumnManager($columns);
        $this->resetPage();
        $this->flushCachedTableRecords();
    }

    protected function getHeaderActions(): array
    {
        $select = fn () => Select::make('view_id')->label('Ansicht')->required()->options(fn () => $this->viewQuery()->orderBy('name')->get()->mapWithKeys(fn ($v) => [$v->id => $v->name.($v->is_default ? ' (Standard)' : '')])->all());
        $write = fn ($id, $data, $state) => app(SavedViewService::class)->save(Filament::auth()->user(), Filament::getTenant(), static::getResource()::getSlug(), $id, $data, $state);

        return [...parent::getHeaderActions(), ActionGroup::make([
            Action::make('loadView')->label('Ansicht laden')->icon('heroicon-o-folder-open')->schema([$select()])
                ->action(fn (array $data) => FormMutation::run($this, fn () => $this->applyViewState($this->viewQuery()->findOrFail($data['view_id'])->state_encrypted))),
            Action::make('saveView')->label('Aktuelle Ansicht speichern')->icon('heroicon-o-bookmark')->schema([
                TextInput::make('name')->label('Name')->required()->maxLength(80), Toggle::make('is_default')->label('Als Standard verwenden')->default(false),
            ])->action(fn (array $data) => FormMutation::run($this, fn () => $write(null, $data, $this->captureViewState()))),
            Action::make('manageView')->label('Ansicht ändern')->icon('heroicon-o-pencil-square')->schema([
                $select()->live()->afterStateUpdated(function ($state, Set $set) {
                    $v = $this->viewQuery()->findOrFail($state);
                    $set('name', $v->name);
                    $set('is_default', $v->is_default);
                    $set('revision', $v->revision);
                }), TextInput::make('name')->label('Name')->required()->maxLength(80),
                Toggle::make('is_default')->label('Als Standard verwenden')->default(false),
                Toggle::make('replace_state')->label('Mit aktueller Auswahl überschreiben')->default(false), Hidden::make('revision')->required(),
            ])->action(fn (array $data) => FormMutation::run($this, fn () => $write((int) $data['view_id'], $data,
                $data['replace_state'] ? $this->captureViewState() : $this->viewQuery()->findOrFail($data['view_id'])->state_encrypted))),
            Action::make('deleteView')->label('Ansicht löschen')->icon('heroicon-o-trash')->color('danger')->requiresConfirmation()->schema([
                $select()->live()->afterStateUpdated(fn ($state, Set $set) => $set('revision', $this->viewQuery()->findOrFail($state)->revision)), Hidden::make('revision')->required(),
            ])->action(fn (array $data) => FormMutation::run($this, fn () => app(SavedViewService::class)->delete(Filament::auth()->user(), Filament::getTenant(), static::getResource()::getSlug(), (int) $data['view_id'], (int) $data['revision']))),
        ])->label('Meine Ansichten')->icon('heroicon-o-bookmark')->button()];
    }
}

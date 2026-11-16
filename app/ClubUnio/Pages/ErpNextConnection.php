<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Services\ErpNextConfiguration;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ErpNextConnection extends Page
{
    protected string $view = 'clubunio.erpnext-connection';

    protected static ?string $slug = 'integrations/erpnext';

    protected static bool $shouldRegisterNavigation = false;

    public function getTitle(): string
    {
        return $this->text('title');
    }

    public static function canAccess(): bool
    {
        return ErpNextConfiguration::canManage(Filament::auth()->user(), Filament::getTenant());
    }

    protected function getHeaderActions(): array
    {
        $fields = ['company', 'company_address', 'territory', 'customer_group', 'item_code', 'income_account'];
        $expenseFields = ['supplier_group', 'expense_item_code', 'expense_account'];

        return [
            Action::make('configure')
                ->label($this->text('configure'))
                ->schema([
                    ...array_map(fn ($key) => TextInput::make($key)->label($this->text("fields.$key"))->required()->maxLength(140), $fields),
                    ...array_map(fn ($key) => TextInput::make($key)->label($this->text("fields.$key"))->maxLength(140), $expenseFields),
                    TextInput::make('due_days')->label($this->text('fields.due_days'))->integer()->required()->minValue(0)->maxValue(365),
                    TextInput::make('api_key')->label($this->text('fields.api_key'))->required()->maxLength(128),
                    TextInput::make('api_secret')->label($this->text('fields.api_secret'))->password()->maxLength(128),
                ])
                ->fillForm(function () use ($fields, $expenseFields) {
                    abort_unless(static::canAccess(), 403);

                    return Arr::only(app(ErpNextConfiguration::class)->connection(Filament::getTenant()), [...$fields, ...$expenseFields, 'due_days', 'api_key'])
                        + ['due_days' => 30];
                })
                ->action(function (array $data, Action $action) {
                    try {
                        app(ErpNextConfiguration::class)->save(Filament::auth()->user(), Filament::getTenant(), $data);
                    } catch (HttpExceptionInterface $e) {
                        abort_if($e->getStatusCode() === 403, 403);
                        Notification::make()->title($e->getMessage())->danger()->send();
                        $action->halt();
                    }
                    Notification::make()->title($this->text('saved'))->success()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return [
            'description' => $this->text('description'),
            'origin' => config('clubunio-erpnext.origin'),
        ];
    }

    private function text(string $key): string
    {
        return trans('erpnext.'.$key);
    }
}

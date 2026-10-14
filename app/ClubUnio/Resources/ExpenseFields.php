<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\Expense;
use App\ClubUnio\Models\ExpenseRound;
use App\ClubUnio\Models\ExpenseStatus;
use App\ClubUnio\Models\ExpenseType;
use App\ClubUnio\Models\GameType;
use App\ClubUnio\Models\MemberBankAccount;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Services\ExpenseService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class ExpenseFields
{
    public static function header(): array
    {
        return [Hidden::make('revision'),
            MemberResource::memberSelect('member_id')->required()->live()->afterStateUpdated(fn (Set $set) => $set('bank_account_id', null)),
            Select::make('team_season_id')->label('Mannschaft / Saison')->required()->searchable()->options(fn () => TeamSeason::where('tenant_id', Filament::getTenant()->id)->where('active', true)->orderBy('label')->pluck('label', 'id')),
            Select::make('round_id')->label('Runde')->required()->options(fn () => ExpenseRound::where('tenant_id', Filament::getTenant()->id)->where('active', true)->pluck('name', 'id')),
            Select::make('bank_account_id')->label('Bankkonto')->required()->searchable()->options(fn (Get $get) => MemberBankAccount::where('tenant_id', Filament::getTenant()->id)->where('active', true)->where('member_id', $get('member_id'))->with('bank')->get()->mapWithKeys(fn ($b) => [$b->id => ($b->bank?->name ?? '').' '.$b->masked_iban])),
            Textarea::make('notes')->label('Bemerkungen')->maxLength(4000),
        ];
    }

    public static function transition(): Action
    {
        return Action::make('transition')->label('Status ändern')->icon('heroicon-o-check-circle')->slideOver()
            ->visible(fn (Expense $record) => count(app(ExpenseService::class)->targets(Filament::auth()->user(), Filament::getTenant(), $record)) > 0)
            ->fillForm(fn (Expense $record) => ['revision' => $record->revision])
            ->schema([Hidden::make('revision'), Select::make('status_id')->label('Neuer Status')->required()->options(fn (Expense $record) => ExpenseStatus::where('tenant_id', Filament::getTenant()->id)->where('active', true)
                ->whereIn('code', app(ExpenseService::class)->targets(Filament::auth()->user(), Filament::getTenant(), $record))->pluck('name', 'id')),
                Textarea::make('reason')->label('Prüfvermerk')->required()->minLength(10)->maxLength(500)])
            ->action(fn (Expense $record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(ExpenseService::class)->transition(Filament::auth()->user(), Filament::getTenant(), $record->id, $data)));
    }

    public static function line(Expense $expense): array
    {
        return [Hidden::make('revision'), Hidden::make('expense_revision'),
            Select::make('expense_type_id')->label('Spesentyp')->required()->live()->options(fn () => ExpenseType::where('tenant_id', Filament::getTenant()->id)->where('active', true)->pluck('name', 'id'))
                ->afterStateUpdated(function ($state, Set $set) use ($expense) {
                    if ($state) {
                        $suggestion = app(ExpenseService::class)->suggestion(Filament::getTenant(), $expense, (int) $state);
                        if ($suggestion !== null) {
                            $set('amount', $suggestion);
                        }
                    }
                }),
            Select::make('game_type_id')->label('Spieltyp')->options(fn () => GameType::where('tenant_id', Filament::getTenant()->id)->where('active', true)->pluck('name', 'id')),
            TextInput::make('opponent')->label('Gegner')->maxLength(255),
            TextInput::make('amount')->label('Betrag')->prefix('CHF')->numeric()->required()->minValue(0.0001)->maxValue(999999999),
            Textarea::make('notes')->label('Bemerkungen')->maxLength(4000), Toggle::make('active')->label('Aktiv')->default(true),
        ];
    }
}

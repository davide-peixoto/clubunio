<?php

namespace App\ClubUnio\Resources;

use App\ClubUnio\Models\MessageTemplate;
use App\ClubUnio\Services\MessageEventCatalog;
use App\ClubUnio\Services\MessageTemplateService;
use App\ClubUnio\Services\TestMailService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MessageTemplateResource extends SettingResource
{
    protected static ?string $model = MessageTemplate::class;

    protected static ?string $modelLabel = 'Nachrichtenvorlage';

    protected static ?string $pluralModelLabel = 'Nachrichtenvorlagen';

    protected static ?string $tenantRelationshipName = 'messageTemplates';

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-envelope';
    }

    public static function fields(bool $creating): array
    {
        return [
            Select::make('event_key')->label(__('Ereignis'))->options(array_map(fn ($e) => $e['name'], MessageEventCatalog::all(app()->getLocale())))->required()->searchable()->live()->disabled(! $creating)->dehydrated(),
            Select::make('channel')->label(__('Kanal'))->options(fn (Get $get) => array_intersect_key(['email' => __('E-Mail'), 'push' => __('Push')], array_flip(MessageEventCatalog::all(app()->getLocale())[$get('event_key')]['channels'] ?? [])))->required()->disabled(! $creating)->dehydrated(),
            Select::make('locale')->label(__('Sprache'))->options(fn (Get $get) => MessageTemplateService::locales() + (! $creating && $get('locale') ? [$get('locale') => $get('locale')] : []))->default('de-CH')->required()->disabled(! $creating)->dehydrated(),
            Hidden::make('revision'),
            TextInput::make('title')->label(__('Betreff / Titel'))->required()->maxLength(200)->live(onBlur: true),
            Textarea::make('body')->label(__('Nachricht'))->required()->rows(8)->maxLength(10000)->live(onBlur: true),
            Toggle::make('active')->label(__('Aktiv'))->default(true),
            View::make('clubunio.messages.editor-preview')->viewData(function (Get $get) {
                $event = MessageEventCatalog::all(app()->getLocale())[$get('event_key')] ?? null;
                $preview = null;
                $error = null;
                if ($event) {
                    try {
                        $preview = app(MessageTemplateService::class)->preview($get('event_key'), (string) $get('title'), (string) $get('body'), MessageEventCatalog::samples((string) $get('locale')));
                    } catch (ValidationException $e) {
                        $error = __('Ungültiger Platzhalter oder unzulässige Formatierung.');
                    }
                }

                return ['variables' => $event['variables'] ?? [], 'preview' => $preview, 'error' => $error];
            }),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('event_key')->label(__('Ereignis'))->formatStateUsing(fn ($state) => MessageEventCatalog::all(app()->getLocale())[$state]['name'] ?? $state)->sortable()->searchable(),
            TextColumn::make('channel')->label(__('Kanal'))->formatStateUsing(fn ($state) => $state === 'email' ? __('E-Mail') : __('Push'))->badge(),
            TextColumn::make('locale')->label(__('Sprache')),
            TextColumn::make('title')->label(__('Betreff / Titel'))->searchable()->wrap(),
            IconColumn::make('active')->label(__('Aktiv'))->boolean(),
            TextColumn::make('revision')->label(__('Version')),
            TextColumn::make('updated_at')->label(__('Geändert'))->dateTime('d.m.Y H:i'),
        ])->filters([
            SelectFilter::make('event_key')->label(__('Ereignis'))->options(array_map(fn ($e) => $e['name'], MessageEventCatalog::all(app()->getLocale()))),
            SelectFilter::make('channel')->label(__('Kanal'))->options(['email' => __('E-Mail'), 'push' => __('Push')]),
            SelectFilter::make('locale')->label(__('Sprache'))->options(MessageTemplateService::locales()),
        ])->deferFilters(false)->persistFiltersInSession()->persistSearchInSession()
            ->headerActions([
                Action::make('newVariant')->label(__('Neue Sprachvorlage'))->icon('heroicon-o-plus')->modalSubmitActionLabel(__('Speichern'))->slideOver()->schema(self::fields(true))
                    ->action(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => app(MessageTemplateService::class)->save(Filament::auth()->user(), Filament::getTenant(), null, $data))),
            ])->recordActions([
                Action::make('testMail')->label(__('Testmail'))->icon('heroicon-o-paper-airplane')->iconButton()->tooltip(__('Testmail an admin@svsissach.ch'))
                    ->visible(fn (MessageTemplate $record) => $record->channel === 'email' && $record->active)
                    ->modalHeading(__('Testmail senden'))->modalDescription(__('Beispieldaten, ausschliesslich an admin@svsissach.ch.'))->modalSubmitActionLabel(__('Testmail senden'))
                    ->fillForm(fn () => ['request_id' => (string) Str::uuid()])
                    ->schema([Hidden::make('request_id')])
                    ->action(function (MessageTemplate $record, array $data, $livewire) {
                        $status = FormMutation::run($livewire, fn () => app(TestMailService::class)->sendPreview(Filament::auth()->user(), Filament::getTenant(), $record->id, $data['request_id']));
                        $notice = Notification::make()->title(match ($status) {
                            'accepted' => __('Testmail vom Mailserver angenommen'), 'sending' => __('Testmail wird bereits verarbeitet'), default => __('Testmail konnte nicht versendet werden'),
                        });
                        ($status === 'accepted' ? $notice->success() : $notice->warning())->send();
                    }),
                Action::make('change')->label(__('Bearbeiten'))->modalHeading(__('Vorlage bearbeiten'))->modalSubmitActionLabel(__('Speichern'))->icon('heroicon-o-pencil-square')->iconButton()->tooltip(__('Vorlage bearbeiten'))->slideOver()
                    ->fillForm(fn (MessageTemplate $record) => $record->only(['event_key', 'channel', 'locale', 'title', 'body', 'active', 'revision']))
                    ->schema(self::fields(false))
                    ->action(fn (MessageTemplate $record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(MessageTemplateService::class)->save(Filament::auth()->user(), Filament::getTenant(), $record->id, $data))),
                Action::make('history')->label(__('Verlauf'))->icon('heroicon-o-clock')->iconButton()->tooltip(__('Änderungsverlauf'))->slideOver()
                    ->modalSubmitAction(false)->modalCancelActionLabel(__('Schliessen'))
                    ->modalContent(fn (MessageTemplate $record) => view('clubunio.messages.history', ['revisions' => $record->revisions()->with('actor')->where('tenant_id', Filament::getTenant()->id)->limit(50)->get()])),
                Action::make('restoreRevision')->label(__('Wiederherstellen'))->icon('heroicon-o-arrow-uturn-left')->iconButton()->tooltip(__('Frühere Version wiederherstellen'))
                    ->visible(fn (MessageTemplate $record) => $record->revision > 1)->modalSubmitActionLabel(__('Wiederherstellen'))
                    ->fillForm(fn (MessageTemplate $record) => ['revision' => $record->revision])
                    ->schema([Hidden::make('revision'), Select::make('previous_revision')->label(__('Frühere Version'))->required()->options(fn (MessageTemplate $record) => $record->revisions()->where('tenant_id', Filament::getTenant()->id)->where('revision', '<', $record->revision)->pluck('revision', 'revision')->map(fn ($r) => __('Version :revision', ['revision' => $r]))->all())])
                    ->action(fn (MessageTemplate $record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(MessageTemplateService::class)->restore(Filament::auth()->user(), Filament::getTenant(), $record->id, (int) $data['previous_revision'], (int) $data['revision']))),
            ])->defaultSort('event_key')->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => MessageTemplateResource\Pages\ListMessageTemplates::route('/')];
    }
}

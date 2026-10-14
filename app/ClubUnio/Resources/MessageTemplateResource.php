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
            Select::make('event_key')->label('Ereignis')->options(array_map(fn ($e) => $e['name'], MessageEventCatalog::all()))->required()->searchable()->live()->disabled(! $creating)->dehydrated(),
            Select::make('channel')->label('Kanal')->options(fn (Get $get) => array_intersect_key(['email' => 'E-Mail', 'push' => 'Push'], array_flip(MessageEventCatalog::all()[$get('event_key')]['channels'] ?? [])))->required()->disabled(! $creating)->dehydrated(),
            Select::make('locale')->label('Sprache')->options(MessageTemplateService::LOCALES)->default('de-CH')->required()->disabled(! $creating)->dehydrated(),
            Hidden::make('revision'),
            TextInput::make('title')->label('Betreff / Titel')->required()->maxLength(200)->live(onBlur: true),
            Textarea::make('body')->label('Nachricht')->required()->rows(8)->maxLength(10000)->live(onBlur: true),
            Toggle::make('active')->label('Aktiv')->default(true),
            View::make('clubunio.messages.editor-preview')->viewData(function (Get $get) {
                $event = MessageEventCatalog::all()[$get('event_key')] ?? null;
                $preview = null;
                $error = null;
                if ($event) {
                    try {
                        $preview = app(MessageTemplateService::class)->preview($get('event_key'), (string) $get('title'), (string) $get('body'), MessageEventCatalog::samples());
                    } catch (ValidationException $e) {
                        $error = 'Ungültiger Platzhalter oder unzulässige Formatierung.';
                    }
                }

                return ['variables' => $event['variables'] ?? [], 'preview' => $preview, 'error' => $error];
            }),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('event_key')->label('Ereignis')->formatStateUsing(fn ($state) => MessageEventCatalog::all()[$state]['name'] ?? $state)->sortable()->searchable(),
            TextColumn::make('channel')->label('Kanal')->formatStateUsing(fn ($state) => $state === 'email' ? 'E-Mail' : 'Push')->badge(),
            TextColumn::make('locale')->label('Sprache'),
            TextColumn::make('title')->label('Betreff / Titel')->searchable()->wrap(),
            IconColumn::make('active')->label('Aktiv')->boolean(),
            TextColumn::make('revision')->label('Version'),
            TextColumn::make('updated_at')->label('Geändert')->dateTime('d.m.Y H:i'),
        ])->filters([
            SelectFilter::make('event_key')->label('Ereignis')->options(array_map(fn ($e) => $e['name'], MessageEventCatalog::all())),
            SelectFilter::make('channel')->label('Kanal')->options(['email' => 'E-Mail', 'push' => 'Push']),
            SelectFilter::make('locale')->label('Sprache')->options(MessageTemplateService::LOCALES),
        ])->deferFilters(false)->persistFiltersInSession()->persistSearchInSession()
            ->headerActions([
                Action::make('newVariant')->label('Neue Sprachvorlage')->icon('heroicon-o-plus')->modalSubmitActionLabel('Speichern')->slideOver()->schema(self::fields(true))
                    ->action(fn (array $data, $livewire) => FormMutation::run($livewire, fn () => app(MessageTemplateService::class)->save(Filament::auth()->user(), Filament::getTenant(), null, $data))),
            ])->recordActions([
                Action::make('testMail')->label('Testmail')->icon('heroicon-o-paper-airplane')->iconButton()->tooltip('Testmail an admin@svsissach.ch')
                    ->visible(fn (MessageTemplate $record) => $record->channel === 'email' && $record->active)
                    ->modalHeading('Testmail senden')->modalDescription('Beispieldaten, ausschliesslich an admin@svsissach.ch.')->modalSubmitActionLabel('Testmail senden')
                    ->fillForm(fn () => ['request_id' => (string) Str::uuid()])
                    ->schema([Hidden::make('request_id')])
                    ->action(function (MessageTemplate $record, array $data, $livewire) {
                        $status = FormMutation::run($livewire, fn () => app(TestMailService::class)->sendPreview(Filament::auth()->user(), Filament::getTenant(), $record->id, $data['request_id']));
                        $notice = Notification::make()->title(match ($status) {
                            'accepted' => 'Testmail vom Mailserver angenommen', 'sending' => 'Testmail wird bereits verarbeitet', default => 'Testmail konnte nicht versendet werden',
                        });
                        ($status === 'accepted' ? $notice->success() : $notice->warning())->send();
                    }),
                Action::make('change')->label('Bearbeiten')->modalHeading('Vorlage bearbeiten')->modalSubmitActionLabel('Speichern')->icon('heroicon-o-pencil-square')->iconButton()->tooltip('Vorlage bearbeiten')->slideOver()
                    ->fillForm(fn (MessageTemplate $record) => $record->only(['event_key', 'channel', 'locale', 'title', 'body', 'active', 'revision']))
                    ->schema(self::fields(false))
                    ->action(fn (MessageTemplate $record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(MessageTemplateService::class)->save(Filament::auth()->user(), Filament::getTenant(), $record->id, $data))),
                Action::make('history')->label('Verlauf')->icon('heroicon-o-clock')->iconButton()->tooltip('Änderungsverlauf')->slideOver()
                    ->modalSubmitAction(false)->modalCancelActionLabel('Schliessen')
                    ->modalContent(fn (MessageTemplate $record) => view('clubunio.messages.history', ['revisions' => $record->revisions()->with('actor')->where('tenant_id', Filament::getTenant()->id)->limit(50)->get()])),
                Action::make('restoreRevision')->label('Wiederherstellen')->icon('heroicon-o-arrow-uturn-left')->iconButton()->tooltip('Frühere Version wiederherstellen')
                    ->visible(fn (MessageTemplate $record) => $record->revision > 1)->modalSubmitActionLabel('Wiederherstellen')
                    ->fillForm(fn (MessageTemplate $record) => ['revision' => $record->revision])
                    ->schema([Hidden::make('revision'), Select::make('previous_revision')->label('Frühere Version')->required()->options(fn (MessageTemplate $record) => $record->revisions()->where('tenant_id', Filament::getTenant()->id)->where('revision', '<', $record->revision)->pluck('revision', 'revision')->map(fn ($r) => 'Version '.$r)->all())])
                    ->action(fn (MessageTemplate $record, array $data, $livewire) => FormMutation::run($livewire, fn () => app(MessageTemplateService::class)->restore(Filament::auth()->user(), Filament::getTenant(), $record->id, (int) $data['previous_revision'], (int) $data['revision']))),
            ])->defaultSort('event_key')->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return ['index' => MessageTemplateResource\Pages\ListMessageTemplates::route('/')];
    }
}

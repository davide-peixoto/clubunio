<?php

namespace App\ClubUnio\Pages;

use App\ClubUnio\Mobile\ChatAccess;
use App\ClubUnio\Http\WebChatController;
use App\ClubUnio\Services\ActiveTenant;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class Chats extends Page
{
    use LocalizedTitle;

    protected string $view = 'clubunio.chat.page';
    protected static ?string $title = 'Chats';
    protected static ?string $slug = 'chats';

    public static function getNavigationGroup(): ?string { return 'Anlagen & Kommunikation'; }
    public static function getNavigationIcon(): string { return 'heroicon-o-chat-bubble-left-right'; }
    public static function getNavigationLabel(): string { return __('Chats'); }

    public static function canAccess(): bool
    {
        $a = Filament::auth()->user(); $t = Filament::getTenant();
        return $a && $t && app(ChatAccess::class)->allows($a, $t, 'view');
    }

    public function mount(): void { abort_unless(static::canAccess(), 403); }

    public function chatConfig(): array
    {
        abort_unless(static::canAccess(), 403);
        return ['base' => url('/chat-data'),
            'context' => session()->get(app(ActiveTenant::class)->key(request())),
            'actorId' => (int)Filament::auth()->id(), 'locale' => app()->getLocale(),
            'languages' => app(\App\ClubUnio\Services\LanguagePreferences::class)->enabled(),
            'capabilities' => app(WebChatController::class)->capabilities(Filament::auth()->user(), Filament::getTenant())];
    }
}

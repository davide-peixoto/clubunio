<?php

namespace App\Providers;

use App\ClubUnio\Models\AccountMemberLink;
use App\ClubUnio\Models\Announcement;
use App\ClubUnio\Models\BillingRecipient;
use App\ClubUnio\Models\Country;
use App\ClubUnio\Models\DocumentType;
use App\ClubUnio\Models\Family;
use App\ClubUnio\Models\FamilyMember;
use App\ClubUnio\Models\Gender;
use App\ClubUnio\Models\InvoiceWorkflowStatus;
use App\ClubUnio\Models\LegacyMaterialReceipt;
use App\ClubUnio\Models\MaterialReceipt;
use App\ClubUnio\Models\Member;
use App\ClubUnio\Models\MemberChangeRequest;
use App\ClubUnio\Models\MemberDocument;
use App\ClubUnio\Models\MemberStatus;
use App\ClubUnio\Models\MemberType;
use App\ClubUnio\Models\MessageTemplate;
use App\ClubUnio\Models\MessageTemplateRevision;
use App\ClubUnio\Models\ObjectChange;
use App\ClubUnio\Models\Registration;
use App\ClubUnio\Models\RegistrationStatus;
use App\ClubUnio\Models\Season;
use App\ClubUnio\Models\StaffFunction;
use App\ClubUnio\Models\Team;
use App\ClubUnio\Models\TeamPlayer;
use App\ClubUnio\Models\TeamSeason;
use App\ClubUnio\Models\TeamStaff;
use App\ClubUnio\Models\TechnicalLead;
use App\ClubUnio\Policies\CatalogPolicy;
use App\ClubUnio\Policies\DossierMaintenancePolicy;
use App\ClubUnio\Policies\MemberPolicy;
use App\ClubUnio\Policies\ReadOnlyPolicy;
use App\ClubUnio\Policies\TeamAssignmentPolicy;
use App\ClubUnio\Services\BackofficeHandleComponents;
use App\ClubUnio\Services\StatusAppearance;
use App\ClubUnio\Services\StatusStyleService;
use App\ClubUnio\Services\TestMailGuard;
use App\Livewire\ClubUnio\SignageFeeds;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\HandleComponents;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        config(['database.connections.clubunio' => config('clubunio.connection')]);
        $this->app->scoped(StatusStyleService::class);
        $this->app->singleton(HandleComponents::class, BackofficeHandleComponents::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Livewire\on('call', function ($component, string $method) {
            if ($method !== 'callMountedAction' || ! method_exists($component, 'getMountedActions')
                || ! str_starts_with($component::class, 'App\\ClubUnio\\')) {
                return;
            }
            foreach ($component->getMountedActions() as $action) {
                abort_unless(\App\ClubUnio\Resources\ProfileUi::actionAllowed($action), 403);
            }
        });
        Gate::before(function ($actor, string $ability, array $arguments) {
            return $actor instanceof \App\ClubUnio\Models\Account
                ? app(\App\ClubUnio\Policies\ProfilePolicyBridge::class)->check($actor, $ability, $arguments) : null;
        });
        Livewire::component('clubunio.signage-feeds', SignageFeeds::class);
        \Filament\Schemas\Components\Tabs::configureUsing(function ($tabs) {
            $tabs->extraAttributes(fn ($component) => $component->getLivewireProperty() === 'activeRelationManager' ? [
                'wire:loading.class' => 'cu-dossier-loading',
                'wire:target' => 'activeRelationManager',
                'wire:loading.attr' => 'aria-busy',
                'data-loading-message' => 'Ansicht wird geladen …',
            ] : [], merge: true);
        });
        // Shared display semantics for status/lookup values in every ClubUnio table and detail.
        foreach ([TextColumn::class, TextEntry::class] as $component) {
            $component::configureUsing(function ($entry) {
                if (in_array($entry->getName(), ['status.name', 'type.name', 'gender.name', 'period.name', 'weekday.name', 'field.name', 'room.name', 'side.name'], true)) {
                    $entry->badge()->color(fn ($state) => StatusAppearance::palette($state));
                }
            });
        }
        Gate::policy(AccountMemberLink::class, ReadOnlyPolicy::class);
        Gate::policy(InvoiceWorkflowStatus::class, CatalogPolicy::class);
        config(['mail.mailers.clubunio_test' => config('clubunio-integrations.smtp')]);
        Event::listen(MessageSending::class, TestMailGuard::class);
        Gate::policy(MessageTemplate::class, ReadOnlyPolicy::class);
        Gate::policy(MessageTemplateRevision::class, ReadOnlyPolicy::class);
        foreach (['ClubFunction', 'Qualification',
            'MemberBankAccount', 'MemberNote', 'MemberFunction', 'MemberKey', 'Sponsorship', 'ClubEvent', 'EventGuest', 'Invoice', 'InvoiceLine', 'Expense', 'ExpenseLine'] as $name) {
            Gate::policy('App\\ClubUnio\\Models\\'.$name, ReadOnlyPolicy::class);
        }
        foreach (['InvoiceStatus', 'InvoiceLineStatus', 'Bank', 'ExpenseStatus', 'ExpenseRound', 'GameType', 'ExpenseType', 'ContributionPrice', 'ContributionRule', 'TrainingSlot', 'TrainingOption'] as $name) {
            Gate::policy('App\\ClubUnio\\Models\\'.$name, CatalogPolicy::class);
        }
        foreach (['ClubFunction', 'Qualification', 'ClubEvent', 'MemberFunction', 'MemberKey', 'Sponsorship', 'EventGuest'] as $name) {
            Gate::policy('App\\ClubUnio\\Models\\'.$name, DossierMaintenancePolicy::class);
        }
        foreach ([Country::class, Gender::class, BillingRecipient::class, DocumentType::class, Family::class, FamilyMember::class] as $model) {
            Gate::policy($model, CatalogPolicy::class);
        }
        Gate::policy(MemberDocument::class, ReadOnlyPolicy::class);
        Gate::policy(MemberChangeRequest::class, ReadOnlyPolicy::class);
        Gate::policy(Announcement::class, ReadOnlyPolicy::class);
        Gate::policy(MaterialReceipt::class, ReadOnlyPolicy::class);
        Gate::policy(LegacyMaterialReceipt::class, ReadOnlyPolicy::class);
        Gate::policy(ObjectChange::class, ReadOnlyPolicy::class);
        foreach ([Team::class, Season::class, MemberStatus::class, MemberType::class, RegistrationStatus::class] as $model) {
            Gate::policy($model, CatalogPolicy::class);
        }
        Gate::policy(Member::class, MemberPolicy::class);
        foreach ([TeamSeason::class, TeamPlayer::class, TeamStaff::class, TechnicalLead::class] as $model) {
            Gate::policy($model, TeamAssignmentPolicy::class);
        }
        Gate::policy(StaffFunction::class, CatalogPolicy::class);
        foreach ([Registration::class] as $model) {
            Gate::policy($model, ReadOnlyPolicy::class);
        }
        if (! app()->environment(['local', 'testing', 'staging'])) {
            throw new \LogicException('ClubUnio currently only supports local, testing and staging.');
        }
    }
}

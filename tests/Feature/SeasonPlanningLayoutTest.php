<?php

namespace Tests\Feature;

use App\ClubUnio\Models\{Account, Tenant};
use App\ClubUnio\Pages\SeasonPlanning;
use App\ClubUnio\Services\BackofficeReadContext;
use App\ClubUnio\Services\PitchWeeklyAreas;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

final class SeasonPlanningLayoutTest extends TestCase
{
    public function test_all_seven_steps_render_with_native_controls_without_touching_saved_plans(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('clubunio'));
        $actor = new class extends Account {
            public function fresh($with = []) { return $this; }
            public function hasUnrestrictedBackofficeAccess(\Illuminate\Database\Eloquent\Model $tenant): bool { return true; }
            public function getTenants(\Filament\Panel $panel): array|\Illuminate\Support\Collection { return collect([Filament::getTenant()]); }
        };
        $this->actingAs($actor->forceFill(['id' => 1, 'active' => true]), 'clubunio');
        Filament::setTenant((new Tenant)->forceFill(['id' => 1, 'slug' => 'layout-fixture']), isQuiet: true);
        $context = $this->mock(BackofficeReadContext::class);
        $context->shouldReceive('duringRender')->andReturnUsing(fn ($render) => $render());
        $context->shouldReceive('withoutSnapshot')->andReturnUsing(fn ($render) => $render());
        $context->shouldReceive('tenantsFor')->andReturn(collect());
        $areas = $this->mock(PitchWeeklyAreas::class);
        $areas->shouldReceive('ready')->andReturn(true);
        $areas->shouldReceive('trainingOptions')->andReturn([1 => 'Court A']);

        $page = Livewire::test(SeasonPlanningLayoutFixture::class)
            ->assertSeeHtml('class="fi-empty-state')
            ->call('showDraft')
            ->assertSeeHtml('fi-input-wrp')
            ->assertSeeHtml('aria-current="step"')
            ->assertSeeHtml('wire:model.live.debounce.1000ms="document.season.name"');

        foreach ([
            2 => 'wire:change="selectTeam(',
            3 => 'wire:model.live.debounce.1000ms="document.prices.0.amount"',
            4 => 'draggable="true"',
            5 => 'wire:model.live="functionId"',
            6 => 'wire:model.live="document.slots.0.area_id"',
            7 => 'wire:click="checkPlan"',
        ] as $step => $control) {
            $page->set('step', $step)->assertSuccessful()->assertSeeHtml($control);
        }
    }
}

class SeasonPlanningLayoutFixture extends SeasonPlanning
{
    public function showDraft(): void
    {
        $this->planId = 42;
        $this->revision = 1;
        $this->document = [
            'season' => ['name' => '2027/2028'],
            'teams' => [['key' => 'team', 'name' => 'Academy U17', 'team_id' => 1, 'rule_key' => null, 'capacity' => 18]],
            'prices' => [['key' => 'price', 'name' => 'Junior']],
            'rules' => [['key' => 'rule', 'price_key' => 'price', 'year_from' => 2009, 'year_to' => 2011]],
            'assignments' => [],
            'slots' => [['key' => 'slot', 'field_id' => 1]],
        ];
    }

    protected function getViewData(): array
    {
        return [
            'facts' => array_fill_keys(['seasons', 'teams', 'genders', 'staff_functions', 'training_options'], []),
            'drafts' => [(object) ['id' => 42, 'name' => '2027/2028', 'state' => 'draft']],
            'draftCount' => 1,
            'pool' => [(object) ['id' => 12, 'first_name' => 'Sample', 'last_name' => 'Player', 'birth_date' => '2010-01-01']],
            'total' => 1,
        ];
    }

    public function updated($field): void
    {
        $this->kind = $this->step === 5 ? 'staff' : 'player';
    }
}

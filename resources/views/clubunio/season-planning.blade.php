<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/season-planning.css') }}?v=4">
    @php
        $lookupTenantId = (int) \Filament\Facades\Filament::getTenant()->getKey();
        // These projected facts are already scoped to this tenant; retain their source identity.
        $seasonLookup = fn (string $table, ?object $row, mixed $fallback = null) => $row
            ? app(\App\ClubUnio\Services\LookupTranslations::class)->label($table,
                ['id' => $row->id, 'name' => $row->name, 'tenant_id' => $lookupTenantId], tenant: $lookupTenantId)
            : $fallback;
        $editable = $planId && $state === 'draft' && \App\ClubUnio\Resources\ProfileUi::allows('seasons', 'create');
        $canFinish = $editable && \App\ClubUnio\Resources\ProfileUi::allows('seasons', 'transfer') && \App\ClubUnio\Resources\ProfileUi::allows('teams', 'create') && \App\ClubUnio\Resources\ProfileUi::allows('contributions', 'create') && \App\ClubUnio\Resources\ProfileUi::allows('training_slots', 'create');
        $steps = [1 => __('Saison'), 2 => __('Mannschaften'), 3 => __('Beiträge'), 4 => __('Spieler'), 5 => __('Staff & TL'), 6 => __('Trainingszeiten'), 7 => __('Abschluss')];
        $status = ['draft' => __('Entwurf'), 'completed' => __('Abgeschlossen'), 'discarded' => __('Verworfen')];
        $stepIcons = [1 => 'heroicon-o-calendar-days', 2 => 'heroicon-o-rectangle-stack', 3 => 'heroicon-o-banknotes', 4 => 'heroicon-o-users', 5 => 'heroicon-o-identification', 6 => 'heroicon-o-clock', 7 => 'heroicon-o-clipboard-document-check'];
        $labels = ['player' => __('Spieler'), 'staff' => __('Staff'), 'lead' => __('Technische Leitung')];
    @endphp
    <div class="season-planner" x-data="{ drag: null, newPlanConfirmation: false, discardConfirmation: false, finishConfirmation: false }" wire:loading.class="sp-busy">
        @if($errors->any())
            <div class="sp-alert sp-error" role="alert"><strong>{{ __('Nicht gespeichert oder abgeschlossen') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <div class="cu-admin-toolbar sp-plan-toolbar">
            <div class="sp-plan-search">
                <label class="cu-admin-field">{{ __('Planung suchen') }}<x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass"><x-filament::input type="search" maxlength="100" wire:model.live.debounce.500ms="planSearch" /></x-filament::input.wrapper></label>
                <x-filament::icon-button wire:click="$set('planSearch', '')" icon="heroicon-o-x-mark" label="{{ __('Planungssuche leeren') }}" tooltip="{{ __('Planungssuche leeren') }}" :disabled="$planSearch === ''" />
            </div>
            <label class="cu-admin-field sp-plan-picker">{{ __('Planung öffnen') }}
                <x-filament::input.wrapper><x-filament::input.select aria-label="{{ __('Planung öffnen') }}" wire:change="openPlan($event.target.value)" wire:loading.attr="disabled">
                    <option value="" disabled @selected(!$planId)>{{ __('Auswählen') }}</option>
                    @foreach($drafts as $draft)<option value="{{ $draft->id }}" @selected($draft->id === $planId)>{{ $draft->name }} · {{ $status[$draft->state] }} · #{{ $draft->id }}</option>@endforeach
                </x-filament::input.select></x-filament::input.wrapper>
            </label>
            @if($draftCount > 50 || $draftPage > 1)
                <div class="sp-pagination">
                    <x-filament::icon-button wire:click="browseDrafts(-1)" :disabled="$draftPage <= 1" icon="heroicon-o-chevron-left" label="{{ __('Vorherige Planungen') }}" tooltip="{{ __('Vorherige Planungen') }}" />
                    <span>{{ $draftPage }} / {{ max(1, (int) ceil($draftCount / 50)) }}</span>
                    <x-filament::icon-button wire:click="browseDrafts(1)" :disabled="$draftPage * 50 >= $draftCount" icon="heroicon-o-chevron-right" label="{{ __('Weitere Planungen') }}" tooltip="{{ __('Weitere Planungen') }}" />
                </div>
            @endif
            @if(\App\ClubUnio\Resources\ProfileUi::allows('seasons', 'create'))
                <label class="cu-admin-field">{{ __('Vorlage') }} <x-filament::input.wrapper><x-filament::input.select wire:model="sourceId"><option value="">{{ __('Ohne Vorlage') }}</option>@foreach(collect($facts['seasons'])->sortByDesc('name') as $season)<option value="{{ $season->id }}">{{ $season->name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                <x-filament::button x-on:click="newPlanConfirmation = !newPlanConfirmation" x-bind:aria-expanded="newPlanConfirmation" aria-controls="sp-new-plan-confirmation" wire:loading.attr="disabled" icon="heroicon-o-plus">{{ __('Neue Planung') }}</x-filament::button>
            @endif
        </div>
        @if(\App\ClubUnio\Resources\ProfileUi::allows('seasons', 'create'))
            <section id="sp-new-plan-confirmation" class="sp-alert" x-show="newPlanConfirmation" x-cloak aria-labelledby="sp-new-plan-title">
                <h2 id="sp-new-plan-title">{{ __('Neuen Entwurf anlegen?') }}</h2>
                <p>{{ __('Der aktuelle gespeicherte Entwurf bleibt erhalten. Eine Saison wird damit noch nicht verbindlich erstellt.') }}</p>
                <div class="sp-toolbar">
                    <x-filament::button x-on:click="newPlanConfirmation = false" color="gray">{{ __('Abbrechen') }}</x-filament::button>
                    <x-filament::button wire:click="newPlan" x-on:click="newPlanConfirmation = false" wire:loading.attr="disabled" icon="heroicon-o-plus">{{ __('Entwurf anlegen') }}</x-filament::button>
                </div>
            </section>
        @endif
        @if($planId)
            <div class="sp-status">
                <div class="sp-status-detail" role="status">
                    <div class="sp-status-title">
                        <strong>{{ $document['season']['name'] ?: __('Neue Saison') }}</strong>
                        <x-filament::badge :color="$state === 'completed' ? 'success' : ($state === 'draft' ? 'warning' : 'gray')">{{ $status[$state] }} #{{ $planId }}</x-filament::badge>
                    </div>
                    <span class="sp-note">{{ __('Version') }} {{ $revision }} · {{ $savedAt ? __('Gespeichert: ').\Carbon\Carbon::parse($savedAt)->timezone('Europe/Zurich')->format('d.m.Y H:i:s') : __('Nicht gespeichert') }}</span>
                </div>
                @if($editable)<x-filament::button wire:click="saveDraft" wire:loading.attr="disabled" color="gray" icon="heroicon-o-bookmark">{{ __('Speichern') }}</x-filament::button>@endif
            </div>
            @if($state === 'completed')
                <p class="sp-alert">{{ __('Saison mit allen geplanten Verknüpfungen erstellt. Die aktuelle Saisonwahl wurde nicht geändert. Rechnungen und Benachrichtigungen wurden nicht ausgelöst.') }}</p>
                @if($seasonId)<x-filament::button tag="a" :href="\App\ClubUnio\Resources\SeasonResource::getUrl('view', ['record' => $seasonId])" icon="heroicon-o-arrow-top-right-on-square">{{ __('Saison öffnen') }}</x-filament::button>@endif
            @elseif($state === 'discarded')<p class="sp-alert">{{ __('Verworfener Entwurf. Es wurden daraus keine Fachdaten erstellt.') }}</p>@endif
            <x-filament::tabs class="sp-steps" :label="__('Planungsschritte')">
                @foreach($steps as $number => $title)
                    <x-filament::tabs.item :active="$step === $number" :aria-current="$step === $number ? 'step' : null"
                        :icon="$stepIcons[$number]" wire:click="go({{ $number }})" wire:loading.attr="disabled">
                        {{ $title }}
                    </x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>
            <fieldset @disabled(!$editable) class="sp-editor">
            @if($step === 1)
                <section><h2>{{ __('Saison') }}</h2><div class="sp-fields">
                    <label class="cu-admin-field">{{ __('Bezeichnung') }}<x-filament::input.wrapper><x-filament::input maxlength="100" wire:model.live.debounce.1000ms="document.season.name" /></x-filament::input.wrapper></label>
                    <label class="cu-admin-field">{{ __('Beginn') }}<x-filament::input.wrapper><x-filament::input type="date" wire:model.live="document.season.starts_on" /></x-filament::input.wrapper></label>
                    <label class="cu-admin-field">{{ __('Ende') }}<x-filament::input.wrapper><x-filament::input type="date" wire:model.live="document.season.ends_on" /></x-filament::input.wrapper></label>
                </div>
                <p class="sp-note">{{ __('Planungsstand:') }} {{ count($document['teams']) }} {{ __('Mannschaften ·') }} {{ count($document['assignments']) }} {{ __('Zuordnungen ·') }} {{ count($document['slots']) }} {{ __('Trainingszeiten.') }}</p>
                <p class="sp-note">{{ __('Bestehende Stammdaten bleiben erhalten. Erst der Abschluss erstellt die neue Saison und ihre Verknüpfungen. Die aktuelle Saison wird nicht umgestellt.') }}</p>
                </section>
            @elseif($step === 2)
                <section><div class="sp-toolbar"><h2>{{ __('Mannschaften') }}</h2><x-filament::button wire:click="addRow('teams')" wire:loading.attr="disabled" icon="heroicon-o-plus">{{ __('Mannschaft') }}</x-filament::button></div>
                    @foreach($document['teams'] as $i => $team)
                        <div class="sp-row" wire:key="team-{{ $team['key'] }}">
                            <div class="sp-fields">
                                <label class="cu-admin-field">{{ __('Stamm-Mannschaft') }}<x-filament::input.wrapper><x-filament::input.select wire:change="selectTeam({{ $i }}, $event.target.value || null)"><option value="" @selected(!$team['team_id'])>{{ __('Neue Mannschaft') }}</option>@foreach($facts['teams'] as $t)<option value="{{ $t->id }}" @selected($t->id == $team['team_id'])>{{ $t->name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                                <label class="cu-admin-field">{{ __('Bezeichnung') }}<x-filament::input.wrapper :disabled="(bool) $team['team_id']"><x-filament::input wire:model.live.debounce.1000ms="document.teams.{{ $i }}.name" :disabled="(bool) $team['team_id']" maxlength="160" /></x-filament::input.wrapper></label>
                                @if(!$team['team_id'])<label class="cu-admin-field">{{ __('Dressentschädigung CHF') }}<x-filament::input.wrapper><x-filament::input type="number" step="0.01" min="0" wire:model.live.debounce.1000ms="document.teams.{{ $i }}.laundry_allowance" /></x-filament::input.wrapper></label>@endif
                                <label class="cu-admin-field">{{ __('Beitragsregel für Vorschläge') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="document.teams.{{ $i }}.rule_key"><option value="">{{ __('Eigener Jahrgangsbereich') }}</option>@foreach($document['rules'] as $rule)<option value="{{ $rule['key'] }}">{{ collect($document['prices'])->firstWhere('key', $rule['price_key'])['name'] ?? __('Beitrag') }} · {{ $rule['year_from'] }}–{{ $rule['year_to'] }} · {{ $seasonLookup('genders', $facts['genders'][$rule['gender_id'] ?? 0] ?? null, '–') }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                                @if(!$team['rule_key'])
                                    <label class="cu-admin-field">{{ __('Jahrgang von') }}<x-filament::input.wrapper><x-filament::input type="number" min="1800" max="2200" wire:model.live.debounce.1000ms="document.teams.{{ $i }}.year_from" /></x-filament::input.wrapper></label>
                                    <label class="cu-admin-field">{{ __('Jahrgang bis') }}<x-filament::input.wrapper><x-filament::input type="number" min="1800" max="2200" wire:model.live.debounce.1000ms="document.teams.{{ $i }}.year_to" /></x-filament::input.wrapper></label>
                                    <label class="cu-admin-field">{{ __('Geschlecht') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="document.teams.{{ $i }}.gender_id"><option value="">{{ __('Alle') }}</option>@foreach($facts['genders'] as $g)<option value="{{ $g->id }}">{{ $seasonLookup('genders', $g) }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                                @endif
                                <label class="cu-admin-field">{{ __('Zielgrösse Spieler') }}<x-filament::input.wrapper><x-filament::input type="number" min="1" max="200" wire:model.live.debounce.1000ms="document.teams.{{ $i }}.capacity" /></x-filament::input.wrapper></label>
                            </div>
                            <div class="sp-toolbar">
                                <x-filament::icon-button wire:click="removeRow('teams', '{{ $team['key'] }}')" wire:confirm="{{ __('Mannschaft einschliesslich ihrer geplanten Zuordnungen und Trainingszeiten aus diesem Entwurf entfernen?') }}" icon="heroicon-o-trash" label="{{ __('Mannschaft entfernen') }}" tooltip="{{ __('Mannschaft entfernen') }}" color="danger" wire:loading.attr="disabled" />
                                @if(count($document['teams']) > 1)
                                    <label class="cu-admin-field">{{ __('Zusammenführen nach') }}<x-filament::input.wrapper><x-filament::input.select aria-label="{{ __('Zielmannschaft für Zusammenführung') }}" wire:change="mergeTeam('{{ $team['key'] }}', $event.target.value)" wire:confirm="{{ __('Zuordnungen und Trainingszeiten verschieben und diese Mannschaft aus dem Entwurf entfernen?') }}"><option value="">{{ __('Auswählen') }}</option>@foreach($document['teams'] as $other)@if($other['key'] !== $team['key'])<option value="{{ $other['key'] }}">{{ $other['name'] ?: __('Neue Mannschaft') }}</option>@endif @endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </section>
            @elseif($step === 3)
                <section><div class="sp-toolbar"><h2>{{ __('Beitragspreise') }}</h2><x-filament::button wire:click="addRow('prices')" wire:loading.attr="disabled" icon="heroicon-o-plus">{{ __('Preis') }}</x-filament::button></div>
                    @foreach($document['prices'] as $i => $price)<div class="sp-row sp-fields" wire:key="price-{{ $price['key'] }}">
                        <label class="cu-admin-field">{{ __('Bezeichnung') }}<x-filament::input.wrapper><x-filament::input maxlength="160" wire:model.live.debounce.1000ms="document.prices.{{ $i }}.name" /></x-filament::input.wrapper></label>
                        <label class="cu-admin-field">{{ __('Betrag CHF') }}<x-filament::input.wrapper><x-filament::input type="number" min="0" step="0.0001" wire:model.live.debounce.1000ms="document.prices.{{ $i }}.amount" /></x-filament::input.wrapper></label>
                        <x-filament::icon-button wire:click="removeRow('prices', '{{ $price['key'] }}')" icon="heroicon-o-trash" color="danger" label="{{ __('Preis entfernen') }}" tooltip="{{ __('Preis entfernen') }}" wire:loading.attr="disabled" />
                    </div>@endforeach
                </section>
                <section><div class="sp-toolbar"><h2>{{ __('Beitragsregeln') }}</h2><x-filament::button wire:click="addRow('rules')" wire:loading.attr="disabled" icon="heroicon-o-plus">{{ __('Regel') }}</x-filament::button></div>
                    @foreach($document['rules'] as $i => $rule)<div class="sp-row sp-fields" wire:key="rule-{{ $rule['key'] }}">
                        <label class="cu-admin-field">{{ __('Preis') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="document.rules.{{ $i }}.price_key"><option value="">{{ __('Auswählen') }}</option>@foreach($document['prices'] as $p)<option value="{{ $p['key'] }}">{{ $p['name'] }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                        <label class="cu-admin-field">{{ __('Geschlecht') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="document.rules.{{ $i }}.gender_id"><option value="">{{ __('Auswählen') }}</option>@foreach($facts['genders'] as $g)<option value="{{ $g->id }}">{{ $seasonLookup('genders', $g) }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                        <label class="cu-admin-field">{{ __('Jahrgang von') }}<x-filament::input.wrapper><x-filament::input type="number" min="1800" max="2200" wire:model.live.debounce.1000ms="document.rules.{{ $i }}.year_from" /></x-filament::input.wrapper></label>
                        <label class="cu-admin-field">{{ __('Jahrgang bis') }}<x-filament::input.wrapper><x-filament::input type="number" min="1800" max="2200" wire:model.live.debounce.1000ms="document.rules.{{ $i }}.year_to" /></x-filament::input.wrapper></label>
                        <x-filament::icon-button wire:click="removeRow('rules', '{{ $rule['key'] }}')" icon="heroicon-o-trash" color="danger" label="{{ __('Regel entfernen') }}" tooltip="{{ __('Regel entfernen') }}" wire:loading.attr="disabled" />
                    </div>@endforeach
                    <p class="sp-note">{{ __('Vorschläge beruhen auf Jahrgang und Geschlecht der Beitragsregel, nicht auf einer sportrechtlichen Spielberechtigung. Bei einer Vorlage werden die Jahrgangsgrenzen um ein Jahr verschoben.') }}</p>
                </section>
            @elseif(in_array($step, [4, 5]))
                <section><h2>{{ $step === 4 ? __('Spielerzuordnung') : __('Staff und technische Leitung') }}</h2>
                    <div class="sp-fields">
                        <label class="cu-admin-field">{{ __('Zuordnung') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="kind">@foreach($labels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                        @if($kind === 'staff')<label class="cu-admin-field">{{ __('Staff-Funktion') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="functionId"><option value="">{{ __('Auswählen') }}</option>@foreach($facts['staff_functions'] as $f)<option value="{{ $f->id }}">{{ $seasonLookup('staff_functions', $f) }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>@endif
                        <label class="cu-admin-field">{{ __('Zielmannschaft') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="targetTeam"><option value="">{{ __('Auswählen') }}</option>@foreach($document['teams'] as $t)<option value="{{ $t['key'] }}">{{ $t['name'] }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                        <label class="cu-admin-field">{{ __('Mitglied suchen') }}<x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.500ms="search" /></x-filament::input.wrapper></label>
                        <label class="cu-admin-field">{{ __('Jahrgang') }}<x-filament::input.wrapper><x-filament::input type="number" min="1800" max="2200" wire:model.live.debounce.500ms="birthYear" /></x-filament::input.wrapper></label>
                        <x-filament::button color="gray" icon="heroicon-o-x-mark" x-on:click="$wire.$set('search', '', false); $wire.$set('birthYear', null, false); $wire.$set('onlyUnassigned', true, false); $wire.$set('onlyMatching', false)" wire:loading.attr="disabled">{{ __('Filter zurücksetzen') }}</x-filament::button>
                        <label class="sp-check"><x-filament::input.checkbox wire:model.live="onlyUnassigned" />{{ __('Noch nicht zugeordnet') }}</label>
                        @if($kind === 'player')<label class="sp-check"><x-filament::input.checkbox wire:model.live="onlyMatching" />{{ __('Passender Jahrgang für Zielmannschaft') }}</label>@endif
                    </div>
                    <div class="sp-board">
                        <div class="sp-pool"><h3>{{ __('Mitgliederpool (') }}{{ $total }})</h3><div class="sp-pool-members">
                            @foreach($pool as $member)<div class="sp-person" wire:key="pool-{{ $member->id }}" draggable="{{ $editable ? 'true' : 'false' }}" x-on:dragstart="drag = {kind: $wire.kind, member: {{ $member->id }}, from: null}; $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', '{{ $member->id }}')" x-on:dragend="drag = null">
                                <span>{{ app(\App\ClubUnio\Services\SeasonPlanningService::class)->memberLabel($member) }}<small>{{ substr($member->birth_date ?? '', 0, 4) ?: __('Jahrgang unbekannt') }}</small></span>
                                <x-filament::icon-button wire:click="moveMember('{{ $kind }}', {{ $member->id }}, null, '{{ $targetTeam }}')" :disabled="!$targetTeam" wire:loading.attr="disabled" icon="heroicon-o-arrow-right" label="{{ __('Der gewählten Mannschaft zuordnen') }}" tooltip="{{ __('Der gewählten Mannschaft zuordnen') }}" />
                            </div>@endforeach</div>
                            <div class="sp-pagination"><x-filament::icon-button wire:click="browsePool(-1)" :disabled="$poolPage <= 1" icon="heroicon-o-chevron-left" label="{{ __('Vorherige Mitglieder') }}" tooltip="{{ __('Vorherige Mitglieder') }}" /><span>{{ $poolPage }} / {{ max(1, (int) ceil($total / 40)) }}</span><x-filament::icon-button wire:click="browsePool(1)" :disabled="$poolPage * 40 >= $total" icon="heroicon-o-chevron-right" label="{{ __('Weitere Mitglieder') }}" tooltip="{{ __('Weitere Mitglieder') }}" /></div>
                        </div>
                        <div class="sp-teams">
                            @foreach($document['teams'] as $team)
                                @php($range = app(\App\ClubUnio\Services\SeasonPlanningService::class)->range($team, $document))
                                @php($links = collect($document['assignments'])->where('team_key', $team['key'])->where('kind', $kind))
                                <div class="sp-team" wire:key="board-{{ $team['key'] }}" x-on:dragover.prevent="$event.dataTransfer.dropEffect = 'move'" x-on:drop.prevent="if (drag) { $wire.moveMember(drag.kind, drag.member, drag.from, '{{ $team['key'] }}', drag.functionId ?? null); drag = null }">
                                    <h3><span>{{ $team['name'] ?: __('Neue Mannschaft') }}</span> <x-filament::badge color="gray">{{ $links->count() }}{{ $kind === 'player' && $team['capacity'] ? ' / '.$team['capacity'] : '' }}</x-filament::badge></h3>
                                    @if($kind === 'player')<p class="sp-note">{{ __('Jahrgänge') }} {{ $range['year_from'] ?? '–' }}–{{ $range['year_to'] ?? '–' }} · {{ $seasonLookup('genders', $facts['genders'][$range['gender_id'] ?? 0] ?? null, __('Alle')) }}</p>
                                        <x-filament::button size="sm" color="gray" wire:click="suggest('{{ $team['key'] }}')" wire:confirm="{{ __('Passende, noch nicht zugeordnete Spieler bis zur Zielgrösse ergänzen?') }}" wire:loading.attr="disabled" icon="heroicon-o-sparkles">{{ __('Vorschläge übernehmen') }}</x-filament::button>
                                    @endif
                                    @foreach($links as $assignment)
                                        @php($member = $facts['members'][$assignment['member_id']] ?? null)
                                        <div class="sp-person" wire:key="assigned-{{ $assignment['key'] }}" draggable="{{ $editable ? 'true' : 'false' }}" x-on:dragstart="drag = {kind: '{{ $assignment['kind'] }}', member: {{ $assignment['member_id'] }}, from: '{{ $team['key'] }}', functionId: {{ $kind === 'staff' ? (int) $assignment['function_id'] : 'null' }}}; $event.dataTransfer.setData('text/plain', '{{ $assignment['member_id'] }}')" x-on:dragend="drag = null">
                                            <span>{{ $member ? app(\App\ClubUnio\Services\SeasonPlanningService::class)->memberLabel($member) : __('Inaktives/fehlendes Mitglied #').$assignment['member_id'] }}<small>{{ $kind === 'staff' ? $seasonLookup('staff_functions', $facts['staff_functions'][$assignment['function_id'] ?? 0] ?? null) : substr($member?->birth_date ?? '', 0, 4) }}</small></span>
                                            <x-filament::icon-button wire:click="removeRow('assignments', '{{ $assignment['key'] }}')" wire:loading.attr="disabled" icon="heroicon-o-x-mark" color="danger" label="{{ __('Zuordnung entfernen') }}" tooltip="{{ __('Zuordnung entfernen') }}" />
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            @elseif($step === 6)
                @php($neutralFacilities = \App\ClubUnio\Services\FacilityPlanningMode::neutral(\Filament\Facades\Filament::getTenant()))
                <section><div class="sp-toolbar"><h2>{{ __('Trainingszeiten') }}</h2><x-filament::button wire:click="addRow('slots')" wire:loading.attr="disabled" icon="heroicon-o-plus">{{ __('Trainingszeit') }}</x-filament::button></div>
                    @foreach($document['slots'] as $i => $slot)<div class="sp-row" wire:key="slot-{{ $slot['key'] }}"><div class="sp-fields">
                        <label class="cu-admin-field">{{ __('Mannschaft') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="document.slots.{{ $i }}.team_key"><option value="">{{ __('Auswählen') }}</option>@foreach($document['teams'] as $t)<option value="{{ $t['key'] }}">{{ $t['name'] }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                        @foreach(['period' => __('Periode'), 'weekday' => __('Wochentag'), 'field' => __($neutralFacilities ? 'Bookable facility' : 'Platz'), 'room' => __('Garderobe'), 'side' => __('Platzteil')] as $category => $caption)
                            <label class="cu-admin-field">{{ $caption }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="document.slots.{{ $i }}.{{ $category }}_id"><option value="">{{ __('Auswählen') }}</option>@foreach($facts['training_options'] as $option)@if($option->category === $category)<option value="{{ $option->id }}">{{ $seasonLookup('training_options', $option) }}</option>@endif @endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                        @endforeach
                        <label class="cu-admin-field">{{ __('Von') }}<x-filament::input.wrapper><x-filament::input type="time" wire:model.live="document.slots.{{ $i }}.starts_at" /></x-filament::input.wrapper></label>
                        @if(app(\App\ClubUnio\Services\PitchWeeklyAreas::class)->ready())
                            <label class="cu-admin-field">{{ __('Teilfläche') }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="document.slots.{{ $i }}.area_id"><option value="">{{ __($neutralFacilities ? 'Entire facility' : 'Ganzer Platz') }}</option>@foreach(app(\App\ClubUnio\Services\PitchWeeklyAreas::class)->trainingOptions(\Filament\Facades\Filament::getTenant(), $slot['field_id'] ?: null) as $areaId => $areaName)<option value="{{ $areaId }}">{{ $areaName }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                        @endif
                        <label class="cu-admin-field">{{ __('Bis') }}<x-filament::input.wrapper><x-filament::input type="time" wire:model.live="document.slots.{{ $i }}.ends_at" /></x-filament::input.wrapper></label>
                        @unless($neutralFacilities)<label class="cu-admin-field">{{ __('Halle') }}<x-filament::input.wrapper><x-filament::input maxlength="255" wire:model.live.debounce.1000ms="document.slots.{{ $i }}.hall" /></x-filament::input.wrapper></label>@endunless
                        @foreach(['all_fields' => __('Alle Plätze'), 'without_main' => __('Ohne Hauptplatz'), 'synthetic_only' => __('Nur Kunstrasen'), 'website_visible' => __('Auf Webseite sichtbar')] as $column => $caption)
                            @if(!$neutralFacilities || $column === 'website_visible')<label class="sp-check"><x-filament::input.checkbox wire:model.live="document.slots.{{ $i }}.{{ $column }}" />{{ $caption }}</label>@endif
                        @endforeach
                        <x-filament::icon-button wire:click="removeRow('slots', '{{ $slot['key'] }}')" wire:loading.attr="disabled" icon="heroicon-o-trash" color="danger" label="{{ __('Trainingszeit entfernen') }}" tooltip="{{ __('Trainingszeit entfernen') }}" />
                    </div></div>@endforeach
                </section>
            @endif
            </fieldset>
            @if($step === 7)
                <section><div class="sp-toolbar"><h2>{{ __('Abschlussprüfung') }}</h2>@if($editable)<x-filament::button wire:click="checkPlan" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-path">{{ __('Erneut prüfen') }}</x-filament::button>@endif</div>
                    <dl class="sp-counts">@foreach(['teams' => __('Mannschaften'), 'prices' => __('Preise'), 'rules' => __('Beitragsregeln'), 'assignments' => __('Zuordnungen'), 'slots' => __('Trainingszeiten')] as $key => $title)<div><dt>{{ $title }}</dt><dd>{{ count($document[$key]) }}</dd></div>@endforeach</dl>
                    @if($review)
                        @if($review['errors'])<div class="sp-alert sp-error"><h3>{{ __('Vor dem Abschluss korrigieren') }}</h3><ul>@foreach($review['errors'] as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
                        @if($review['warnings'])<div class="sp-alert sp-warning"><h3>{{ __('Hinweise zur Planung') }}</h3><ul>@foreach($review['warnings'] as $message)<li>{{ $message }}</li>@endforeach</ul><label class="sp-check"><x-filament::input.checkbox wire:model.live="acknowledge" />{{ __('Hinweise geprüft; Abweichungen sind beabsichtigt.') }}</label></div>@endif
                        @if(!$review['errors'] && $canFinish)
                            <p class="sp-note">{{ __('Der Abschluss erstellt alle geplanten Fachdaten gemeinsam. Vorlagensaison und bestehende Mitglieder bleiben unverändert. Es erfolgen weder Rechnungsversand noch Push-Nachrichten oder externe Synchronisationen.') }}</p>
                            <x-filament::button x-on:click="finishConfirmation = !finishConfirmation" x-bind:aria-expanded="finishConfirmation" aria-controls="sp-finish-confirmation" wire:loading.attr="disabled" :disabled="count($review['warnings']) && !$acknowledge" icon="heroicon-o-check">{{ __('Saison verbindlich erstellen') }}</x-filament::button>
                            <div id="sp-finish-confirmation" x-show="finishConfirmation" x-cloak class="sp-alert" role="group" aria-labelledby="sp-finish-title">
                                <h3 id="sp-finish-title">{{ __('Saisonabschluss bestätigen') }}</h3>
                                <p>{{ $document['season']['name'] }} {{ __('wird jetzt mit sämtlichen geplanten Verknüpfungen erstellt. Der Entwurf ist danach nicht mehr bearbeitbar.') }}</p>
                                <div class="sp-toolbar">
                                    <x-filament::button x-on:click="finishConfirmation = false" color="gray">{{ __('Planung weiter bearbeiten') }}</x-filament::button>
                                    <x-filament::button wire:click="finish" x-on:click="finishConfirmation = false" wire:loading.attr="disabled" :disabled="count($review['warnings']) && !$acknowledge" icon="heroicon-o-check">{{ __('Abschluss bestätigen und erstellen') }}</x-filament::button>
                                </div>
                            </div>
                        @endif
                        @if($editable && !$canFinish)<p class="sp-note">{{ __('Für den Abschluss fehlen Erstellungsrechte für mindestens einen Bereich oder das Recht zur Saisonübernahme.') }}</p>@endif
                    @endif
                </section>
            @endif
            <div class="sp-toolbar sp-footer">
                @if($step > 1)<x-filament::button wire:click="go({{ $step - 1 }})" wire:loading.attr="disabled" color="gray" icon="heroicon-o-chevron-left">{{ __('Zurück') }}</x-filament::button>@endif
                @if($step < 7)<x-filament::button wire:click="go({{ $step + 1 }})" wire:loading.attr="disabled" icon="heroicon-o-chevron-right" icon-position="after">{{ __('Weiter') }}</x-filament::button>@endif
                @if($editable && \App\ClubUnio\Resources\ProfileUi::allows('seasons', 'deactivate'))<x-filament::button class="sp-discard" x-on:click="discardConfirmation = !discardConfirmation" x-bind:aria-expanded="discardConfirmation" aria-controls="sp-discard-confirmation" wire:loading.attr="disabled" color="danger" outlined icon="heroicon-o-archive-box">{{ __('Verwerfen') }}</x-filament::button>@endif
            </div>
            @if($editable && \App\ClubUnio\Resources\ProfileUi::allows('seasons', 'deactivate'))
                <section id="sp-discard-confirmation" class="sp-alert sp-error" x-show="discardConfirmation" x-cloak aria-labelledby="sp-discard-title">
                    <h2 id="sp-discard-title">{{ __('Diesen Entwurf verwerfen?') }}</h2>
                    <p>{{ __('Die Planung bleibt zur Nachverfolgung erhalten. Es werden daraus keine Fachdaten erstellt.') }}</p>
                    <div class="sp-toolbar">
                        <x-filament::button x-on:click="discardConfirmation = false" color="gray">{{ __('Weiter bearbeiten') }}</x-filament::button>
                        <x-filament::button wire:click="discard" x-on:click="discardConfirmation = false" wire:loading.attr="disabled" color="danger" icon="heroicon-o-archive-box">{{ __('Entwurf verwerfen') }}</x-filament::button>
                    </div>
                </section>
            @endif
        @else
            <x-filament::empty-state compact :heading="__('Noch keine Planung geöffnet.')" icon="heroicon-o-calendar-days" />
        @endif
        <p wire:loading role="status">{{ __('Planung wird verarbeitet …') }}</p>
    </div>
</x-filament-panels::page>

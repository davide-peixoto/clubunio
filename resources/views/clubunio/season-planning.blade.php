<x-filament-panels::page>
    <link rel="stylesheet" href="{{ asset('css/season-planning.css') }}?v=3">
    @php
        $editable = $planId && $state === 'draft' && \App\ClubUnio\Resources\ProfileUi::allows('seasons', 'create');
        $canFinish = $editable && \App\ClubUnio\Resources\ProfileUi::allows('seasons', 'transfer') && \App\ClubUnio\Resources\ProfileUi::allows('teams', 'create') && \App\ClubUnio\Resources\ProfileUi::allows('contributions', 'create') && \App\ClubUnio\Resources\ProfileUi::allows('training_slots', 'create');
        $steps = [1 => 'Saison', 2 => 'Mannschaften', 3 => 'Beiträge', 4 => 'Spieler', 5 => 'Staff & TL', 6 => 'Trainingszeiten', 7 => 'Abschluss'];
        $status = ['draft' => 'Entwurf', 'completed' => 'Abgeschlossen', 'discarded' => 'Verworfen'];
        $labels = ['player' => 'Spieler', 'staff' => 'Staff', 'lead' => 'Technische Leitung'];
    @endphp
    <div class="season-planner" x-data="{ drag: null, newPlanConfirmation: false, discardConfirmation: false, finishConfirmation: false }" wire:loading.class="sp-busy">
        @if($errors->any())
            <div class="sp-alert sp-error" role="alert"><strong>Nicht gespeichert oder abgeschlossen</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <div class="sp-toolbar">
            <label>Planung suchen<input type="search" maxlength="100" wire:model.live.debounce.500ms="planSearch"></label>
            <label>Planung öffnen
                <select aria-label="Planung öffnen" wire:change="openPlan($event.target.value)" wire:loading.attr="disabled">
                    <option value="" disabled @selected(!$planId)>Auswählen</option>
                    @foreach($drafts as $draft)<option value="{{ $draft->id }}" @selected($draft->id === $planId)>{{ $draft->name }} · {{ $status[$draft->state] }} · #{{ $draft->id }}</option>@endforeach
                </select>
            </label>
            @if($draftCount > 50 || $draftPage > 1)
                <x-filament::icon-button wire:click="browseDrafts(-1)" :disabled="$draftPage <= 1" icon="heroicon-o-chevron-left" label="Vorherige Planungen" />
                <span>{{ $draftPage }} / {{ max(1, (int) ceil($draftCount / 50)) }}</span>
                <x-filament::icon-button wire:click="browseDrafts(1)" :disabled="$draftPage * 50 >= $draftCount" icon="heroicon-o-chevron-right" label="Weitere Planungen" />
            @endif
            @if(\App\ClubUnio\Resources\ProfileUi::allows('seasons', 'create'))
                <label>Vorlage <select wire:model="sourceId"><option value="">Ohne Vorlage</option>@foreach(collect($facts['seasons'])->sortByDesc('name') as $season)<option value="{{ $season->id }}">{{ $season->name }}</option>@endforeach</select></label>
                <x-filament::button x-on:click="newPlanConfirmation = !newPlanConfirmation" x-bind:aria-expanded="newPlanConfirmation" aria-controls="sp-new-plan-confirmation" wire:loading.attr="disabled" icon="heroicon-o-plus">Neue Planung</x-filament::button>
            @endif
        </div>
        @if(\App\ClubUnio\Resources\ProfileUi::allows('seasons', 'create'))
            <section id="sp-new-plan-confirmation" x-show="newPlanConfirmation" x-cloak aria-labelledby="sp-new-plan-title">
                <h2 id="sp-new-plan-title">Neuen Entwurf anlegen?</h2>
                <p>Der aktuelle gespeicherte Entwurf bleibt erhalten. Eine Saison wird damit noch nicht verbindlich erstellt.</p>
                <div class="sp-toolbar">
                    <x-filament::button x-on:click="newPlanConfirmation = false" color="gray">Abbrechen</x-filament::button>
                    <x-filament::button wire:click="newPlan" x-on:click="newPlanConfirmation = false" wire:loading.attr="disabled" icon="heroicon-o-plus">Entwurf anlegen</x-filament::button>
                </div>
            </section>
        @endif
        @if($planId)
            <div class="sp-toolbar sp-status" role="status">
                <strong>{{ $document['season']['name'] ?: 'Neue Saison' }} · {{ $status[$state] }} #{{ $planId }}</strong>
                <span>Version {{ $revision }} · {{ $savedAt ? 'Gespeichert: '.\Carbon\Carbon::parse($savedAt)->timezone('Europe/Zurich')->format('d.m.Y H:i:s') : 'Nicht gespeichert' }}</span>
                @if($editable)<x-filament::button wire:click="saveDraft" wire:loading.attr="disabled" color="gray" icon="heroicon-o-bookmark">Speichern</x-filament::button>@endif
            </div>
            @if($state === 'completed')
                <p class="sp-alert">Saison mit allen geplanten Verknüpfungen erstellt. Die aktuelle Saisonwahl wurde nicht geändert. Rechnungen und Benachrichtigungen wurden nicht ausgelöst.</p>
                @if($seasonId)<x-filament::button tag="a" :href="\App\ClubUnio\Resources\SeasonResource::getUrl('view', ['record' => $seasonId])" icon="heroicon-o-arrow-top-right-on-square">Saison öffnen</x-filament::button>@endif
            @elseif($state === 'discarded')<p class="sp-alert">Verworfener Entwurf. Es wurden daraus keine Fachdaten erstellt.</p>@endif
            <nav class="sp-steps" aria-label="Planungsschritte">
                @foreach($steps as $number => $title)<button type="button" wire:click="go({{ $number }})" wire:loading.attr="disabled" @class(['is-current' => $step === $number]) @if($step === $number) aria-current="step" @endif><span>{{ $number }}</span> {{ $title }}</button>@endforeach
            </nav>
            <fieldset @disabled(!$editable) class="sp-editor">
            @if($step === 1)
                <section><h2>Saison</h2><div class="sp-fields">
                    <label>Bezeichnung<input maxlength="100" wire:model.live.debounce.1000ms="document.season.name"></label>
                    <label>Beginn<input type="date" wire:model.live="document.season.starts_on"></label>
                    <label>Ende<input type="date" wire:model.live="document.season.ends_on"></label>
                </div>
                <p class="sp-note">Planungsstand: {{ count($document['teams']) }} Mannschaften · {{ count($document['assignments']) }} Zuordnungen · {{ count($document['slots']) }} Trainingszeiten.</p>
                <p class="sp-note">Bestehende Stammdaten bleiben erhalten. Erst der Abschluss erstellt die neue Saison und ihre Verknüpfungen. Die aktuelle Saison wird nicht umgestellt.</p>
                </section>
            @elseif($step === 2)
                <section><div class="sp-toolbar"><h2>Mannschaften</h2><x-filament::button wire:click="addRow('teams')" wire:loading.attr="disabled" icon="heroicon-o-plus">Mannschaft</x-filament::button></div>
                    @foreach($document['teams'] as $i => $team)
                        <div class="sp-row" wire:key="team-{{ $team['key'] }}">
                            <div class="sp-fields">
                                <label>Stamm-Mannschaft<select wire:change="selectTeam({{ $i }}, $event.target.value || null)"><option value="" @selected(!$team['team_id'])>Neue Mannschaft</option>@foreach($facts['teams'] as $t)<option value="{{ $t->id }}" @selected($t->id == $team['team_id'])>{{ $t->name }}</option>@endforeach</select></label>
                                <label>Bezeichnung<input wire:model.live.debounce.1000ms="document.teams.{{ $i }}.name" @disabled($team['team_id']) maxlength="160"></label>
                                @if(!$team['team_id'])<label>Dressentschädigung CHF<input type="number" step="0.01" min="0" wire:model.live.debounce.1000ms="document.teams.{{ $i }}.laundry_allowance"></label>@endif
                                <label>Beitragsregel für Vorschläge<select wire:model.live="document.teams.{{ $i }}.rule_key"><option value="">Eigener Jahrgangsbereich</option>@foreach($document['rules'] as $rule)<option value="{{ $rule['key'] }}">{{ collect($document['prices'])->firstWhere('key', $rule['price_key'])['name'] ?? 'Beitrag' }} · {{ $rule['year_from'] }}–{{ $rule['year_to'] }} · {{ ($facts['genders'][$rule['gender_id'] ?? 0] ?? null)?->name ?? '–' }}</option>@endforeach</select></label>
                                @if(!$team['rule_key'])
                                    <label>Jahrgang von<input type="number" min="1800" max="2200" wire:model.live.debounce.1000ms="document.teams.{{ $i }}.year_from"></label>
                                    <label>Jahrgang bis<input type="number" min="1800" max="2200" wire:model.live.debounce.1000ms="document.teams.{{ $i }}.year_to"></label>
                                    <label>Geschlecht<select wire:model.live="document.teams.{{ $i }}.gender_id"><option value="">Alle</option>@foreach($facts['genders'] as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach</select></label>
                                @endif
                                <label>Zielgrösse Spieler<input type="number" min="1" max="200" wire:model.live.debounce.1000ms="document.teams.{{ $i }}.capacity"></label>
                            </div>
                            <div class="sp-toolbar">
                                <x-filament::icon-button wire:click="removeRow('teams', '{{ $team['key'] }}')" wire:confirm="Mannschaft einschliesslich ihrer geplanten Zuordnungen und Trainingszeiten aus diesem Entwurf entfernen?" icon="heroicon-o-trash" label="Mannschaft entfernen" color="danger" wire:loading.attr="disabled" />
                                @if(count($document['teams']) > 1)
                                    <label>Zusammenführen nach<select aria-label="Zielmannschaft für Zusammenführung" wire:change="mergeTeam('{{ $team['key'] }}', $event.target.value)" wire:confirm="Zuordnungen und Trainingszeiten verschieben und diese Mannschaft aus dem Entwurf entfernen?"><option value="">Auswählen</option>@foreach($document['teams'] as $other)@if($other['key'] !== $team['key'])<option value="{{ $other['key'] }}">{{ $other['name'] ?: 'Neue Mannschaft' }}</option>@endif@endforeach</select></label>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </section>
            @elseif($step === 3)
                <section><div class="sp-toolbar"><h2>Beitragspreise</h2><x-filament::button wire:click="addRow('prices')" wire:loading.attr="disabled" icon="heroicon-o-plus">Preis</x-filament::button></div>
                    @foreach($document['prices'] as $i => $price)<div class="sp-row sp-fields" wire:key="price-{{ $price['key'] }}">
                        <label>Bezeichnung<input maxlength="160" wire:model.live.debounce.1000ms="document.prices.{{ $i }}.name"></label>
                        <label>Betrag CHF<input type="number" min="0" step="0.0001" wire:model.live.debounce.1000ms="document.prices.{{ $i }}.amount"></label>
                        <x-filament::icon-button wire:click="removeRow('prices', '{{ $price['key'] }}')" icon="heroicon-o-trash" color="danger" label="Preis entfernen" wire:loading.attr="disabled" />
                    </div>@endforeach
                </section>
                <section><div class="sp-toolbar"><h2>Beitragsregeln</h2><x-filament::button wire:click="addRow('rules')" wire:loading.attr="disabled" icon="heroicon-o-plus">Regel</x-filament::button></div>
                    @foreach($document['rules'] as $i => $rule)<div class="sp-row sp-fields" wire:key="rule-{{ $rule['key'] }}">
                        <label>Preis<select wire:model.live="document.rules.{{ $i }}.price_key"><option value="">Auswählen</option>@foreach($document['prices'] as $p)<option value="{{ $p['key'] }}">{{ $p['name'] }}</option>@endforeach</select></label>
                        <label>Geschlecht<select wire:model.live="document.rules.{{ $i }}.gender_id"><option value="">Auswählen</option>@foreach($facts['genders'] as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach</select></label>
                        <label>Jahrgang von<input type="number" min="1800" max="2200" wire:model.live.debounce.1000ms="document.rules.{{ $i }}.year_from"></label>
                        <label>Jahrgang bis<input type="number" min="1800" max="2200" wire:model.live.debounce.1000ms="document.rules.{{ $i }}.year_to"></label>
                        <x-filament::icon-button wire:click="removeRow('rules', '{{ $rule['key'] }}')" icon="heroicon-o-trash" color="danger" label="Regel entfernen" wire:loading.attr="disabled" />
                    </div>@endforeach
                    <p class="sp-note">Vorschläge beruhen auf Jahrgang und Geschlecht der Beitragsregel, nicht auf einer sportrechtlichen Spielberechtigung. Bei einer Vorlage werden die Jahrgangsgrenzen um ein Jahr verschoben.</p>
                </section>
            @elseif(in_array($step, [4, 5]))
                <section><h2>{{ $step === 4 ? 'Spielerzuordnung' : 'Staff und technische Leitung' }}</h2>
                    <div class="sp-fields">
                        <label>Zuordnung<select wire:model.live="kind">@foreach($labels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                        @if($kind === 'staff')<label>Staff-Funktion<select wire:model.live="functionId"><option value="">Auswählen</option>@foreach($facts['staff_functions'] as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach</select></label>@endif
                        <label>Zielmannschaft<select wire:model.live="targetTeam"><option value="">Auswählen</option>@foreach($document['teams'] as $t)<option value="{{ $t['key'] }}">{{ $t['name'] }}</option>@endforeach</select></label>
                        <label>Mitglied suchen<input type="search" wire:model.live.debounce.500ms="search"></label>
                        <label>Jahrgang<input type="number" min="1800" max="2200" wire:model.live.debounce.500ms="birthYear"></label>
                        <label class="sp-check"><input type="checkbox" wire:model.live="onlyUnassigned">Noch nicht zugeordnet</label>
                        @if($kind === 'player')<label class="sp-check"><input type="checkbox" wire:model.live="onlyMatching">Passender Jahrgang für Zielmannschaft</label>@endif
                    </div>
                    <div class="sp-board">
                        <div class="sp-pool"><h3>Mitgliederpool ({{ $total }})</h3><div class="sp-pool-members">
                            @foreach($pool as $member)<div class="sp-person" wire:key="pool-{{ $member->id }}" draggable="{{ $editable ? 'true' : 'false' }}" x-on:dragstart="drag = {kind: $wire.kind, member: {{ $member->id }}, from: null}; $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', '{{ $member->id }}')" x-on:dragend="drag = null">
                                <span>{{ app(\App\ClubUnio\Services\SeasonPlanningService::class)->memberLabel($member) }}<small>{{ substr($member->birth_date ?? '', 0, 4) ?: 'Jahrgang unbekannt' }}</small></span>
                                <x-filament::icon-button wire:click="moveMember('{{ $kind }}', {{ $member->id }}, null, '{{ $targetTeam }}')" :disabled="!$targetTeam" wire:loading.attr="disabled" icon="heroicon-o-arrow-right" label="Der gewählten Mannschaft zuordnen" />
                            </div>@endforeach</div>
                            <div class="sp-toolbar"><x-filament::icon-button wire:click="browsePool(-1)" :disabled="$poolPage <= 1" icon="heroicon-o-chevron-left" label="Vorherige Mitglieder" /><span>{{ $poolPage }} / {{ max(1, (int) ceil($total / 40)) }}</span><x-filament::icon-button wire:click="browsePool(1)" :disabled="$poolPage * 40 >= $total" icon="heroicon-o-chevron-right" label="Weitere Mitglieder" /></div>
                        </div>
                        <div class="sp-teams">
                            @foreach($document['teams'] as $team)
                                @php($range = app(\App\ClubUnio\Services\SeasonPlanningService::class)->range($team, $document))
                                @php($links = collect($document['assignments'])->where('team_key', $team['key'])->where('kind', $kind))
                                <div class="sp-team" wire:key="board-{{ $team['key'] }}" x-on:dragover.prevent="$event.dataTransfer.dropEffect = 'move'" x-on:drop.prevent="if (drag) { $wire.moveMember(drag.kind, drag.member, drag.from, '{{ $team['key'] }}', drag.functionId ?? null); drag = null }">
                                    <h3>{{ $team['name'] ?: 'Neue Mannschaft' }} <span>{{ $links->count() }}{{ $kind === 'player' && $team['capacity'] ? ' / '.$team['capacity'] : '' }}</span></h3>
                                    @if($kind === 'player')<p class="sp-note">Jahrgänge {{ $range['year_from'] ?? '–' }}–{{ $range['year_to'] ?? '–' }} · {{ ($facts['genders'][$range['gender_id'] ?? 0] ?? null)?->name ?? 'Alle' }}</p>
                                        <x-filament::button size="sm" color="gray" wire:click="suggest('{{ $team['key'] }}')" wire:confirm="Passende, noch nicht zugeordnete Spieler bis zur Zielgrösse ergänzen?" wire:loading.attr="disabled" icon="heroicon-o-sparkles">Vorschläge übernehmen</x-filament::button>
                                    @endif
                                    @foreach($links as $assignment)
                                        @php($member = $facts['members'][$assignment['member_id']] ?? null)
                                        <div class="sp-person" wire:key="assigned-{{ $assignment['key'] }}" draggable="{{ $editable ? 'true' : 'false' }}" x-on:dragstart="drag = {kind: '{{ $assignment['kind'] }}', member: {{ $assignment['member_id'] }}, from: '{{ $team['key'] }}', functionId: {{ $kind === 'staff' ? (int) $assignment['function_id'] : 'null' }}}; $event.dataTransfer.setData('text/plain', '{{ $assignment['member_id'] }}')" x-on:dragend="drag = null">
                                            <span>{{ $member ? app(\App\ClubUnio\Services\SeasonPlanningService::class)->memberLabel($member) : 'Inaktives/fehlendes Mitglied #'.$assignment['member_id'] }}<small>{{ $kind === 'staff' ? ($facts['staff_functions'][$assignment['function_id'] ?? 0] ?? null)?->name : substr($member?->birth_date ?? '', 0, 4) }}</small></span>
                                            <x-filament::icon-button wire:click="removeRow('assignments', '{{ $assignment['key'] }}')" wire:loading.attr="disabled" icon="heroicon-o-x-mark" color="danger" label="Zuordnung entfernen" />
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                </section>
            @elseif($step === 6)
                <section><div class="sp-toolbar"><h2>Trainingszeiten</h2><x-filament::button wire:click="addRow('slots')" wire:loading.attr="disabled" icon="heroicon-o-plus">Trainingszeit</x-filament::button></div>
                    @foreach($document['slots'] as $i => $slot)<div class="sp-row" wire:key="slot-{{ $slot['key'] }}"><div class="sp-fields">
                        <label>Mannschaft<select wire:model.live="document.slots.{{ $i }}.team_key"><option value="">Auswählen</option>@foreach($document['teams'] as $t)<option value="{{ $t['key'] }}">{{ $t['name'] }}</option>@endforeach</select></label>
                        @foreach(['period' => 'Periode', 'weekday' => 'Wochentag', 'field' => 'Platz', 'room' => 'Garderobe', 'side' => 'Platzteil'] as $category => $caption)
                            <label>{{ $caption }}<select wire:model.live="document.slots.{{ $i }}.{{ $category }}_id"><option value="">Auswählen</option>@foreach($facts['training_options'] as $option)@if($option->category === $category)<option value="{{ $option->id }}">{{ $option->name }}</option>@endif@endforeach</select></label>
                        @endforeach
                        <label>Von<input type="time" wire:model.live="document.slots.{{ $i }}.starts_at"></label>
                        <label>Bis<input type="time" wire:model.live="document.slots.{{ $i }}.ends_at"></label>
                        <label>Halle<input maxlength="255" wire:model.live.debounce.1000ms="document.slots.{{ $i }}.hall"></label>
                        @foreach(['all_fields' => 'Alle Plätze', 'without_main' => 'Ohne Hauptplatz', 'synthetic_only' => 'Nur Kunstrasen', 'website_visible' => 'Auf Webseite sichtbar'] as $column => $caption)<label class="sp-check"><input type="checkbox" wire:model.live="document.slots.{{ $i }}.{{ $column }}">{{ $caption }}</label>@endforeach
                        <x-filament::icon-button wire:click="removeRow('slots', '{{ $slot['key'] }}')" wire:loading.attr="disabled" icon="heroicon-o-trash" color="danger" label="Trainingszeit entfernen" />
                    </div></div>@endforeach
                </section>
            @endif
            </fieldset>
            @if($step === 7)
                <section><div class="sp-toolbar"><h2>Abschlussprüfung</h2>@if($editable)<x-filament::button wire:click="checkPlan" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-path">Erneut prüfen</x-filament::button>@endif</div>
                    <dl class="sp-counts">@foreach(['teams' => 'Mannschaften', 'prices' => 'Preise', 'rules' => 'Beitragsregeln', 'assignments' => 'Zuordnungen', 'slots' => 'Trainingszeiten'] as $key => $title)<div><dt>{{ $title }}</dt><dd>{{ count($document[$key]) }}</dd></div>@endforeach</dl>
                    @if($review)
                        @if($review['errors'])<div class="sp-alert sp-error"><h3>Vor dem Abschluss korrigieren</h3><ul>@foreach($review['errors'] as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
                        @if($review['warnings'])<div class="sp-alert sp-warning"><h3>Hinweise zur Planung</h3><ul>@foreach($review['warnings'] as $message)<li>{{ $message }}</li>@endforeach</ul><label class="sp-check"><input type="checkbox" wire:model.live="acknowledge">Hinweise geprüft; Abweichungen sind beabsichtigt.</label></div>@endif
                        @if(!$review['errors'] && $canFinish)
                            <p class="sp-note">Der Abschluss erstellt alle geplanten Fachdaten gemeinsam. Vorlagensaison und bestehende Mitglieder bleiben unverändert. Es erfolgen weder Rechnungsversand noch Push-Nachrichten oder externe Synchronisationen.</p>
                            <x-filament::button x-on:click="finishConfirmation = !finishConfirmation" x-bind:aria-expanded="finishConfirmation" aria-controls="sp-finish-confirmation" wire:loading.attr="disabled" :disabled="count($review['warnings']) && !$acknowledge" icon="heroicon-o-check">Saison verbindlich erstellen</x-filament::button>
                            <div id="sp-finish-confirmation" x-show="finishConfirmation" x-cloak class="sp-alert" role="group" aria-labelledby="sp-finish-title">
                                <h3 id="sp-finish-title">Saisonabschluss bestätigen</h3>
                                <p>{{ $document['season']['name'] }} wird jetzt mit sämtlichen geplanten Verknüpfungen erstellt. Der Entwurf ist danach nicht mehr bearbeitbar.</p>
                                <div class="sp-toolbar">
                                    <x-filament::button x-on:click="finishConfirmation = false" color="gray">Planung weiter bearbeiten</x-filament::button>
                                    <x-filament::button wire:click="finish" x-on:click="finishConfirmation = false" wire:loading.attr="disabled" :disabled="count($review['warnings']) && !$acknowledge" icon="heroicon-o-check">Abschluss bestätigen und erstellen</x-filament::button>
                                </div>
                            </div>
                        @endif
                        @if($editable && !$canFinish)<p class="sp-note">Für den Abschluss fehlen Erstellungsrechte für mindestens einen Bereich oder das Recht zur Saisonübernahme.</p>@endif
                    @endif
                </section>
            @endif
            <div class="sp-toolbar sp-footer">
                @if($step > 1)<x-filament::button wire:click="go({{ $step - 1 }})" wire:loading.attr="disabled" color="gray" icon="heroicon-o-chevron-left">Zurück</x-filament::button>@endif
                @if($step < 7)<x-filament::button wire:click="go({{ $step + 1 }})" wire:loading.attr="disabled" icon="heroicon-o-chevron-right" icon-position="after">Weiter</x-filament::button>@endif
                @if($editable && \App\ClubUnio\Resources\ProfileUi::allows('seasons', 'deactivate'))<x-filament::button x-on:click="discardConfirmation = !discardConfirmation" x-bind:aria-expanded="discardConfirmation" aria-controls="sp-discard-confirmation" wire:loading.attr="disabled" color="danger" outlined icon="heroicon-o-archive-box">Verwerfen</x-filament::button>@endif
            </div>
            @if($editable && \App\ClubUnio\Resources\ProfileUi::allows('seasons', 'deactivate'))
                <section id="sp-discard-confirmation" x-show="discardConfirmation" x-cloak aria-labelledby="sp-discard-title">
                    <h2 id="sp-discard-title">Diesen Entwurf verwerfen?</h2>
                    <p>Die Planung bleibt zur Nachverfolgung erhalten. Es werden daraus keine Fachdaten erstellt.</p>
                    <div class="sp-toolbar">
                        <x-filament::button x-on:click="discardConfirmation = false" color="gray">Weiter bearbeiten</x-filament::button>
                        <x-filament::button wire:click="discard" x-on:click="discardConfirmation = false" wire:loading.attr="disabled" color="danger" icon="heroicon-o-archive-box">Entwurf verwerfen</x-filament::button>
                    </div>
                </section>
            @endif
        @else
            <p>Noch keine Planung geöffnet.</p>
        @endif
        <p wire:loading role="status">Planung wird verarbeitet …</p>
    </div>
</x-filament-panels::page>

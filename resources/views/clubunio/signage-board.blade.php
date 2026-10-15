@php
    $trainingGroups = collect([
        $trainings->filter(fn($t)=>substr($t->starts_at,0,5)<'19:00'),
        $trainings->filter(fn($t)=>substr($t->starts_at,0,5)>='19:00' && substr($t->starts_at,0,5)<'20:15'),
        $trainings->filter(fn($t)=>substr($t->starts_at,0,5)>='20:15'),
    ])->filter(fn($group)=>$group->isNotEmpty());
@endphp
<div class="sg-board {{ $competitions->isEmpty() ? 'sg-no-games':'' }} {{ $notices->isEmpty() ? 'sg-no-notices':'' }}" style="--sg-brand:{{ $brand['color'] }};--sg-ink:{{ $brand['ink'] }}" data-signage>
    <header class="sg-header">
        <div class="sg-brand"><img src="{{ $brand['logo'] }}" alt="{{ $brand['title'] }}" width="48" height="48"><h1>{{ $brand['title'] }} <span>– Info-Display</span></h1></div>
        <div class="sg-meta"><span class="sg-test">TEST</span><time class="sg-clock">{{ now('Europe/Zurich')->format('H:i:s') }}</time><span class="sg-date">{{ now('Europe/Zurich')->format('d.m.Y') }}</span>
        @if($publicDisplay)<details class="sg-settings"><summary aria-label="Display-Einstellungen" title="Display-Einstellungen">@svg('heroicon-o-cog-6-tooth')</summary><form method="get"><label>Trainingsperiode<select name="period_id"><option value="">Bitte wählen</option>@foreach($periods as $period)<option value="{{ $period->id }}" @selected((string)$periodId===(string)$period->id)>{{ $period->name }}</option>@endforeach</select></label><button type="submit" aria-label="Übernehmen" title="Übernehmen">@svg('heroicon-o-check')</button></form></details>@endif
        </div>
    </header>
    <div class="sg-layout"><div class="sg-main">
        @if($competitions->isNotEmpty())
        <section class="sg-panel sg-games"><header><h2>@svg('heroicon-o-trophy') Spielplan heute</h2><span class="sg-page-count"></span><small>aktualisiert {{ now('Europe/Zurich')->format('H:i') }}</small></header>
        <div class="sg-panel-body sg-pageable"><table class="sg-game-table"><colgroup><col class="sg-time-col"><col class="sg-club-col"><col class="sg-room-col"><col class="sg-club-col"><col class="sg-room-col"><col class="sg-field-col"><col></colgroup><thead><tr><th>Zeit</th><th>Gastgeber</th><th>Garderobe</th><th>Gast</th><th>Garderobe</th><th>Spielfeld</th><th>Bemerkungen</th></tr></thead><tbody>
        @foreach($competitions as $game)<tr><td class="sg-time">{{ substr($game->starts_at??'',0,5)?:'–' }}</td><td><span class="sg-club"><x-clubunio.club-logo :url="$clubs->get($game->host_club_number)?->logo_url"/><span>{{ $game->host_name }} {{ $game->host_category }}</span></span></td><td>{{ \App\ClubUnio\Services\SignageService::roomLabel($game->hostRoom?->name) }} {{ $game->hostSide?->name }}</td><td><span class="sg-club"><x-clubunio.club-logo :url="$clubs->get($game->guest_club_number)?->logo_url"/><span>{{ $game->guest_name }} {{ $game->guest_category }}</span></span></td><td>{{ \App\ClubUnio\Services\SignageService::roomLabel($game->guestRoom?->name) }} {{ $game->guestSide?->name }}</td><td>{{ $game->field?->name }}</td><td>{{ $game->remarks }}</td></tr>@endforeach
        </tbody></table></div></section>
        @endif
        <section class="sg-panel sg-training"><header><h2>@svg('heroicon-o-user-group') Trainingsplan heute</h2><span class="sg-page-count"></span><small>aktualisiert {{ now('Europe/Zurich')->format('H:i') }}</small></header>
            <div class="sg-panel-body sg-training-columns sg-training-columns-{{ $trainingGroups->count() }}" style="--sg-training-columns:{{ max(1,$trainingGroups->count()) }}">
            @forelse($trainingGroups as $group)<div class="sg-pageable"><table><colgroup><col style="width:30%"><col style="width:30%"><col style="width:16%"><col style="width:24%"></colgroup><thead><tr><th>Zeit</th><th>Mannschaft</th><th title="Garderobe">Gard.</th><th>Feld</th></tr></thead><tbody>
                @foreach($group as $training)<tr><td class="sg-time"><span>{{ substr($training->starts_at,0,5) }}</span> – <span>{{ substr($training->ends_at,0,5) }}</span></td><td>{{ $training->teamSeason?->team?->name ?: $training->teamSeason?->label }}</td><td>{{ \App\ClubUnio\Services\SignageService::roomLabel($training->room?->name) }} {{ $training->side?->name }}</td><td>{{ $training->hall ?: $training->field?->name }}</td></tr>@endforeach
            </tbody></table></div>@empty<p class="sg-empty">{{ $periodId?'Heute keine Trainings für diese Auswahl.':'Bitte Trainingsperiode wählen.' }}</p>@endforelse
            </div>
        </section>
        <div class="sg-bottom">
            @if($notices->isNotEmpty())<section class="sg-panel sg-notices"><header><h2>@svg('heroicon-o-megaphone') Meldungen</h2></header><div class="sg-panel-body sg-scroll">@foreach($notices as $notice)<article><h3>{{ $notice->title }}</h3><p>{{ $notice->body }}</p></article>@endforeach</div></section>@endif
            <section class="sg-panel sg-fields"><header><h2>@svg('heroicon-o-map-pin') Anlagenstatus</h2></header><div class="sg-panel-body sg-scroll">@forelse($fields as $field)<div class="sg-field"><span>{{ $field->field?->name }}</span><span class="sg-status {{ !$field->status?->active ? 'sg-unknown' : ($field->status->blocks_training ? 'sg-closed':'sg-open') }}">@svg($field->status?->active && !$field->status->blocks_training ? 'heroicon-o-lock-open':'heroicon-o-lock-closed') {{ $field->status?->active ? $field->status->name:'Unbekannt' }}</span>@if($field->note)<small>{{ $field->note }}</small>@endif</div>@empty<p class="sg-empty">Kein Anlagenstatus erfasst.</p>@endforelse</div></section>
            <livewire:clubunio.signage-feeds :tenant-id="$tenant->id" :public-display="$publicDisplay" :kiosk="true" feed="weather" :key="'weather-'.$tenant->id"/>
        </div>
    </div>
    <livewire:clubunio.signage-feeds :tenant-id="$tenant->id" :public-display="$publicDisplay" :kiosk="true" feed="instagram" :key="'instagram-'.$tenant->id"/>
    </div>
</div>

@php
    $trainingGroups = collect([
        $trainings->filter(fn($t)=>substr($t->starts_at,0,5)<'19:00'),
        $trainings->filter(fn($t)=>substr($t->starts_at,0,5)>='19:00' && substr($t->starts_at,0,5)<'20:15'),
        $trainings->filter(fn($t)=>substr($t->starts_at,0,5)>='20:15'),
    ])->filter(fn($group)=>$group->isNotEmpty());
@endphp
<div class="sg-board {{ $competitions->isEmpty() ? 'sg-no-games':'' }} {{ $notices->isEmpty() ? 'sg-no-notices':'' }}" style="--sg-brand:{{ $brand['color'] }};--sg-ink:{{ $brand['ink'] }}" data-signage data-locale="{{ str_replace('_', '-', app()->getLocale()) }}">
    <header class="sg-header">
        <div class="sg-brand"><img src="{{ $brand['logo'] }}" alt="{{ $brand['title'] }}" width="48" height="48"><h1>{{ $brand['title'] }} <span>{{ __('– Info-Display') }}</span></h1></div>
        <div class="sg-meta"><span class="sg-test">{{ __('TEST') }}</span><time class="sg-clock">{{ now('Europe/Zurich')->format('H:i:s') }}</time><span class="sg-date">{{ now('Europe/Zurich')->format('d.m.Y') }}</span>
        </div>
    </header>
    <div class="sg-layout"><div class="sg-main">
        @if($competitions->isNotEmpty())
        <section class="sg-panel sg-games"><header><h2>@svg('heroicon-o-trophy') {{ __('Spielplan heute') }}</h2><span class="sg-page-count"></span><small>{{ __('aktualisiert') }} {{ now('Europe/Zurich')->format('H:i') }}</small></header>
        <div class="sg-panel-body sg-pageable"><table class="sg-game-table"><colgroup><col class="sg-time-col"><col class="sg-club-col"><col class="sg-room-col"><col class="sg-club-col"><col class="sg-room-col"><col class="sg-field-col"><col></colgroup><thead><tr><th>{{ __('Zeit') }}</th><th>{{ __('Heimmannschaft') }}</th><th>{{ __('Garderobe') }}</th><th>{{ __('Gastmannschaft') }}</th><th>{{ __('Garderobe') }}</th><th>{{ __('Spielfeld') }}</th><th>{{ __('Bemerkungen') }}</th></tr></thead><tbody>
        @foreach($competitions as $game)<tr><td class="sg-time">{{ substr($game->starts_at??'',0,5)?:'–' }}</td><td><span class="sg-club"><x-clubunio.club-logo :url="$clubs->get($game->host_club_number)?->logo_url"/><span>{{ $game->host_name }} {{ $game->host_category }}</span></span></td><td>{{ (\App\ClubUnio\Services\SignageService::roomLabel($game->hostRoom?->name) === '–' ? '–' : app(\App\ClubUnio\Services\LookupTranslations::class)->label('training_options', ($game->hostRoom ?? []), tenant: $tenant->id ?? null)) }} {{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('training_options', ($game->hostSide ?? []), tenant: $tenant->id ?? null) }}</td><td><span class="sg-club"><x-clubunio.club-logo :url="$clubs->get($game->guest_club_number)?->logo_url"/><span>{{ $game->guest_name }} {{ $game->guest_category }}</span></span></td><td>{{ (\App\ClubUnio\Services\SignageService::roomLabel($game->guestRoom?->name) === '–' ? '–' : app(\App\ClubUnio\Services\LookupTranslations::class)->label('training_options', ($game->guestRoom ?? []), tenant: $tenant->id ?? null)) }} {{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('training_options', ($game->guestSide ?? []), tenant: $tenant->id ?? null) }}</td><td>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('training_options', ($game->field ?? []), tenant: $tenant->id ?? null) }}</td><td>{{ $game->remarks }}</td></tr>@endforeach
        </tbody></table></div></section>
        @endif
        <section class="sg-panel sg-training"><header><h2>@svg('heroicon-o-user-group') {{ __('Trainingsplan heute') }}</h2><span class="sg-page-count"></span><small>{{ __('aktualisiert') }} {{ now('Europe/Zurich')->format('H:i') }}</small></header>
            <div class="sg-panel-body sg-training-columns sg-training-columns-{{ $trainingGroups->count() }}" style="--sg-training-columns:{{ max(1,$trainingGroups->count()) }}">
            @forelse($trainingGroups as $group)<div class="sg-pageable"><table><colgroup><col style="width:30%"><col style="width:30%"><col style="width:16%"><col style="width:24%"></colgroup><thead><tr><th>{{ __('Zeit') }}</th><th>{{ __('Mannschaft') }}</th><th title="{{ __('Garderobe') }}">{{ __('Gard.') }}</th><th>{{ __('Feld') }}</th></tr></thead><tbody>
                @foreach($group as $training)<tr><td class="sg-time"><span>{{ substr($training->starts_at,0,5) }}</span> – <span>{{ substr($training->ends_at,0,5) }}</span></td><td>{{ $training->teamSeason?->team?->name ?: $training->teamSeason?->label }}</td><td>{{ (\App\ClubUnio\Services\SignageService::roomLabel($training->room?->name) === '–' ? '–' : app(\App\ClubUnio\Services\LookupTranslations::class)->label('training_options', ($training->room ?? []), tenant: $tenant->id ?? null)) }} {{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('training_options', ($training->side ?? []), tenant: $tenant->id ?? null) }}</td><td>{{ $training->hall ?: app(\App\ClubUnio\Services\LookupTranslations::class)->label('training_options', ($training->field ?? []), tenant: $tenant->id ?? null) }}</td></tr>@endforeach
            </tbody></table></div>@empty<p class="sg-empty">{{ $periodId?__('Heute keine Trainings für diese Auswahl.'):__('Bitte Trainingsperiode wählen.') }}</p>@endforelse
            </div>
        </section>
        <div class="sg-bottom">
            @if($notices->isNotEmpty())<section class="sg-panel sg-notices"><header><h2>@svg('heroicon-o-megaphone') {{ __('Meldungen') }}</h2></header><div class="sg-panel-body sg-scroll">@foreach($notices as $notice)<article><h3>{{ $notice->title }}</h3><p>{{ $notice->body }}</p></article>@endforeach</div></section>@endif
            <section class="sg-panel sg-fields"><header><h2>@svg('heroicon-o-map-pin') {{ __('Anlagenstatus') }}</h2></header><div class="sg-panel-body sg-scroll">@forelse($fields as $field)<div class="sg-field"><span>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('training_options', ($field->field ?? []), tenant: $tenant->id ?? null) }}</span><span class="sg-status {{ !$field->status?->active ? 'sg-unknown' : ($field->status->blocks_training ? 'sg-closed':'sg-open') }}">@svg($field->status?->active && !$field->status->blocks_training ? 'heroicon-o-lock-open':'heroicon-o-lock-closed') {{ $field->status?->active ? app(\App\ClubUnio\Services\LookupTranslations::class)->label('facility_statuses', $field->status, tenant: $tenant->id ?? null) : __('Unbekannt') }}</span>@if($field->note)<small>{{ $field->note }}</small>@endif</div>@empty<p class="sg-empty">{{ __('Kein Anlagenstatus erfasst.') }}</p>@endforelse</div></section>
            <livewire:clubunio.signage-feeds :tenant-id="$tenant->id" :public-display="$publicDisplay" :kiosk="true" feed="weather" :key="'weather-'.$tenant->id"/>
        </div>
    </div>
    <livewire:clubunio.signage-feeds :tenant-id="$tenant->id" :public-display="$publicDisplay" :kiosk="true" feed="instagram" :key="'instagram-'.$tenant->id"/>
    </div>
</div>

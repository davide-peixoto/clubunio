@extends('clubunio.portal.layout')
@section('content')
<a href="{{ route('clubunio.portal.registrations', ['tenant'=>$tenant->slug]) }}">{{ __('Anmeldungen') }}</a>
<h1>{{ $r->first_name }} {{ $r->last_name }}</h1>
<p>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('registration_statuses', ($r->status ?? []), tenant: $tenant->id ?? null) }} · {{ $r->teamSeason?->label }}</p>
@if(session('saved'))<p role="status">{{ __('Änderung gespeichert.') }}</p>@endif
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<dl class="registration-details">
@foreach(['birth_date'=>__('Geburtsdatum'),'street'=>__('Strasse'),'house_number'=>__('Hausnummer'),'postal_code'=>__('PLZ'),'city'=>__('Ort'),'email'=>__('E-Mail'),'mobile'=>__('Mobile'),'mother_name'=>__('Mutter'),'mother_email'=>__('E-Mail Mutter'),'father_name'=>__('Vater'),'father_email'=>__('E-Mail Vater'),'previous_club'=>__('Vorverein'),'notes'=>__('Bemerkung')] as $key=>$label)
<div><dt>{{ $label }}</dt><dd>{{ $values[$key] ?? '–' }}</dd></div>
@endforeach
</dl>
@if($r->status?->workflow_key !== 'accepted')
<form method="post" action="{{ route('clubunio.portal.registration.update', ['tenant'=>$tenant->slug, 'registration'=>$r->id]) }}" class="registration-form">
@csrf
<input type="hidden" name="revision" value="{{ old('revision', $r->revision) }}">
<input type="hidden" name="member_version" value="{{ old('member_version', $r->member ? \App\ClubUnio\Services\MemberService::version($r->member) : '') }}">
<label>{{ __('Status') }}<select name="status_id" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($statuses as $status)<option value="{{ $status->id }}" @selected((string)old('status_id', $r->status_id)===(string)$status->id)>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('registration_statuses', ['id' => $status->id, 'name' => $status->name, 'tenant_id' => $status->tenant_id ?? $tenant->id], tenant: $tenant->id ?? null) }}</option>@endforeach</select></label>
<label>{{ __('Mannschaft / Saison') }}<select name="team_season_id"><option value="">{{ __('Nicht zugeordnet') }}</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected((string)old('team_season_id', $r->team_season_id)===(string)$team->id)>{{ $team->label }}</option>@endforeach</select></label>
<label>{{ __('Mitgliederart') }}<select name="member_type_id"><option value="">{{ __('Nicht gewählt') }}</option>@foreach($types as $type)<option value="{{ $type->id }}" @selected((string)old('member_type_id', $r->member?->type_id)===(string)$type->id)>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('member_types', $type, tenant: $tenant->id ?? null) }}</option>@endforeach</select></label>
<label>{{ __('Dokumententyp Ausweise') }}<select name="document_type_id"><option value="">{{ __('Nicht gewählt') }}</option>@foreach($documentTypes as $type)<option value="{{ $type->id }}" @selected((string)old('document_type_id')===(string)$type->id)>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('document_types', $type, tenant: $tenant->id ?? null) }}</option>@endforeach</select></label>
<label class="full-width">{{ __('Prüfvermerk') }}<textarea name="reason" required minlength="10" maxlength="500">{{ old('reason') }}</textarea></label>
<button type="submit">{{ __('Speichern') }}</button>
</form>
@endif
<h2>{{ __('Dokumente') }}</h2>
@forelse($documents as $document)
<p><a target="_blank" rel="noopener" href="{{ route('clubunio.portal.registration.document', ['tenant'=>$tenant->slug, 'document'=>$document->id]) }}">{{ $document->kind === 'portrait' ? __('Passfoto') : __('Ausweis') }}</a></p>
@empty<p>{{ __('Keine Dokumente vorhanden.') }}</p>@endforelse
@endsection

@extends('clubunio.portal.layout')
@section('content')
<a href="{{ route('clubunio.portal.registrations', ['tenant'=>$tenant->slug]) }}">Anmeldungen</a>
<h1>{{ $r->first_name }} {{ $r->last_name }}</h1>
<p>{{ $r->status?->name }} · {{ $r->teamSeason?->label }}</p>
@if(session('saved'))<p role="status">Änderung gespeichert.</p>@endif
@if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<dl class="registration-details">
@foreach(['birth_date'=>'Geburtsdatum','street'=>'Strasse','house_number'=>'Hausnummer','postal_code'=>'PLZ','city'=>'Ort','email'=>'E-Mail','mobile'=>'Mobile','mother_name'=>'Mutter','mother_email'=>'E-Mail Mutter','father_name'=>'Vater','father_email'=>'E-Mail Vater','previous_club'=>'Vorverein','notes'=>'Bemerkung'] as $key=>$label)
<div><dt>{{ $label }}</dt><dd>{{ $values[$key] ?? '–' }}</dd></div>
@endforeach
</dl>
@if($r->status?->workflow_key !== 'accepted')
<form method="post" action="{{ route('clubunio.portal.registration.update', ['tenant'=>$tenant->slug, 'registration'=>$r->id]) }}" class="registration-form">
@csrf
<input type="hidden" name="revision" value="{{ old('revision', $r->revision) }}">
<input type="hidden" name="member_version" value="{{ old('member_version', $r->member ? \App\ClubUnio\Services\MemberService::version($r->member) : '') }}">
<label>Status<select name="status_id" required><option value="">Bitte wählen</option>@foreach($statuses as $status)<option value="{{ $status->id }}" @selected((string)old('status_id', $r->status_id)===(string)$status->id)>{{ $status->name }}</option>@endforeach</select></label>
<label>Mannschaft / Saison<select name="team_season_id"><option value="">Nicht zugeordnet</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected((string)old('team_season_id', $r->team_season_id)===(string)$team->id)>{{ $team->label }}</option>@endforeach</select></label>
<label>Mitgliederart<select name="member_type_id"><option value="">Nicht gewählt</option>@foreach($types as $type)<option value="{{ $type->id }}" @selected((string)old('member_type_id', $r->member?->type_id)===(string)$type->id)>{{ $type->name }}</option>@endforeach</select></label>
<label>Dokumententyp Ausweise<select name="document_type_id"><option value="">Nicht gewählt</option>@foreach($documentTypes as $type)<option value="{{ $type->id }}" @selected((string)old('document_type_id')===(string)$type->id)>{{ $type->name }}</option>@endforeach</select></label>
<label class="full-width">Prüfvermerk<textarea name="reason" required minlength="10" maxlength="500">{{ old('reason') }}</textarea></label>
<button type="submit">Speichern</button>
</form>
@endif
<h2>Dokumente</h2>
@forelse($documents as $document)
<p><a target="_blank" rel="noopener" href="{{ route('clubunio.portal.registration.document', ['tenant'=>$tenant->slug, 'document'=>$document->id]) }}">{{ $document->kind === 'portrait' ? 'Passfoto' : 'Ausweis' }}</a></p>
@empty<p>Keine Dokumente vorhanden.</p>@endforelse
@endsection

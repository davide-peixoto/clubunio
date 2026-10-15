@extends('clubunio.public.layout')
@section('title', 'Mitgliederanmeldung')
@section('content')
<h1>Mitgliederanmeldung</h1>
<p class="notice">Testumgebung: Diese Anmeldung wird nur in ClubUnio erfasst und ersetzt noch keine Anmeldung beim Verein.</p>
@if(session('submitted'))
    <section role="status"><h2>Anmeldung gespeichert</h2><p>Vielen Dank. Die Testanmeldung wurde zur Prüfung erfasst. Damit ist noch keine Mitgliedschaft bestätigt.</p>
        @if(session('uploads_pending'))<p>Die Anhänge sind geschützt gespeichert. Ihre Übertragung wird intern geprüft; bitte nicht erneut anmelden.</p>@endif
        <a href="{{ route('clubunio.public.registration', ['tenant'=>$tenant->slug, 'new'=>1]) }}">Weitere Anmeldung erfassen</a></section>
@else
    @if($errors->any())<div role="alert" class="errors"><h2>Bitte Eingaben prüfen</h2><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul><p>Dateien bei Bedarf erneut auswählen.</p></div>@endif
    <form method="post" enctype="multipart/form-data" action="{{ route('clubunio.public.registration.submit', $tenant->slug) }}">
    @csrf
    <input type="hidden" name="request_id" value="{{ $form['id'] }}">
    <div hidden aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <fieldset><legend>Persönliche Angaben</legend><div class="form-grid">
    @foreach(['first_name'=>'Vorname','last_name'=>'Nachname','email'=>'E-Mail','birth_date'=>'Geburtsdatum','birth_place'=>'Geburtsort','mobile'=>'Mobile','phone_private'=>'Telefon'] as $key=>$label)
        <label>{{ $label }}@if(in_array($key,['first_name','last_name','email','birth_date'])) * @endif<input name="{{ $key }}" type="{{ $key==='birth_date'?'date':($key==='email'?'email':'text') }}" value="{{ old($key) }}" @required(in_array($key,['first_name','last_name','email','birth_date'])) maxlength="{{ in_array($key,['mobile','phone_private'])?50:255 }}" @if($key==='birth_date') max="{{ now('Europe/Zurich')->toDateString() }}" @endif></label>
    @endforeach
    @foreach(['gender_id'=>'Geschlecht','nationality_id'=>'Nationalität'] as $key=>$label)
        <label>{{ $label }} *<select name="{{ $key }}" required><option value="">Bitte wählen</option>@foreach($choices[$key] as $id=>$name)<option value="{{ $id }}" @selected((string)old($key)===(string)$id)>{{ $name }}</option>@endforeach</select></label>
    @endforeach
    </div></fieldset>
    <fieldset><legend>Adresse</legend><div class="form-grid">
    @foreach(['street'=>'Strasse','house_number'=>'Hausnummer','postal_code'=>'PLZ','city'=>'Ort'] as $key=>$label)
        <label>{{ $label }} @if($key!=='house_number') * @endif<input name="{{ $key }}" value="{{ old($key) }}" @required($key!=='house_number') maxlength="{{ in_array($key,['postal_code','house_number'])?20:255 }}"></label>
    @endforeach
        <label>Land *<select name="country_id" required><option value="">Bitte wählen</option>@foreach($choices['country_id'] as $id=>$name)<option value="{{ $id }}" @selected((string)old('country_id')===(string)$id)>{{ $name }}</option>@endforeach</select></label>
    </div></fieldset>
    <fieldset><legend>Eltern und Rechnungsempfänger</legend><div class="form-grid">
    @foreach(['mother_name'=>'Name Mutter','mother_email'=>'E-Mail Mutter','mother_mobile'=>'Mobile Mutter','father_name'=>'Name Vater','father_email'=>'E-Mail Vater','father_mobile'=>'Mobile Vater'] as $key=>$label)
        <label>{{ $label }}<input name="{{ $key }}" type="{{ str_contains($key,'email')?'email':'text' }}" value="{{ old($key) }}" maxlength="{{ str_contains($key,'mobile')?50:254 }}"></label>
    @endforeach
        <label>Rechnungsempfänger *<select name="billing_recipient_id" required><option value="">Bitte wählen</option>@foreach($choices['billing_recipient_id'] as $id=>$name)<option value="{{ $id }}" @selected((string)old('billing_recipient_id')===(string)$id)>{{ $name }}</option>@endforeach</select></label>
    </div></fieldset>
    <fieldset><legend>Vorverein</legend><div class="form-grid">
        <input type="hidden" name="has_previous_club" value="Nein">
        <label class="consent"><input type="checkbox" name="has_previous_club" value="Ja" @checked(old('has_previous_club')==='Ja')>Bereits in einem Verein gespielt</label>
        <label>Vorverein<input name="previous_club" value="{{ old('previous_club') }}" maxlength="255"></label>
    </div></fieldset>
    <fieldset><legend>Dokumente</legend><div class="form-grid">
        <label>Passfoto (JPG, PNG, WebP; maximal 5 MB)<input type="file" name="portrait" accept="image/jpeg,image/png,image/webp"></label>
        <label>Ausweise (maximal 3 Dateien, je 10 MB)<input type="file" name="identity[]" multiple accept="application/pdf,image/jpeg,image/png,image/webp"></label>
    </div><p>Die Dokumente werden privat gespeichert und sind nicht öffentlich abrufbar.</p></fieldset>
    <label>Bemerkungen<textarea name="notes" maxlength="10000" rows="4">{{ old('notes') }}</textarea></label>
    <label class="consent"><input type="checkbox" name="consent" value="1" required @checked(old('consent'))>Ich bin mit der Speicherung meiner Angaben und Dokumente für diese Testanmeldung einverstanden.</label>
    <button type="submit">Testanmeldung absenden</button>
    </form>
@endif
@endsection

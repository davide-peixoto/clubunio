@extends('clubunio.public.layout')
@section('title', __('Mitgliederanmeldung'))
@section('content')
<h1>{{ __('Mitgliederanmeldung') }}</h1>
<p class="notice">{{ __('Testumgebung: Diese Anmeldung wird nur in ClubUnio erfasst und ersetzt noch keine Anmeldung beim Verein.') }}</p>
@if(session('submitted'))
    <section role="status"><h2>{{ trans('public-registration.received_title') }}</h2><p>{{ trans('public-registration.received_body') }}</p>
        @if(session('uploads_pending'))<p>{{ __('Die Anhänge sind geschützt gespeichert. Ihre Übertragung wird intern geprüft; bitte nicht erneut anmelden.') }}</p>@endif
        <a href="{{ route('clubunio.public.registration', ['tenant'=>$tenant->slug, 'new'=>1]) }}">{{ __('Weitere Anmeldung erfassen') }}</a></section>
@else
    @if($errors->any())<div role="alert" class="errors"><h2>{{ __('Bitte Eingaben prüfen') }}</h2><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul><p>{{ __('Dateien bei Bedarf erneut auswählen.') }}</p></div>@endif
    <form method="post" enctype="multipart/form-data" action="{{ route('clubunio.public.registration.submit', $tenant->slug) }}">
    @csrf
    <input type="hidden" name="request_id" value="{{ $form['id'] }}">
    <div hidden aria-hidden="true"><label>{{ __('Website') }}<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <fieldset><legend>{{ __('Persönliche Angaben') }}</legend><div class="form-grid">
    @foreach(['first_name'=>__('Vorname'),'last_name'=>__('Nachname'),'email'=>__('E-Mail'),'birth_date'=>__('Geburtsdatum'),'birth_place'=>__('Geburtsort'),'mobile'=>__('Mobile'),'phone_private'=>__('Telefon')] as $key=>$label)
        <label>{{ $label }}@if(in_array($key,['first_name','last_name','birth_date','birth_place','mobile'])) * @endif<input name="{{ $key }}" type="{{ $key==='birth_date'?'date':($key==='email'?'email':'text') }}" value="{{ old($key) }}" @required(in_array($key,['first_name','last_name','birth_date','birth_place','mobile'])) maxlength="{{ in_array($key,['mobile','phone_private'])?50:255 }}" @if($key==='birth_date') max="{{ now('Europe/Zurich')->toDateString() }}" @endif></label>
    @endforeach
        <label>{{ __('AHV-Nummer') }} *<input name="ahv" inputmode="numeric" required pattern="756([. ]?[0-9]){10}" maxlength="23" autocomplete="off"></label>
    @foreach(['gender_id'=>__('Geschlecht'),'nationality_id'=>__('Nationalität')] as $key=>$label)
        <label>{{ $label }} *<select name="{{ $key }}" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($choices[$key] as $id=>$name)<option value="{{ $id }}" @selected((string)old($key)===(string)$id)>{{ $name }}</option>@endforeach</select></label>
    @endforeach
    </div></fieldset>
    <fieldset><legend>{{ __('Adresse') }}</legend><div class="form-grid">
    @foreach(['street'=>__('Strasse'),'house_number'=>__('Hausnummer'),'postal_code'=>__('PLZ'),'city'=>__('Ort')] as $key=>$label)
        <label>{{ $label }} *<input name="{{ $key }}" value="{{ old($key) }}" required maxlength="{{ in_array($key,['postal_code','house_number'])?20:255 }}"></label>
    @endforeach
        <label>{{ __('Land *') }}<select name="country_id" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($choices['country_id'] as $id=>$name)<option value="{{ $id }}" @selected((string)old('country_id')===(string)$id)>{{ $name }}</option>@endforeach</select></label>
    </div></fieldset>
    <fieldset><legend>{{ __('Eltern und Rechnungsempfänger') }}</legend><div class="form-grid">
    @foreach(['mother_name'=>__('Name Mutter'),'mother_email'=>__('E-Mail Mutter'),'mother_mobile'=>__('Mobile Mutter'),'father_name'=>__('Name Vater'),'father_email'=>__('E-Mail Vater'),'father_mobile'=>__('Mobile Vater')] as $key=>$label)
        <label>{{ $label }}<input name="{{ $key }}" type="{{ str_contains($key,'email')?'email':'text' }}" value="{{ old($key) }}" maxlength="{{ str_contains($key,'mobile')?50:254 }}"></label>
    @endforeach
        <label>{{ __('Rechnungsempfänger *') }}<select name="billing_recipient_id" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($choices['billing_recipient_id'] as $id=>$name)<option data-email-field="{{ $billingEmailFields[$id] ?? '' }}" value="{{ $id }}" @selected((string)old('billing_recipient_id')===(string)$id)>{{ $name }}</option>@endforeach</select></label>
    </div></fieldset>
    <fieldset><legend>{{ __('Vorverein') }}</legend><div class="form-grid">
        <label>{{ __('Bereits in einem Verein gespielt') }} *<select name="has_previous_club" required><option value="">{{ __('Bitte wählen') }}</option><option value="J" @selected(old('has_previous_club')==='J')>{{ __('Ja') }}</option><option value="N" @selected(old('has_previous_club')==='N')>{{ __('Nein') }}</option></select></label>
        <label data-previous-club>{{ __('Vorverein') }}<input name="previous_club" value="{{ old('previous_club') }}" maxlength="255"></label>
        <label data-previous-club>{{ __('Land des Vorvereins') }}<select name="previous_club_country_type"><option value="">{{ __('Bitte wählen') }}</option><option value="I" @selected(old('previous_club_country_type')==='I')>{{ __('Schweiz') }}</option><option value="A" @selected(old('previous_club_country_type')==='A')>{{ __('Ausland') }}</option></select></label>
    </div></fieldset>
    <fieldset><legend>{{ __('Dokumente') }}</legend><div class="form-grid">
        <label>{{ __('Passfoto (JPG, PNG, WebP; maximal 5 MB)') }}<input type="file" name="portrait" required accept="image/jpeg,image/png,image/webp"></label>
        <label>{{ __('Ausweise (maximal 3 Dateien, je 10 MB)') }}<input type="file" name="identity[]" required multiple accept="application/pdf,image/jpeg,image/png,image/webp"></label>
    </div><p>{{ __('Die Dokumente werden privat gespeichert und sind nicht öffentlich abrufbar.') }}</p></fieldset>
    <label>{{ __('Bemerkungen') }}<textarea name="notes" maxlength="10000" rows="4">{{ old('notes') }}</textarea></label>
    <label class="consent"><input type="checkbox" name="consent" value="1" required @checked(old('consent'))>{{ trans('public-registration.consent') }}</label>
    <button type="submit">{{ trans('public-registration.submit') }}</button>
    </form>
    <script src="{{ asset('js/clubunio-registration.js') }}" defer></script>
@endif
@endsection

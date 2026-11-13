<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"><style>
    @page { margin: 24mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 11pt; }
    .letter + .letter { page-break-before: always; }
    address { font-style: normal; margin: 25mm 0 14mm; }
    h1 { font-size: 17pt; }
    .url { word-wrap: break-word; font-size: 8pt; }
</style></head>
<body>
@foreach($letters as $letter)
    <section class="letter">
        <strong>{{ $tenant->name }}</strong>
        <address>{{ $letter['member']->first_name }} {{ $letter['member']->last_name }}<br>
            {{ $letter['member']->street }} {{ $letter['member']->house_number }}<br>
            {{ $letter['member']->postal_code }} {{ $letter['member']->city }}</address>
        <h1>{{ __('campaign.update') }}</h1>
        <p>{{ __('Bitte aktualisiere deine Kontaktdaten für :club. Die Geschäftsstelle prüft deine Änderungen.', ['club' => $tenant->name]) }}</p>
        <img src="{{ $letter['qr'] }}" width="150" height="150" alt="{{ __('campaign.qr') }}">
        <p class="url">{{ $letter['url'] }}</p>
        @include('clubunio.brand.attribution')
    </section>
@endforeach
</body></html>

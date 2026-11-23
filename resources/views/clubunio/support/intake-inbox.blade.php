<!doctype html>
<html lang="{{ app()->getLocale() }}">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ trans('support-intake.title') }} · ClubUnio</title>
<link rel="stylesheet" href="{{ asset('css/clubunio-portal.css') }}">
<link rel="stylesheet" href="{{ asset('css/clubunio-support.css') }}">
<main class="cu-support">
    <h1>{{ trans('support.workspace.sign_in_requests') }}</h1>
    @forelse($rows as $row)
        @php($entry = json_decode(decrypt($row->payload), true, flags: JSON_THROW_ON_ERROR))
        <article><h2>{{ $entry['email'] }}</h2><time>{{ $row->created_at }}</time><p>{{ $entry['body'] }}</p></article>
    @empty
        <p>{{ trans('support.workspace.no_sign_in_requests') }}</p>
    @endforelse
</main>
</html>

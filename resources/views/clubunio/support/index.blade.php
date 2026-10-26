<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Benutzersimulation · ClubUnio</title>
<style>body{font:16px system-ui;margin:0;color:#243141;background:#f7f9fc}header,main{max-width:1100px;margin:auto;padding:24px}a{color:#164b8a}section{border-top:1px solid #ccd5df;padding:24px 0}form{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;align-items:end}label{display:grid;gap:6px}input,select,textarea,button{font:inherit;padding:10px;max-width:100%;box-sizing:border-box;border:1px solid #9aaabd;border-radius:4px}button{background:#164b8a;color:white;cursor:pointer}table{border-collapse:collapse;width:100%}td,th{padding:10px;text-align:left;border-bottom:1px solid #ddd}.scroll{overflow:auto}h1{font-size:26px}h2{font-size:20px}</style></head>
<body><header><a href="/">ClubUnio</a> / {{ $tenant->name }}<h1>Benutzersimulation</h1></header><main>
@if($errors->any())<div role="alert">{{ $errors->first() }}</div>@endif
<section><h2>Simulation starten</h2>
@if($allowed)
<form method="post" action="{{ route('clubunio.support.start') }}">@csrf
<label>Benutzer<select name="target_id" required><option value="">Auswählen</option>@foreach($accounts as $link)@if($link->role !== 'admin' && $link->account->active && !$link->account->api_only)<option value="{{ $link->account_id }}">{{ $link->account->display_name }} · {{ $link->account->username }}</option>@endif @endforeach</select></label>
<label>Supportgrund<textarea name="reason" required minlength="10" maxlength="500"></textarea></label>
<label>Eigenes Passwort<input type="password" name="password" required autocomplete="current-password"></label>
<button type="submit">Nur-Lesen-Simulation starten</button></form>
@else<p>Für dein Konto ist die Benutzersimulation nicht freigeschaltet.</p>@endif
</section><section><h2>Persönliche Freischaltungen</h2>
@foreach($accounts as $link)@if($link->role === 'admin')
@php($grant = $grants->get($link->account_id))
<details><summary>{{ $link->account->display_name }} · {{ $grant?->enabled ? 'Freigeschaltet' : 'Gesperrt' }}</summary>
<form method="post" action="{{ route('clubunio.support.grant') }}">@csrf
<input type="hidden" name="target_id" value="{{ $link->account_id }}"><input type="hidden" name="revision" value="{{ $grant?->revision ?? 0 }}">
<label>Freischaltung<select name="enabled"><option value="0" @selected(!$grant?->enabled)>Gesperrt</option><option value="1" @selected($grant?->enabled)>Erlaubt</option></select></label>
<label>Begründung<textarea name="reason" required minlength="10" maxlength="500"></textarea></label><label>Eigenes Passwort<input type="password" name="password" required autocomplete="current-password"></label><button type="submit">Freischaltung speichern</button></form></details>
@endif @endforeach
</section><section><h2>Supportprotokoll</h2><div class="scroll"><table><thead><tr><th>Zeit</th><th>Admin-ID</th><th>Benutzer-ID</th><th>Ereignis</th><th>Details</th></tr></thead><tbody>
@foreach($events as $event)<tr><td>{{ \Carbon\CarbonImmutable::parse($event->created_at)->timezone('Europe/Zurich')->format('d.m.Y H:i:s') }}</td><td>{{ $event->actor_id }}</td><td>{{ $event->target_id }}</td><td>{{ $event->action }}</td><td>{{ $event->details }}</td></tr>@endforeach
</tbody></table></div></section></main></body></html>

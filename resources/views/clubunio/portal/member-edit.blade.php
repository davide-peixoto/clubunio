@extends('clubunio.portal.layout')
@section('content')
<div class="page-heading"><h1>{{ $member->display_name }}</h1><a class="icon-button" href="{{ route('clubunio.portal.members', $tenant->slug) }}" title="Mitglieder" aria-label="Mitglieder">@svg('heroicon-o-arrow-left')</a></div>
@if(session('message'))<p role="status">{{ session('message') }}</p>@endif
@if($errors->any())<div role="alert" class="error">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<h2>Änderungsantrag</h2>
<form method="post" action="{{ route('clubunio.portal.member.submit', ['tenant'=>$tenant->slug,'member'=>$member->id]) }}" class="registration-form">
    @csrf<input type="hidden" name="_version" value="{{ old('_version', $version) }}">
    @foreach(\App\ClubUnio\Services\MemberChangeService::FIELDS as $field => $label)
    <div><label for="{{ $field }}">{{ $label }}</label>
        @if(isset($lookups[$field]))
        <select id="{{ $field }}" name="{{ $field }}"><option value="">–</option>@foreach($lookups[$field] as $id=>$name)<option value="{{ $id }}" @selected((string)old($field,$values[$field])===(string)$id)>{{ $name }}</option>@endforeach</select>
        @else
        <input id="{{ $field }}" name="{{ $field }}" type="{{ $field==='birth_date'?'date':(str_contains($field,'email')?'email':'text') }}" value="{{ old($field,$values[$field]) }}" maxlength="255">
        @endif
    </div>
    @endforeach
    <button type="submit">Antrag einreichen</button>
</form>
<h2>Meine Anträge</h2>
@forelse($requests as $change)
<section><h3>{{ $change->created_at->timezone('Europe/Zurich')->format('d.m.Y H:i') }} · <span style="color:{{ $statusColors[\App\ClubUnio\Services\StatusAppearance::key($change->status->name)] ?? \App\ClubUnio\Services\StatusAppearance::defaultHex($change->status->name) }}">{{ $change->status->name }}</span></h3>
    <div class="table-scroll"><table><thead><tr><th>Feld</th><th>Bisher</th><th>Beantragt</th></tr></thead><tbody>
    @foreach($comparisons[$change->id] as $row)<tr><td>{{ $row['field'] }}</td><td>{{ $row['before'] ?? '–' }}</td><td>{{ $row['after'] ?? '–' }}</td></tr>@endforeach
    </tbody></table></div>
    @if($change->decision_note)<p>{{ $change->decision_note }}</p>@endif
    @if($change->state==='pending')<form method="post" action="{{ route('clubunio.portal.member.withdraw',['tenant'=>$tenant->slug,'change'=>$change->id]) }}">@csrf<input type="hidden" name="revision" value="{{ $change->revision }}"><button type="submit">Zurückziehen</button></form>@endif
</section>
@empty<p>Keine Anträge vorhanden.</p>@endforelse
<h2>Dokumente</h2>
<div class="table-scroll"><table><thead><tr><th>Dokumententyp</th><th>Dateiname</th><th>Öffnen</th></tr></thead><tbody>
@forelse($documents as $document)<tr><td>{{ $document->documentType?->name }}</td><td>{{ $document->original_filename }}</td><td><a class="icon-button" target="_blank" rel="noopener" href="{{ route('clubunio.portal.member.document',['tenant'=>$tenant->slug,'document'=>$document->id]) }}" title="Dokument öffnen" aria-label="Dokument öffnen">@svg('heroicon-o-document-text')</a></td></tr>
@empty<tr><td colspan="3">Keine freigegebenen Dokumente.</td></tr>@endforelse
</tbody></table></div>
@endsection

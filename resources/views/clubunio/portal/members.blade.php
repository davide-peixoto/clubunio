@extends('clubunio.portal.layout')
@section('content')
<div class="page-heading"><h1>{{ __('Mitglieder') }}</h1><span>{{ $members->total() }} {{ __('Einträge') }}</span></div>
<form method="get" class="toolbar" action="{{ route('clubunio.portal.members', ['tenant' => $tenant->slug]) }}">
    <div><label for="q">{{ __('Suche') }}</label><input type="search" id="q" name="q" value="{{ $q }}" maxlength="120"></div>
    <div><label for="status">{{ __('Status') }}</label><select id="status" name="status"><option value="">{{ __('Alle') }}</option>
        @foreach($statuses as $status)<option value="{{ $status->id }}" @selected((string)request('status') === (string)$status->id)>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('member_statuses', ['id' => $status->id, 'name' => $status->name, 'tenant_id' => $status->tenant_id ?? $tenant->id], tenant: $tenant->id ?? null) }}</option>@endforeach
    </select></div>
    <button class="icon-button" type="submit" aria-label="{{ __('Suchen') }}" title="{{ __('Suchen') }}">@svg('heroicon-o-magnifying-glass')</button>
    <a class="icon-button" href="{{ route('clubunio.portal.members', ['tenant' => $tenant->slug]) }}" title="{{ __('Filter zurücksetzen') }}" aria-label="{{ __('Filter zurücksetzen') }}">@svg('heroicon-o-x-mark')</a>
</form>
@if($errors->any())<p role="alert" class="error">{{ $errors->first() }}</p>@endif
<div class="table-scroll"><table><thead><tr><th>{{ __('Name') }}</th><th>{{ __('Status') }}</th><th>{{ __('Ort') }}</th><th>{{ __('E-Mail') }}</th><th>{{ __('Mobile') }}</th><th>{{ __('Rechnungen') }}</th></tr></thead><tbody>
@forelse($members as $member)
    <tr><td>{{ $member->company ?: trim($member->first_name.' '.$member->last_name) }}</td><td>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('member_statuses', ($member->status ?? []), tenant: $tenant->id ?? null) }}</td>
        <td>{{ $member->city }}</td><td>{{ $member->email }}</td><td>{{ $member->mobile }}</td><td>@if(\App\ClubUnio\Resources\ProfileUi::profileAllows(auth('portal')->user(), $tenant, 'invoices', 'view'))<a class="icon-button" href="{{ route('clubunio.portal.invoices',['tenant'=>$tenant->slug,'member'=>$member->id]) }}" title="{{ __('Rechnungen') }}" aria-label="{{ __('Rechnungen') }}">@svg('heroicon-o-document-text')</a>@endif
        @if(in_array($member->id,$editableMemberIds))<a class="icon-button" href="{{ route('clubunio.portal.member.edit',['tenant'=>$tenant->slug,'member'=>$member->id]) }}" title="{{ __('Mitgliederdaten und Dokumente') }}" aria-label="{{ __('Mitgliederdaten und Dokumente') }}">@svg('heroicon-o-pencil-square')</a>@endif</td></tr>
@empty
    <tr><td colspan="6">{{ __('Keine Mitglieder gefunden.') }}</td></tr>
@endforelse
</tbody></table></div>
<div class="pagination">{{ $members->links('pagination::simple-default') }}</div>
@endsection

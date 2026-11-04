<div class="space-y-4">
@forelse($candidates as $member)
<dl class="border-b pb-4">
<dt>{{ __('Mitglied') }}</dt><dd>{{ $member->display_name }} {{ __('· Nr.') }} {{ $member->member_number ?? $member->id }}</dd>
<dt>{{ __('Geburtsdatum') }}</dt><dd>{{ $member->birth_date?->format('d.m.Y') ?? '–' }}</dd>
<dt>{{ __('Adresse') }}</dt><dd>{{ $member->street }} {{ $member->house_number }}, {{ $member->postal_code }} {{ $member->city }}</dd>
<dt>{{ __('Status') }}</dt><dd>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('member_statuses', ($member->status ?? []), tenant: $tenant->id ?? null) }} @if($member->exit_date) {{ __('· Austritt') }} {{ $member->exit_date->format('d.m.Y') }} @endif</dd>
</dl>
@empty<p>{{ __('Keine passenden bestehenden Mitglieder gefunden.') }}</p>@endforelse
</div>

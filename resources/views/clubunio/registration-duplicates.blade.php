<div class="space-y-4">
@forelse($candidates as $member)
<dl class="border-b pb-4">
<dt>Mitglied</dt><dd>{{ $member->display_name }} · Nr. {{ $member->member_number ?? $member->id }}</dd>
<dt>Geburtsdatum</dt><dd>{{ $member->birth_date?->format('d.m.Y') ?? '–' }}</dd>
<dt>Adresse</dt><dd>{{ $member->street }} {{ $member->house_number }}, {{ $member->postal_code }} {{ $member->city }}</dd>
<dt>Status</dt><dd>{{ $member->status?->name }} @if($member->exit_date) · Austritt {{ $member->exit_date->format('d.m.Y') }} @endif</dd>
</dl>
@empty<p>Keine passenden bestehenden Mitglieder gefunden.</p>@endforelse
</div>

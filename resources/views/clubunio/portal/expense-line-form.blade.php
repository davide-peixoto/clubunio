@if($editable && \App\ClubUnio\Resources\ProfileUi::profileAllows(auth('portal')->user(), $tenant, 'expenses', $line ? 'update' : 'create'))
<form method="post" action="{{ route('clubunio.portal.expense.line',['tenant'=>$tenant->slug,'expense'=>$e->id,'line'=>$line?->id]) }}" class="registration-form expense-line-form">@csrf
<input type="hidden" name="expense_revision" value="{{ $e->revision }}">
@if($line)<input type="hidden" name="revision" value="{{ $line->revision }}">@endif
<label>{{ __('Spesentyp') }}<select name="expense_type_id" required><option value="">{{ __('Bitte wählen') }}</option>@foreach($types as $type)<option value="{{ $type->id }}" data-default-amount="{{ $type->amount_source==='team_laundry' ? $e->teamSeason?->team?->laundry_allowance : '' }}" @selected($line?->expense_type_id===$type->id)>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('expense_types', $type, tenant: $tenant->id ?? null) }}</option>@endforeach</select></label>
<label>{{ __('Spieltyp') }}<select name="game_type_id"><option value="">{{ __('Nicht gewählt') }}</option>@foreach($gameTypes as $type)<option value="{{ $type->id }}" @selected($line?->game_type_id===$type->id)>{{ app(\App\ClubUnio\Services\LookupTranslations::class)->label('game_types', $type, tenant: $tenant->id ?? null) }}</option>@endforeach</select></label>
<label>{{ __('Gegner') }}<input name="opponent" value="{{ $line?->opponent }}" maxlength="255"></label>
<label>{{ __('Betrag CHF') }}<input name="amount" type="number" step="0.01" min="0.01" max="999999999" required value="{{ $line?->amount }}"></label>
<label>{{ __('Bemerkungen') }}<textarea name="notes" maxlength="4000">{{ $line?->notes }}</textarea></label>
@if(! $line || \App\ClubUnio\Resources\ProfileUi::profileAllows(auth('portal')->user(), $tenant, 'expenses', $line->active ? 'deactivate' : 'restore'))
<input type="hidden" name="active" value="0"><label><input type="checkbox" name="active" value="1" @checked($line?->active ?? true)> {{ __('Aktiv') }}</label>
@else
<input type="hidden" name="active" value="{{ (int) $line->active }}">
@endif
<button>{{ __('Speichern') }}</button></form>
@endif

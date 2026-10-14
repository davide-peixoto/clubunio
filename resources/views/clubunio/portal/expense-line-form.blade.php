<form method="post" action="{{ route('clubunio.portal.expense.line',['tenant'=>$tenant->slug,'expense'=>$e->id,'line'=>$line?->id]) }}" class="registration-form expense-line-form">@csrf
<input type="hidden" name="expense_revision" value="{{ $e->revision }}">
@if($line)<input type="hidden" name="revision" value="{{ $line->revision }}">@endif
<label>Spesentyp<select name="expense_type_id" required><option value="">Bitte wählen</option>@foreach($types as $type)<option value="{{ $type->id }}" data-default-amount="{{ $type->amount_source==='team_laundry' ? $e->teamSeason?->team?->laundry_allowance : '' }}" @selected($line?->expense_type_id===$type->id)>{{ $type->name }}</option>@endforeach</select></label>
<label>Spieltyp<select name="game_type_id"><option value="">Nicht gewählt</option>@foreach($gameTypes as $type)<option value="{{ $type->id }}" @selected($line?->game_type_id===$type->id)>{{ $type->name }}</option>@endforeach</select></label>
<label>Gegner<input name="opponent" value="{{ $line?->opponent }}" maxlength="255"></label>
<label>Betrag CHF<input name="amount" type="number" step="0.01" min="0.01" max="999999999" required value="{{ $line?->amount }}"></label>
<label>Bemerkungen<textarea name="notes" maxlength="4000">{{ $line?->notes }}</textarea></label>
<input type="hidden" name="active" value="0"><label><input type="checkbox" name="active" value="1" checked> Aktiv</label><button>Speichern</button></form>

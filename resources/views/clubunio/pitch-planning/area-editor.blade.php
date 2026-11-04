<label>{{ __('Spielfeld') }}<select wire:change="editArea(Number($event.target.value))">@foreach($options['fields'] as $field)<option value="{{ $field['id'] }}" @selected((int) $form['field_id'] === (int) $field['id'])>{{ $field['name'] }}</option>@endforeach</select></label>
<label>{{ __('Teilfläche') }}<select wire:change="editArea({{ (int) $form['field_id'] }}, $event.target.value ? Number($event.target.value) : null)"><option value="">{{ __('Neue Teilfläche') }}</option>@foreach($areas as $area)<option value="{{ $area['id'] }}" @selected(($selectedArea['id'] ?? null) === $area['id'])>{{ $area['name'] }}</option>@endforeach</select></label>
<label>{{ __('Name (Deutsch)') }}<input wire:model="form.name_de" maxlength="160" required></label>
<label>{{ __('Name (Englisch)') }}<input wire:model="form.name_en" maxlength="160" required></label>
@foreach(['x' => __('Position X (0–1)'), 'y' => __('Position Y (0–1)'), 'width' => __('Breite (0–1)'), 'height' => __('Höhe (0–1)')] as $key => $label)
    <label>{{ $label }}<input type="number" min="{{ in_array($key, ['width', 'height']) ? '0.001' : '0' }}" max="1" step="0.001" wire:model="form.{{ $key }}" required></label>
@endforeach

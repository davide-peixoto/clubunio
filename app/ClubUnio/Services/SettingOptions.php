<?php

namespace App\ClubUnio\Services;

use App\ClubUnio\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettingOptions
{
    public function archived(Setting $setting): bool
    {
        return $setting->area === 'general' && in_array($setting->key, ['SommerWinter', 'StatusPlatz1', 'StatusPlatz2', 'StatusPlatz3'], true);
    }

    public function options(Setting $setting): ?array
    {
        if ($setting->is_secret || $setting->area !== 'general') { return null; }
        $db = DB::connection('clubunio');
        if ($setting->key === ActiveTrainingPlan::KEY) {
            return [0 => 'Nicht festgelegt'] + $db->table('clubora.training_options')
                ->where('tenant_id', $setting->tenant_id)->where('category', 'period')
                ->where('active', true)->orderBy('name')->pluck('name', 'id')->all();
        }
        if ($setting->key === 'SaisonID') {
            $rows = $db->table('clubora.seasons')->where('tenant_id', $setting->tenant_id)->orderByDesc('id')->get();
            $options = [];
            foreach ($rows as $row) {
                if (! $row->active) { continue; }
                // Existing consumers prefer imported IDs; never change the persisted ID contract.
                $value = $row->legacy_id ?? $row->id;
                if ($row->legacy_id === null && $rows->contains('legacy_id', $value)) { continue; }
                $options[(string) $value] = $row->name;
            }
            return $options;
        }
        if ($setting->key === 'SommerWinter') {
            return $db->table('clubora.training_options')->where('tenant_id', $setting->tenant_id)->where('category', 'period')->where('active', true)->orderBy('name')->pluck('name', 'code')->all();
        }
        if (in_array($setting->key, ['StatusPlatz1', 'StatusPlatz2', 'StatusPlatz3'], true)) {
            return $db->table('clubora.facility_statuses')->where('tenant_id', $setting->tenant_id)->where('active', true)->orderBy('name')->pluck('name', 'code')->all();
        }
        return null;
    }

    public function display(Setting $setting): string
    {
        if ($setting->is_secret) { return '••••••••'; }
        $value = Crypt::decryptString($setting->encrypted_value);
        $options = $this->options($setting);
        return $options === null ? $value : ($options[$value] ?? 'Nicht zugeordnet').' ('.$value.')';
    }

    public function usage(Setting $setting): string
    {
        if ($this->archived($setting)) { return 'Altbestand'; }
        if ($setting->area === 'general' && in_array($setting->key, ['SaisonID', ActiveTrainingPlan::KEY], true)) { return 'Verwendet'; }
        return $setting->enabled ? 'Freigegeben' : 'Nicht freigegeben';
    }

    public function hint(Setting $setting): string
    {
        if ($setting->area === 'general' && $setting->key === ActiveTrainingPlan::KEY) {
            return 'Aktiver Trainingsplan für Trainingszeiten und Signage.';
        }
        if ($this->archived($setting)) {
            return $setting->key === 'SommerWinter'
                ? 'Importierter Altwert. Massgebend ist Aktiver Trainingsplan unter Allgemein.'
                : 'Importierter Altwert. Den aktuellen Platzstatus unter Platzverfügbarkeit ändern.';
        }
        return $setting->area === 'general' && $setting->key === 'SaisonID'
            ? 'Aktuelle Saison für die App. Wird unabhängig vom technischen Freigabeschalter verwendet.'
            : 'Technische Freigabe des Parameters; kein allgemeiner Nachweis, ob die gesamte Funktion aktiv ist.';
    }

    public function validate(Setting $setting, string $value): void
    {
        if ($this->archived($setting)) {
            throw ValidationException::withMessages(['value' => $this->hint($setting)]);
        }
        $options = $this->options($setting);
        if ($options !== null && ! array_key_exists($value, $options)) {
            throw ValidationException::withMessages(['value' => 'Bitte einen gültigen aktiven Wert dieses Vereins auswählen.']);
        }
    }
}

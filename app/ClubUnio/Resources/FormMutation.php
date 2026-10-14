<?php

namespace App\ClubUnio\Resources;

use Closure;
use Illuminate\Validation\ValidationException;

class FormMutation
{
    public static function run(object $livewire, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            // Domain services use field names, whereas modal errors need the
            // current Filament form state path to appear beside their inputs.
            $prefix = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
            $messages = [];
            foreach ($e->errors() as $field => $errors) {
                $messages[$prefix.'.'.$field] = $errors;
            }
            throw ValidationException::withMessages($messages);
        }
    }
}

<?php

namespace App\ClubUnio;

use Illuminate\Routing\UrlGenerator;

class SessionUrlGenerator extends UrlGenerator
{
    public function toRoute($route, $parameters, $absolute)
    {
        // Filament still supplies tenant for model scopes. It is no longer a URL parameter.
        if (str_starts_with($route->getName() ?? '', 'filament.clubunio.') && is_array($parameters)) {
            unset($parameters['tenant']);
        }

        return parent::toRoute($route, $parameters, $absolute);
    }
}

<?php
return ['enabled' => filter_var(env('CLUBUNIO_MOBILE_EXPENSE_PROVENANCE_ENABLED', false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true];

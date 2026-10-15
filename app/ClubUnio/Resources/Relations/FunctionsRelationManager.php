<?php

namespace App\ClubUnio\Resources\Relations;

class FunctionsRelationManager extends EditableDossierRelationManager
{
    protected static string $relationship = 'functions';

    protected static string $kind = 'functions';

    protected static ?string $title = 'Funktionen';
}

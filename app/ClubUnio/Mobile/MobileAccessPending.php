<?php

namespace App\ClubUnio\Mobile;

/** Only emit after a password was actually verified; never as an anonymous account lookup. */
final class MobileAccessPending extends \Symfony\Component\HttpKernel\Exception\HttpException
{
    public function __construct() { parent::__construct(403, 'Deine E-Mail ist bestätigt. Dein Vereinszugang wartet noch auf die Zuordnungsprüfung durch den Verein.'); }
}

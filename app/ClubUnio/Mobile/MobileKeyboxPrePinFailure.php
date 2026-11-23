<?php
namespace App\ClubUnio\Mobile;

/** Definitive failure before any physical PIN request; contains no provider payload. */
final class MobileKeyboxPrePinFailure extends \RuntimeException
{
    public function __construct(public readonly bool $availabilityFailure)
    {
        parent::__construct($availabilityFailure ? 'keybox_oauth_unavailable' : 'keybox_oauth_rejected');
    }
}

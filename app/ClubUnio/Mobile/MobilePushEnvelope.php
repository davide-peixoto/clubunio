<?php

namespace App\ClubUnio\Mobile;

/** Public routing identifiers only. Never accepts presentation text or URLs. */
final readonly class MobilePushEnvelope
{
    public function __construct(public int $accountId, public int $tenantId, public string $kind, public int $objectId, public string $deliveryId)
    {
        if ($accountId <= 0 || $tenantId <= 0 || $objectId <= 0
            || ! in_array($kind, MobilePushTargets::KINDS, true)
            || ! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/D', $deliveryId)) {
            throw new \InvalidArgumentException('Invalid personal push routing identifiers');
        }
    }

    public function data(): array
    {
        return ['v' => '1', 'account_id' => (string) $this->accountId, 'tenant_id' => (string) $this->tenantId,
            'kind' => $this->kind, 'object_id' => (string) $this->objectId, 'delivery_id' => $this->deliveryId];
    }
}

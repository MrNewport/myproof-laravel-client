<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Webhooks;

/** Authentication does not deduplicate. Persist (workspaceId, id) before side effects. */
final readonly class Notification
{
    public function __construct(public string $id, public string $workspaceId, public string $deliveryId, public string $mode, public string $topic,
        public string $occurredAt, public string $recordedAt, public ?NotificationEvidence $evidence, public ?NotificationExport $export) {}
}

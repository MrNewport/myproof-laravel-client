<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Webhooks;

final readonly class NotificationEvidence
{
    public function __construct(public string $eventId, public string $type, public string $source, public int $sequence, public string $sha256) {}
}

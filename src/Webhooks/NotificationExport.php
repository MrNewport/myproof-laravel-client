<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Webhooks;

final readonly class NotificationExport
{
    public function __construct(public string $id, public string $status, public string $privacyMode) {}
}

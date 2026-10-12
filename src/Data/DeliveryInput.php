<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

final readonly class DeliveryInput
{
    public function __construct(public string $orderId, public string $brandId, public string $accessMode = 'email_code', public int $expiresInDays = 30) {}

    public function toArray(): array
    {
        return ['order_id' => $this->orderId, 'brand_id' => $this->brandId, 'access_mode' => $this->accessMode, 'expires_in_days' => $this->expiresInDays];
    }
}

<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

final readonly class DomainInput
{
    public function __construct(public string $brandId, public string $hostname) {}

    public function toArray(): array
    {
        if (! preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/i', $this->brandId) || strlen($this->hostname) > 244
            || ! preg_match('/\A(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z][a-zA-Z0-9-]{0,61}[a-zA-Z0-9]\z/', $this->hostname)) {
            throw new \InvalidArgumentException('A brand ULID and ASCII subdomain are required.');
        }

        return ['brand_id' => $this->brandId, 'hostname' => $this->hostname];
    }
}

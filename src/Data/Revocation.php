<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

final readonly class Revocation
{
    public function __construct(public string $id, public string $status, public ?int $residualUrlSeconds = null) {}

    public static function fromArray(array $data): self
    {
        return new self(Decode::string($data, 'id'), Decode::string($data, 'status'), isset($data['residual_url_seconds']) ? Decode::int($data, 'residual_url_seconds') : null);
    }
}

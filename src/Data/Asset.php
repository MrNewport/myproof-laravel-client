<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

final readonly class Asset
{
    public function __construct(public string $id, public string $name, public string $mode) {}

    public static function fromArray(array $data): self
    {
        return new self(Decode::string($data, 'id'), Decode::string($data, 'name'), Decode::string($data, 'mode'));
    }
}

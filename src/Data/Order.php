<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

final readonly class Order
{
    public function __construct(public string $id, public string $mode, public string $snapshotSha256) {}

    public static function fromArray(array $data): self
    {
        return new self(Decode::string($data, 'id'), Decode::string($data, 'mode'), Decode::string($data, 'snapshot_sha256'));
    }
}

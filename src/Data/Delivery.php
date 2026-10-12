<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

final readonly class Delivery
{
    public function __construct(public string $id, public ?string $orderId, public string $brandId, public string $mode, public string $status, public ?string $reference, public string $accessMode, public int $headSequence, public ?string $headHash, public ?string $expiresAt, public ?string $revokedAt) {}

    public static function fromArray(array $data): self
    {
        return new self(Decode::string($data, 'id'), Decode::nullableString($data, 'order_id'), Decode::string($data, 'brand_id'), Decode::string($data, 'mode'), Decode::string($data, 'status'), Decode::nullableString($data, 'reference'), Decode::string($data, 'access_mode'), Decode::int($data, 'head_sequence'), Decode::nullableString($data, 'head_hash'), Decode::nullableString($data, 'expires_at'), Decode::nullableString($data, 'revoked_at'));
    }
}

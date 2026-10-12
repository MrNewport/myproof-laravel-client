<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

final readonly class AssetVersion
{
    public function __construct(public string $id, public string $assetId, public string $mode, public string $status, public int $sizeBytes, public ?string $mime, public ?string $sha256, public string $scanStatus) {}

    public static function fromArray(array $data): self
    {
        return new self(Decode::string($data, 'id'), Decode::string($data, 'asset_id'), Decode::string($data, 'mode'), Decode::string($data, 'status'), Decode::int($data, 'size_bytes'), Decode::nullableString($data, 'mime'), Decode::nullableString($data, 'sha256'), Decode::string($data, 'scan_status'));
    }
}

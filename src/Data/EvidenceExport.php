<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

use MyProof\LaravelClient\Exceptions\ApiException;

final readonly class EvidenceExport
{
    public function __construct(public string $id, public string $deliveryId, public string $mode, public string $privacyMode, public string $status, public int $headSequence, public ?string $headHash, public string $requestedAt, public ?string $completedAt, public ?string $lastErrorCode, public string $format = 'v2', public int $segmentCount = 0) {}

    public static function fromArray(array $data): self
    {
        $format = array_key_exists('format', $data) ? Decode::string($data, 'format') : 'v2';
        $count = isset($data['segment_count']) ? Decode::int($data, 'segment_count') : 0;
        if (! in_array($format, ['v2', 'collection.v1'], true) || $count > 1024 || ($format === 'v2' && $count !== 0)) {
            throw new ApiException(502, 'invalid_response');
        }

        return new self(Decode::string($data, 'id'), Decode::string($data, 'delivery_id'), Decode::string($data, 'mode'), Decode::string($data, 'privacy_mode'), Decode::string($data, 'status'), Decode::int($data, 'head_sequence'), Decode::nullableString($data, 'head_hash'), Decode::string($data, 'requested_at'), Decode::nullableString($data, 'completed_at'), Decode::nullableString($data, 'last_error_code'), $format, $count);
    }
}

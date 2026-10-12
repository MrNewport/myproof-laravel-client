<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

/** Timeline facts may contain provider identifiers; independently verify a downloaded evidence archive for chain/signature trust. */
final readonly class EvidenceEvent
{
    public function __construct(public string $id, public int $sequence, public string $type, public string $source, public string $occurredAt, public string $recordedAt, public array $payload, public string $sha256) {}

    public static function fromArray(array $data): self
    {
        return new self(Decode::string($data, 'id'), Decode::int($data, 'sequence'), Decode::string($data, 'type'), Decode::string($data, 'source'), Decode::string($data, 'occurred_at'), Decode::string($data, 'recorded_at'), Decode::array($data, 'payload'), Decode::string($data, 'sha256'));
    }
}

<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

/** A short-lived sensitive result. Never log, serialize into a job or store in browser history. */
final readonly class AccessLink
{
    public function __construct(#[\SensitiveParameter] private string $url, public int $generation, public ?string $expiresAt = null, public ?string $id = null) {}

    public function url(): string
    {
        return $this->url;
    }

    public static function fromArray(array $data): self
    {
        return new self(Decode::string($data, 'url'), Decode::int($data, 'generation'), Decode::nullableString($data, 'expires_at'), Decode::nullableString($data, 'id'));
    }

    public function __debugInfo(): array
    {
        return ['url' => '[redacted]', 'generation' => $this->generation];
    }
}

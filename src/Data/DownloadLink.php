<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

use MyProof\LaravelClient\Exceptions\ApiException;

/** The client deliberately does not follow this URL or forward its bearer to storage. */
final readonly class DownloadLink
{
    public function __construct(#[\SensitiveParameter] private string $url, public int $expiresInSeconds, public ?string $filename = null) {}

    public function url(): string
    {
        return $this->url;
    }

    public static function fromArray(array $data): self
    {
        $expires = Decode::int($data, 'expires_in_seconds');
        $filename = Decode::nullableString($data, 'filename');
        if ($expires > 120 || ($filename !== null && ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $filename))) {
            throw new ApiException(502, 'invalid_response');
        }

        return new self(Decode::string($data, 'url'), $expires, $filename);
    }

    public function __debugInfo(): array
    {
        return ['url' => '[redacted]', 'expiresInSeconds' => $this->expiresInSeconds];
    }
}

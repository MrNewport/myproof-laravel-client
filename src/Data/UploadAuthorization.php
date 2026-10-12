<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

use MyProof\LaravelClient\Exceptions\ApiException;

final readonly class UploadAuthorization
{
    public function __construct(public string $sessionId, public string $assetId, public string $expiresAt, #[\SensitiveParameter] private string $url, #[\SensitiveParameter] private array $fields) {}

    public function url(): string
    {
        return $this->url;
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public static function fromArray(array $data): self
    {
        $upload = Decode::array($data, 'upload');
        $fields = Decode::array($upload, 'fields');
        foreach ($fields as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new ApiException(502, 'invalid_response');
            }
        }

        return new self(Decode::string($data, 'session_id'), Decode::string($data, 'asset_id'), Decode::string($data, 'expires_at'), Decode::string($upload, 'url'), $fields);
    }

    public function __debugInfo(): array
    {
        return ['sessionId' => $this->sessionId, 'authorization' => '[redacted]'];
    }
}

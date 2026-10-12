<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

use MyProof\LaravelClient\Exceptions\ApiException;

/** Public lifecycle metadata only. Validation URLs are data, never fetched by this client. */
final readonly class Domain
{
    /** @param list<array{type:string,name:string,value:string}> $providerRecords */
    public function __construct(public string $id, public string $brandId, public string $hostname, public string $status,
        public ?string $claimExpiresAt, public ?string $verifiedAt, public ?string $freshUntil,
        public ?string $certificateStatus, public array $providerRecords, public ?string $errorCode, public ?string $nextAttemptAt) {}

    public static function fromArray(array $data): self
    {
        $status = Decode::string($data, 'status');
        $records = Decode::array($data, 'provider_records');
        if (! in_array($status, ['pending', 'provisioning', 'active', 'suspended', 'removing', 'removed'], true)
            || ! array_is_list($records) || count($records) > 2) {
            throw new ApiException(502, 'invalid_response');
        }
        $safe = [];
        foreach ($records as $record) {
            if (! is_array($record) || ! in_array($record['type'] ?? null, ['CNAME', 'HTTP_VALIDATION'], true)) {
                throw new ApiException(502, 'invalid_response');
            }
            $safe[] = ['type' => $record['type'], 'name' => self::bounded($record, 'name', 2048), 'value' => self::bounded($record, 'value', 2048)];
        }

        return new self(self::bounded($data, 'id', 26), self::bounded($data, 'brand_id', 26), self::bounded($data, 'hostname', 244), $status,
            self::nullable($data, 'claim_expires_at'), self::nullable($data, 'verified_at'), self::nullable($data, 'fresh_until'),
            self::nullable($data, 'certificate_status'), $safe, self::nullable($data, 'error_code'), self::nullable($data, 'next_attempt_at'));
    }

    private static function bounded(array $data, string $key, int $max): string
    {
        $value = Decode::string($data, $key);
        if ($value === '' || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new ApiException(502, 'invalid_response');
        }

        return $value;
    }

    private static function nullable(array $data, string $key): ?string
    {
        return ($data[$key] ?? null) === null ? null : self::bounded($data, $key, 64);
    }
}

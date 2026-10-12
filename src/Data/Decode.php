<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

use MyProof\LaravelClient\Exceptions\ApiException;

/** @internal */
final class Decode
{
    public static function string(array $data, string $key): string
    {
        if (! isset($data[$key]) || ! is_string($data[$key]) || strlen($data[$key]) > 16384) {
            throw new ApiException(502, 'invalid_response');
        }

        return $data[$key];
    }

    public static function nullableString(array $data, string $key): ?string
    {
        return ($data[$key] ?? null) === null ? null : self::string($data, $key);
    }

    public static function int(array $data, string $key): int
    {
        if (! isset($data[$key]) || ! is_int($data[$key]) || $data[$key] < 0) {
            throw new ApiException(502, 'invalid_response');
        }

        return $data[$key];
    }

    public static function array(array $data, string $key): array
    {
        if (! isset($data[$key]) || ! is_array($data[$key])) {
            throw new ApiException(502, 'invalid_response');
        }

        return $data[$key];
    }
}

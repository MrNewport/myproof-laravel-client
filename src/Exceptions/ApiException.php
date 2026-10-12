<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Exceptions;

use RuntimeException;

/** Contains only bounded public error metadata, never a request, bearer, URL or raw response. */
final class ApiException extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, public readonly ?string $requestId = null)
    {
        parent::__construct('MyProof API operation failed: '.$errorCode, $status);
    }
}

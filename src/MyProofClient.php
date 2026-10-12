<?php

declare(strict_types=1);

namespace MyProof\LaravelClient;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use InvalidArgumentException;
use MyProof\Evidence\Schema;
use MyProof\LaravelClient\Data\AccessLink;
use MyProof\LaravelClient\Data\Asset;
use MyProof\LaravelClient\Data\AssetVersion;
use MyProof\LaravelClient\Data\Decode;
use MyProof\LaravelClient\Data\Delivery;
use MyProof\LaravelClient\Data\DeliveryInput;
use MyProof\LaravelClient\Data\Domain;
use MyProof\LaravelClient\Data\DomainClaim;
use MyProof\LaravelClient\Data\DomainInput;
use MyProof\LaravelClient\Data\DownloadLink;
use MyProof\LaravelClient\Data\EvidenceEvent;
use MyProof\LaravelClient\Data\EvidenceExport;
use MyProof\LaravelClient\Data\Order;
use MyProof\LaravelClient\Data\OrderInput;
use MyProof\LaravelClient\Data\Page;
use MyProof\LaravelClient\Data\Revocation;
use MyProof\LaravelClient\Data\UploadAuthorization;
use MyProof\LaravelClient\Data\UploadInput;
use MyProof\LaravelClient\Exceptions\ApiException;

final class MyProofClient
{
    private string $origin;

    private const MAX_RESPONSE_BYTES = 2097152;

    public function __construct(private readonly Factory $http, string $origin, #[\SensitiveParameter] private readonly string $token, private readonly int $timeoutSeconds = 20, private readonly int $connectTimeoutSeconds = 5, private readonly int $maxAttempts = 2)
    {
        $parts = parse_url($origin);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user'], $parts['pass'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || ! preg_match('/\A[1-9][0-9]{0,18}\|[A-Za-z0-9_-]{43}\z/', $token)
            || $timeoutSeconds < 1 || $timeoutSeconds > 60 || $connectTimeoutSeconds < 1 || $connectTimeoutSeconds > $timeoutSeconds || $maxAttempts < 1 || $maxAttempts > 3) {
            throw new InvalidArgumentException('Invalid MyProof client configuration. Use an HTTPS origin and a scoped token.');
        }
        $this->origin = rtrim($origin, '/');
    }

    public function beginUpload(UploadInput $input, string $idempotencyKey): UploadAuthorization
    {
        return UploadAuthorization::fromArray($this->data('POST', '/uploads', $input->toArray(), $idempotencyKey));
    }

    public function finalizeUpload(string $uploadId, string $objectVersionId, string $idempotencyKey): AssetVersion
    {
        return AssetVersion::fromArray($this->data('POST', '/uploads/'.$this->id($uploadId).'/finalize', ['version_id' => $objectVersionId], $idempotencyKey));
    }

    public function revokeUpload(string $uploadId, string $idempotencyKey): Revocation
    {
        return Revocation::fromArray($this->data('POST', '/uploads/'.$this->id($uploadId).'/revoke', [], $idempotencyKey));
    }

    /** @return Page<Asset> */
    public function assets(?string $cursor = null, int $limit = 25): Page
    {
        return Page::fromArray($this->request('GET', '/assets', $this->pagination($cursor, $limit)), Asset::fromArray(...));
    }

    /** @return Page<AssetVersion> */
    public function versions(string $assetId, ?string $cursor = null, int $limit = 25): Page
    {
        return Page::fromArray($this->request('GET', '/assets/'.$this->id($assetId).'/versions', $this->pagination($cursor, $limit)), AssetVersion::fromArray(...));
    }

    public function version(string $versionId): AssetVersion
    {
        return AssetVersion::fromArray($this->data('GET', '/asset-versions/'.$this->id($versionId)));
    }

    public function createOrder(OrderInput $input, string $idempotencyKey): Order
    {
        return Order::fromArray($this->data('POST', '/orders', $input->toArray(), $idempotencyKey));
    }

    public function createDelivery(DeliveryInput $input, string $idempotencyKey): Delivery
    {
        return Delivery::fromArray($this->data('POST', '/deliveries', $input->toArray(), $idempotencyKey));
    }

    /** @return Page<Delivery> */
    public function deliveries(?string $cursor = null, int $limit = 25): Page
    {
        return Page::fromArray($this->request('GET', '/deliveries', $this->pagination($cursor, $limit)), Delivery::fromArray(...));
    }

    public function delivery(string $deliveryId): Delivery
    {
        return Delivery::fromArray($this->data('GET', '/deliveries/'.$this->id($deliveryId)));
    }

    public function revealDelivery(string $deliveryId, string $idempotencyKey): AccessLink
    {
        return AccessLink::fromArray($this->data('POST', '/deliveries/'.$this->id($deliveryId).'/reveal', [], $idempotencyKey));
    }

    public function rotateDelivery(string $deliveryId, string $idempotencyKey): AccessLink
    {
        return AccessLink::fromArray($this->data('POST', '/deliveries/'.$this->id($deliveryId).'/rotate', [], $idempotencyKey));
    }

    public function revokeDelivery(string $deliveryId, string $idempotencyKey): Revocation
    {
        return Revocation::fromArray($this->data('POST', '/deliveries/'.$this->id($deliveryId).'/revoke', [], $idempotencyKey));
    }

    /** @return Page<EvidenceEvent> */
    public function events(string $deliveryId, int $afterSequence = 0, int $limit = 25, ?string $source = null): Page
    {
        $this->pagination(null, $limit);
        if ($afterSequence < 0 || $afterSequence > 9007199254740991) {
            throw new InvalidArgumentException('Invalid sequence cursor.');
        }

        if ($source !== null && $source !== '' && ! in_array($source, Schema::SOURCES, true)) {
            throw new InvalidArgumentException('Invalid event source filter.');
        }
        $query = ['after_sequence' => $afterSequence, 'limit' => $limit];
        if ($source !== null && $source !== '') {
            $query['source'] = $source;
        }

        return Page::fromArray($this->request('GET', '/deliveries/'.$this->id($deliveryId).'/events', $query), EvidenceEvent::fromArray(...), 'next_after_sequence');
    }

    public function requestExport(string $deliveryId, string $idempotencyKey): EvidenceExport
    {
        return EvidenceExport::fromArray($this->data('POST', '/deliveries/'.$this->id($deliveryId).'/exports', [], $idempotencyKey));
    }

    public function export(string $exportId): EvidenceExport
    {
        return EvidenceExport::fromArray($this->data('GET', '/exports/'.$this->id($exportId)));
    }

    public function exportDownload(string $exportId, ?int $part = null): DownloadLink
    {
        if ($part !== null && ($part < 1 || $part > 1024)) {
            throw new InvalidArgumentException('Collection part must be between 1 and 1024.');
        }

        return DownloadLink::fromArray($this->data('GET', '/exports/'.$this->id($exportId).'/download', $part === null ? [] : ['part' => $part]));
    }

    public function claimDomain(DomainInput $input, string $idempotencyKey): DomainClaim
    {
        return DomainClaim::fromArray($this->data('POST', '/domains', $input->toArray(), $idempotencyKey));
    }

    public function domain(string $domainId): Domain
    {
        return Domain::fromArray($this->data('GET', '/domains/'.$this->id($domainId)));
    }

    private function data(string $method, string $path, array $input = [], ?string $key = null): array
    {
        return Decode::array($this->request($method, $path, $input, $key), 'data');
    }

    /** Fixed private routes only. All public POST methods require a stable idempotency key. */
    private function request(string $method, string $path, array $input = [], ?string $key = null): array
    {
        if ($method === 'POST' && ($key === null || ! preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $key))) {
            throw new InvalidArgumentException('A valid stable idempotency key is required.');
        }
        $json = json_encode((object) $input, JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) {
            throw new InvalidArgumentException('API request exceeds the byte limit.');
        }
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $pending = $this->http->withToken($this->token)->acceptJson()->withoutRedirecting()
                    ->timeout($this->timeoutSeconds)->connectTimeout($this->connectTimeoutSeconds)
                    ->withOptions(['stream' => true, 'cookies' => false, 'http_errors' => false]);
                if ($key !== null) {
                    $pending = $pending->withHeaders(['Idempotency-Key' => $key]);
                }
                if ($method === 'POST') {
                    $pending = $pending->withBody($json, 'application/json');
                }
                $response = $pending->send($method, $this->origin.'/api/v1'.$path, $method === 'GET' ? ['query' => $input] : []);
            } catch (ConnectionException) {
                if ($attempt < $this->maxAttempts) {
                    $this->backoff($attempt);

                    continue;
                }
                throw new ApiException(0, 'connection_failed');
            }
            $stream = $response->toPsrResponse()->getBody();
            try {
                if (in_array($response->status(), [429, 502, 503, 504], true) && $attempt < $this->maxAttempts) {
                    $stream->close();
                    $this->backoff($attempt);

                    continue;
                }
                if ((int) $response->header('Content-Length') > self::MAX_RESPONSE_BYTES) {
                    throw new ApiException(502, 'response_too_large');
                }
                $bytes = '';
                while (strlen($bytes) <= self::MAX_RESPONSE_BYTES) {
                    $chunk = $stream->read(min(65536, self::MAX_RESPONSE_BYTES + 1 - strlen($bytes)));
                    if ($chunk === '') {
                        if (! $stream->eof()) {
                            throw new ApiException(502, 'invalid_response');
                        }
                        break;
                    }
                    $bytes .= $chunk;
                }
                if (strlen($bytes) > self::MAX_RESPONSE_BYTES) {
                    throw new ApiException(502, 'response_too_large');
                }
            } catch (ApiException $failure) {
                throw $failure;
            } catch (\Throwable) {
                // Stream/transport exceptions can embed private URLs or bearer headers.
                throw new ApiException(0, 'connection_failed');
            } finally {
                $stream->close();
            }
            $body = json_decode($bytes, true, 32);
            if (! $response->successful()) {
                $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
                $allowed = ['authentication_required', 'forbidden', 'validation_failed', 'not_found', 'method_not_allowed', 'idempotency_conflict', 'rate_limited', 'temporarily_unavailable'];
                $code = in_array($error['code'] ?? '', $allowed, true) ? $error['code'] : 'unexpected_response';
                $requestId = is_string($error['request_id'] ?? null) && preg_match('/\A[0-9a-f-]{36}\z/i', $error['request_id']) ? $error['request_id'] : null;
                throw new ApiException($response->status(), $code, $requestId);
            }
            if (! is_array($body) || ! str_contains(strtolower($response->header('Content-Type')), 'application/json')) {
                throw new ApiException(502, 'invalid_response');
            }

            return $body;
        }
        throw new ApiException(0, 'connection_failed');
    }

    private function id(string $id): string
    {
        if (! preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/i', $id)) {
            throw new InvalidArgumentException('Invalid resource identifier.');
        }

        return $id;
    }

    private function pagination(?string $cursor, int $limit): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Page size must be between 1 and 50.');
        }

        return $cursor === null ? ['limit' => $limit] : ['limit' => $limit, 'cursor' => $this->id($cursor)];
    }

    private function backoff(int $attempt): void
    {
        usleep(min(500000, 100000 * $attempt));
    }

    public function __debugInfo(): array
    {
        return ['origin' => $this->origin, 'token' => '[redacted]'];
    }
}

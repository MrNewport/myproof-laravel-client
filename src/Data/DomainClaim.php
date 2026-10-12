<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

use MyProof\LaravelClient\Exceptions\ApiException;

final readonly class DomainClaim
{
    /** @param array{type:string,name:string,value:string} $record */
    private function __construct(public Domain $domain, #[\SensitiveParameter] private array $record) {}

    /** Private TXT proof: never log, place in browser history, or serialize into jobs. */
    public function ownershipRecord(): array
    {
        return $this->record;
    }

    public static function fromArray(array $data): self
    {
        $domain = Domain::fromArray(Decode::array($data, 'domain'));
        $record = Decode::array($data, 'ownership_record');
        if (($record['type'] ?? null) !== 'TXT' || ($record['name'] ?? null) !== '_myproof.'.$domain->hostname
            || ! is_string($record['value'] ?? null) || ! preg_match('/\Amyproof-domain=[a-f0-9]{64}\z/', $record['value'])) {
            throw new ApiException(502, 'invalid_response');
        }

        return new self($domain, ['type' => 'TXT', 'name' => $record['name'], 'value' => $record['value']]);
    }

    public function __debugInfo(): array
    {
        return ['domain' => $this->domain, 'ownership_record' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Do not serialize private domain claims. Persist the public domain ID instead.');
    }
}

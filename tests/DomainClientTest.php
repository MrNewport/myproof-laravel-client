<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Tests;

use Illuminate\Http\Client\Factory;
use MyProof\LaravelClient\Data\DomainClaim;
use MyProof\LaravelClient\Data\DomainInput;
use MyProof\LaravelClient\Exceptions\ApiException;
use MyProof\LaravelClient\MyProofClient;
use Orchestra\Testbench\TestCase;

final class DomainClientTest extends TestCase
{
    private const ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private function domain(): array
    {
        return ['id' => self::ID, 'brand_id' => self::ID, 'hostname' => 'files.example.com', 'status' => 'pending',
            'claim_expires_at' => '2026-10-13T00:00:00Z', 'verified_at' => null, 'fresh_until' => null,
            'certificate_status' => null, 'provider_records' => [], 'error_code' => null, 'next_attempt_at' => '2026-10-12T00:00:00Z'];
    }

    private function claim(): array
    {
        return ['domain' => $this->domain(), 'ownership_record' => ['type' => 'TXT', 'name' => '_myproof.files.example.com', 'value' => 'myproof-domain='.str_repeat('f', 64)]];
    }

    public function test_claim_retries_exact_idempotent_body_and_debug_json_never_reveal_txt_secret(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fakeSequence()->pushStatus(503)->push(['data' => $this->claim()], 201)->push(['data' => $this->domain()]);
        $client = new MyProofClient($http, 'https://app.example.test', '1|'.str_repeat('a', 43));
        $claim = $client->claimDomain(new DomainInput(strtolower(self::ID), 'files.example.com'), 'stable-domain-claim');
        $this->assertSame($this->claim()['ownership_record'], $claim->ownershipRecord());
        $this->assertSame('pending', $claim->domain->status);
        $this->assertStringNotContainsString('myproof-domain=', json_encode($claim));
        $this->assertStringNotContainsString('myproof-domain=', print_r($claim, true));
        $this->assertNull($client->domain(self::ID)->certificateStatus);
        $requests = $http->recorded();
        $this->assertCount(3, $requests);
        $this->assertSame($requests[0][0]->body(), $requests[1][0]->body());
        $this->assertSame(['stable-domain-claim'], $requests[1][0]->header('Idempotency-Key'));
        $this->assertSame('https://app.example.test/api/v1/domains/'.self::ID, $requests[2][0]->url());
        $this->expectException(\LogicException::class);
        serialize($claim);
    }

    public function test_expired_recent_auth_is_not_retried_and_error_does_not_keep_private_body(): void
    {
        $http = new Factory;
        $http->fakeSequence()->push(['error' => ['code' => 'forbidden', 'message' => 'private-secret']], 423)->push(['data' => $this->claim()]);
        try {
            (new MyProofClient($http, 'https://app.example.test', '1|'.str_repeat('a', 43)))->claimDomain(new DomainInput(self::ID, 'files.example.com'), 'stable');
            $this->fail('Expired grant accepted');
        } catch (ApiException $failure) {
            $this->assertSame(423, $failure->status);
            $this->assertSame('forbidden', $failure->errorCode);
            $this->assertStringNotContainsString('private-secret', $failure->getMessage());
        }
        $http->assertSentCount(1);
    }

    public function test_validation_records_are_metadata_not_followed_and_private_fields_are_discarded(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $domain = [...$this->domain(), 'status' => 'provisioning', 'provider_records' => [
            ['type' => 'CNAME', 'name' => 'files.example.com', 'value' => 'actual.cloudfront.net'],
            ['type' => 'HTTP_VALIDATION', 'name' => 'https://files.example.com/challenge', 'value' => 'https://acm-validations.aws/challenge'],
        ], 'origin_secret' => 'unexpected-private'];
        $http->fakeSequence()->push(['data' => $domain]);
        $result = (new MyProofClient($http, 'https://app.example.test', '1|'.str_repeat('a', 43)))->domain(self::ID);
        $this->assertCount(2, $result->providerRecords);
        $this->assertStringNotContainsString('unexpected-private', json_encode($result));
        $http->assertSentCount(1);
    }

    public function test_bad_domain_inputs_never_send_and_malformed_claim_records_are_rejected(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake();
        $client = new MyProofClient($http, 'https://app.example.test', '1|'.str_repeat('a', 43));
        foreach (['https://files.example.com', "files.example.com\n", 'user@files.example.com'] as $host) {
            try {
                $client->claimDomain(new DomainInput(self::ID, $host), 'stable');
                $this->fail('Invalid hostname accepted');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $http->assertNothingSent();
        foreach ([['type' => 'TXT', 'name' => '_myproof.other.example.com', 'value' => 'myproof-domain='.str_repeat('f', 64)], ['type' => 'CNAME', 'name' => '_myproof.files.example.com', 'value' => 'wrong']] as $record) {
            try {
                DomainClaim::fromArray([...$this->claim(), 'ownership_record' => $record]);
                $this->fail('Invalid ownership record accepted');
            } catch (ApiException $failure) {
                $this->assertSame('invalid_response', $failure->errorCode);
            }
        }
    }
}

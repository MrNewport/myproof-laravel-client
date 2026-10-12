<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Tests;

use MyProof\Evidence\CanonicalJson;
use MyProof\LaravelClient\Exceptions\ApiException;
use MyProof\LaravelClient\Webhooks\NotificationVerifier;
use PHPUnit\Framework\TestCase;

final class NotificationVerifierTest extends TestCase
{
    private const ID = '01arz3ndektsv4rrffq69g5fav';

    private const NOW = 1791810000;

    private const SECRET = 'whsec_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function notice(): object
    {
        return (object) ['schema' => 'myproof.notification.v1', 'id' => self::ID, 'workspace_id' => self::ID, 'delivery_id' => self::ID, 'mode' => 'test', 'topic' => 'delivery.created',
            'occurred_at' => '2026-10-12T12:00:00.000000Z', 'recorded_at' => '2026-10-12T12:00:01.000000Z',
            'evidence' => (object) ['event_id' => self::ID, 'type' => 'order_snapshot_recorded', 'source' => 'app', 'sequence' => 1, 'sha256' => str_repeat('a', 64)], 'export' => null];
    }

    private function signed(object $notice, int $timestamp = self::NOW, string $id = self::ID)
    {
        $body = (new CanonicalJson)->encode($notice);

        return (new NotificationVerifier(self::SECRET, self::ID, 'test'))->verify($body, $id, (string) $timestamp, 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET), self::NOW);
    }

    public function test_verifies_exact_prefix_timestamp_raw_bytes_and_typed_notice(): void
    {
        $notice = $this->signed($this->notice());
        $this->assertSame(1, $notice->evidence->sequence);
        $this->assertSame('delivery.created', $notice->topic);
        $this->assertNull($notice->export);
        $this->assertSame($notice->id, $this->signed($this->notice(), self::NOW + 20)->id);
    }

    public function test_masked_export_notice_is_valid(): void
    {
        $input = $this->notice();
        $input->topic = 'export.ready';
        $input->evidence = null;
        $input->export = (object) ['id' => self::ID, 'status' => 'ready', 'privacy_mode' => 'masked'];
        $this->assertSame('masked', $this->signed($input)->export->privacyMode);
    }

    public function test_provider_payment_event_is_a_fixed_source_observation_notice(): void
    {
        $input = $this->notice();
        $input->topic = 'observation.added';
        $input->evidence->type = 'payment_provider_observed';
        $input->evidence->source = 'stripe';
        $this->assertSame('payment_provider_observed', $this->signed($input)->evidence->type);
        $input->evidence->source = 'merchant';
        $this->expectException(ApiException::class);
        $this->signed($input);
    }

    public function test_rejects_stale_future_timestamp_mismatched_header_and_payload_changes(): void
    {
        foreach ([self::NOW - 301, self::NOW + 301] as $timestamp) {
            try {
                $this->signed($this->notice(), $timestamp);
                $this->fail('Rejected clock window expected');
            } catch (ApiException) {
                $this->assertTrue(true);
            }
        }
        $mutators = [fn ($x) => $x->workspace_id = '01arz3ndektsv4rrffq69g5faw', fn ($x) => $x->mode = 'live', fn ($x) => $x->private = 'context',
            fn ($x) => $x->evidence->source = 'stripe', fn ($x) => $x->evidence->sequence = 0, fn ($x) => $x->topic = 'help.requested'];
        foreach ($mutators as $mutate) {
            $input = $this->notice();
            $mutate($input);
            try {
                $this->signed($input);
                $this->fail('Rejected closed payload expected');
            } catch (ApiException) {
                $this->assertTrue(true);
            }
        }
        $this->expectException(ApiException::class);
        $this->signed($this->notice(), self::NOW, '01arz3ndektsv4rrffq69g5faw');
    }

    public function test_rejects_tampered_signature_duplicate_keys_and_oversized_body(): void
    {
        $verifier = new NotificationVerifier(self::SECRET, self::ID, 'test');
        $body = (new CanonicalJson)->encode($this->notice());
        $cases = [[$body, 'v1='.str_repeat('0', 64)], ['{"id":"a","id":"b"}', null], [str_repeat('x', 8193), null]];
        foreach ($cases as [$bytes, $signature]) {
            $signature ??= 'v1='.hash_hmac('sha256', self::NOW.'.'.$bytes, self::SECRET);
            try {
                $verifier->verify($bytes, self::ID, (string) self::NOW, $signature, self::NOW);
                $this->fail('Invalid authentication expected');
            } catch (ApiException $failure) {
                $this->assertSame('invalid_notification', $failure->errorCode);
            }
        }
    }
}

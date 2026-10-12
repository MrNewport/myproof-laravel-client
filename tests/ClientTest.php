<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use MyProof\LaravelClient\Data\DeliveryInput;
use MyProof\LaravelClient\Data\OrderInput;
use MyProof\LaravelClient\Data\UploadInput;
use MyProof\LaravelClient\Exceptions\ApiException;
use MyProof\LaravelClient\MyProofClient;
use MyProof\LaravelClient\MyProofServiceProvider;
use Orchestra\Testbench\TestCase;

final class ClientTest extends TestCase
{
    private const ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    private Factory $http;

    private MyProofClient $client;

    protected function getPackageProviders($app): array
    {
        return [MyProofServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('myproof-client.origin', 'https://myproof.example.test');
        $app['config']->set('myproof-client.token', '12|'.str_repeat('t', 43));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = $this->app->make(Factory::class);
        $this->http->preventStrayRequests();
        $this->client = $this->app->make(MyProofClient::class);
    }

    private function page(array $rows): array
    {
        return ['data' => $rows, 'meta' => ['next_cursor' => null, 'limit' => 25]];
    }

    private function asset(): array
    {
        return ['id' => self::ID, 'name' => 'Guide', 'mode' => 'test'];
    }

    private function version(): array
    {
        return ['id' => self::ID, 'asset_id' => self::ID, 'mode' => 'test', 'status' => 'pending', 'size_bytes' => 4, 'mime' => null, 'sha256' => null, 'scan_status' => 'pending'];
    }

    private function delivery(): array
    {
        return ['id' => self::ID, 'order_id' => self::ID, 'brand_id' => self::ID, 'mode' => 'test', 'status' => 'active', 'reference' => 'opaque', 'access_mode' => 'email_code', 'head_sequence' => 1, 'head_hash' => str_repeat('a', 64), 'expires_at' => '2026-11-12T12:00:00Z', 'revoked_at' => null];
    }

    private function export(): array
    {
        return ['id' => self::ID, 'delivery_id' => self::ID, 'mode' => 'test', 'privacy_mode' => 'masked', 'status' => 'pending', 'head_sequence' => 1, 'head_hash' => str_repeat('a', 64), 'requested_at' => '2026-10-12T12:00:00Z', 'completed_at' => null, 'last_error_code' => null];
    }

    public function test_provider_boots_and_typed_pagination_preserves_origin_bearer_and_options(): void
    {
        $this->http->fake(function (Request $request, array $options) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertFalse($options['cookies']);
            $this->assertSame(20, $options['timeout']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertTrue($options['stream']);

            return $this->http->response($this->page([$this->asset()]));
        });
        $page = $this->client->assets();
        $this->assertSame('Guide', $page->items[0]->name);
        $this->assertNull($page->nextCursor);
        $this->http->assertSent(fn (Request $request) => $request->url() === 'https://myproof.example.test/api/v1/assets?limit=25' && $request->hasHeader('Authorization', 'Bearer 12|'.str_repeat('t', 43)));
    }

    public function test_upload_finalize_and_version_use_fixed_contract(): void
    {
        $this->http->fakeSequence()->push(['data' => ['session_id' => self::ID, 'asset_id' => self::ID, 'expires_at' => '2026-10-12T12:00:00Z', 'upload' => ['url' => 'https://s3.example.test', 'fields' => ['policy' => 'signed']]]], 201)
            ->push(['data' => $this->version()], 202)->push(['data' => $this->version()])->push($this->page([$this->version()]));
        $authorization = $this->client->beginUpload(new UploadInput('Guide', 'guide.txt', 4, 'text/plain', true), 'upload-1');
        $this->assertSame('signed', $authorization->fields()['policy']);
        $this->assertSame('https://s3.example.test', $authorization->url());
        $this->assertSame('pending', $this->client->finalizeUpload(self::ID, 'exact-version', 'finalize-1')->status);
        $this->assertSame(4, $this->client->version(self::ID)->sizeBytes);
        $this->assertCount(1, $this->client->versions(self::ID)->items);
        $this->http->assertSent(fn (Request $request) => str_ends_with($request->url(), '/finalize') && $request['version_id'] === 'exact-version' && $request->hasHeader('Idempotency-Key', 'finalize-1'));
        $this->assertStringNotContainsString('signed', json_encode($authorization));
    }

    public function test_order_delivery_and_export_methods_match_wire_contract(): void
    {
        $this->http->fakeSequence()->push(['data' => ['id' => self::ID, 'mode' => 'test', 'snapshot_sha256' => str_repeat('b', 64)]], 201)
            ->push(['data' => $this->delivery()], 201)->push(['data' => $this->delivery()])->push($this->page([$this->delivery()]))
            ->push(['data' => $this->export()], 202)->push(['data' => $this->export()]);
        $order = $this->client->createOrder(new OrderInput('ORD-1', 'Guide', 'buyer@example.test', 1200, 'JPY', 0, self::ID), 'order-1');
        $this->assertSame(self::ID, $order->id);
        $this->assertSame('active', $this->client->createDelivery(new DeliveryInput($order->id, self::ID), 'delivery-1')->status);
        $this->assertSame('test', $this->client->delivery(self::ID)->mode);
        $this->assertCount(1, $this->client->deliveries()->items);
        $this->assertSame('masked', $this->client->requestExport(self::ID, 'export-1')->privacyMode);
        $this->assertSame('pending', $this->client->export(self::ID)->status);
        $this->http->assertSent(fn (Request $request) => str_ends_with($request->url(), '/orders') && $request['asset_version_ids'] === [self::ID]);
        $this->http->assertSent(fn (Request $request) => str_ends_with($request->url(), '/exports') && $request->body() === '{}');
    }

    public function test_links_remain_explicit_and_never_trigger_a_followup_fetch(): void
    {
        $url = 'https://myproof.example.test/d/opaque#private-secret';
        $this->http->fakeSequence()->push(['data' => ['url' => $url, 'generation' => 1]])->push(['data' => ['url' => $url, 'generation' => 2]])
            ->push(['data' => ['id' => self::ID, 'status' => 'revoked']])->push(['data' => ['id' => self::ID, 'status' => 'revoked']])
            ->push(['data' => ['url' => 'https://storage.example.test/private?signature=secret', 'expires_in_seconds' => 120]]);
        $link = $this->client->revealDelivery(self::ID, 'reveal-1');
        $this->assertSame($url, $link->url());
        $this->assertSame(2, $this->client->rotateDelivery(self::ID, 'rotate-1')->generation);
        $this->assertSame('revoked', $this->client->revokeDelivery(self::ID, 'revoke-1')->status);
        $this->assertSame('revoked', $this->client->revokeUpload(self::ID, 'revoke-upload-1')->status);
        $download = $this->client->exportDownload(self::ID);
        $this->assertSame(120, $download->expiresInSeconds);
        $this->assertStringNotContainsString('secret', json_encode($download));
        $this->assertStringNotContainsString('private-secret', json_encode($link));
        $this->http->assertSentCount(5);
    }

    public function test_event_cursor_is_sequential_and_typed(): void
    {
        $this->http->fake(['*' => $this->http->response(['data' => [['id' => self::ID, 'sequence' => 3, 'type' => 'order_snapshot_recorded', 'source' => 'app', 'occurred_at' => '2026-10-12T12:00:00.000000Z', 'recorded_at' => '2026-10-12T12:00:00.000000Z', 'payload' => ['snapshot_sha256' => str_repeat('b', 64)], 'sha256' => str_repeat('a', 64)]], 'meta' => ['next_after_sequence' => 3, 'limit' => 1]])]);
        $page = $this->client->events(self::ID, 2, 1);
        $this->assertSame(3, $page->nextCursor);
        $this->assertSame('app', $page->items[0]->source);
    }

    public function test_idempotent_retry_reuses_exact_bytes_and_key(): void
    {
        $this->http->fakeSequence()->push(['error' => ['code' => 'temporarily_unavailable']], 503)->push(['data' => $this->export()], 202);
        $this->assertSame('pending', $this->client->requestExport(self::ID, 'stable-1')->status);
        $this->http->assertSentCount(2);
        foreach ($this->http->recorded() as [$request]) {
            $this->assertSame('{}', $request->body());
            $this->assertTrue($request->hasHeader('Idempotency-Key', 'stable-1'));
        }
    }

    public function test_conflict_does_not_retry_or_embed_raw_response(): void
    {
        $this->http->fake(['*' => $this->http->response(['error' => ['code' => 'idempotency_conflict', 'message' => 'private-provider-url']], 409)]);
        try {
            $this->client->rotateDelivery(self::ID, 'stable');
            $this->fail('Expected conflict');
        } catch (ApiException $failure) {
            $this->assertSame(409, $failure->status);
            $this->assertSame('idempotency_conflict', $failure->errorCode);
            $this->assertStringNotContainsString('private-provider-url', $failure->getMessage());
        }
        $this->http->assertSentCount(1);
    }

    public function test_redirect_is_not_followed_and_untrusted_error_text_is_discarded(): void
    {
        $this->http->fake(['*' => $this->http->response('private-secret', 302, ['Location' => 'https://attacker.example.test'])]);
        try {
            $this->client->assets();
            $this->fail('Expected redirect failure');
        } catch (ApiException $failure) {
            $this->assertSame(302, $failure->status);
            $this->assertStringNotContainsString('secret', $failure->getMessage());
        }
        $this->http->assertSentCount(1);
    }

    public function test_response_size_is_bounded(): void
    {
        $this->http->fake(['*' => $this->http->response(str_repeat('x', 2097153), 200, ['Content-Type' => 'application/json'])]);
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('response_too_large');
        $this->client->assets();
    }

    public function test_path_injection_and_missing_idempotency_key_never_send(): void
    {
        $this->http->fake();
        foreach ([fn () => $this->client->version('https://attacker.example.test'), fn () => $this->client->rotateDelivery(self::ID, ''), fn () => $this->client->assets(null, 51)] as $operation) {
            try {
                $operation();
                $this->fail('Expected local contract rejection');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->http->assertNothingSent();
    }

    public function test_configuration_rejects_plain_http_userinfo_and_path(): void
    {
        foreach (['http://myproof.example.test', 'https://user:pass@myproof.example.test', 'https://myproof.example.test/api', 'https://myproof.example.test?secret'] as $origin) {
            try {
                new MyProofClient($this->http, $origin, '12|'.str_repeat('t', 43));
                $this->fail('Expected configuration rejection');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_source_filter_preserves_cursor_and_empty_source_means_all(): void
    {
        $this->http->fake(fn () => $this->http->response(['data' => [], 'meta' => ['next_after_sequence' => null, 'limit' => 25]]));
        $this->client->events(self::ID, 7, 25, 'stripe');
        $this->client->events(self::ID, source: '');
        $requests = $this->http->recorded();
        parse_str(parse_url($requests[0][0]->url(), PHP_URL_QUERY), $query);
        $this->assertSame(['after_sequence' => '7', 'limit' => '25', 'source' => 'stripe'], $query);
        parse_str(parse_url($requests[1][0]->url(), PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('source', $query);
        foreach (['Stripe', 'stripe&workspace=other', 'arbitrary'] as $source) {
            try {
                $this->client->events(self::ID, source: $source);
                $this->fail('Invalid source accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->http->assertSentCount(2);
    }

    public function test_collection_metadata_and_exact_part_selection_do_not_fetch_the_sensitive_storage_url(): void
    {
        $private = 'https://storage.example.test/exact-version?private-signature';
        $this->http->fakeSequence()
            ->push(['data' => [...$this->export(), 'format' => 'collection.v1', 'segment_count' => 3, 'status' => 'complete']])
            ->push(['data' => ['url' => $private, 'filename' => 'collection.zip', 'expires_in_seconds' => 119]])
            ->push(['data' => ['url' => $private, 'filename' => 'segment-0002.zip', 'expires_in_seconds' => 10]]);
        $export = $this->client->export(self::ID);
        $this->assertSame('collection.v1', $export->format);
        $this->assertSame(3, $export->segmentCount);
        $manifest = $this->client->exportDownload(self::ID);
        $part = $this->client->exportDownload(self::ID, 2);
        $this->assertSame('collection.zip', $manifest->filename);
        $this->assertSame('segment-0002.zip', $part->filename);
        $this->assertSame(10, $part->expiresInSeconds);
        $this->assertSame($private, $part->url());
        $this->assertStringNotContainsString('private-signature', json_encode($part));
        $requests = $this->http->recorded();
        $this->assertStringNotContainsString('part=', $requests[1][0]->url());
        $this->assertSame('https://myproof.example.test/api/v1/exports/'.self::ID.'/download?part=2', $requests[2][0]->url());
        $this->http->assertSentCount(3);
    }

    public function test_old_export_and_link_responses_remain_compatible_and_new_access_expiry_is_available(): void
    {
        $this->http->fakeSequence()->push(['data' => $this->export()])
            ->push(['data' => ['id' => self::ID, 'url' => 'https://buyer.example.test/d/opaque#secret', 'generation' => 2, 'expires_at' => '2026-12-01T00:00:00Z']])
            ->push(['data' => ['id' => self::ID, 'status' => 'revoked', 'residual_url_seconds' => 120]]);
        $old = $this->client->export(self::ID);
        $this->assertSame('v2', $old->format);
        $this->assertSame(0, $old->segmentCount);
        $link = $this->client->revealDelivery(self::ID, 'reveal-expiry');
        $this->assertSame(self::ID, $link->id);
        $this->assertSame('2026-12-01T00:00:00Z', $link->expiresAt);
        $this->assertSame(120, $this->client->revokeDelivery(self::ID, 'revoke-residual')->residualUrlSeconds);
    }

    public function test_collection_bounds_reject_before_network_and_malformed_metadata_is_not_silently_downgraded(): void
    {
        $this->http->fakeSequence()
            ->push(['data' => [...$this->export(), 'format' => 'unrecognized', 'segment_count' => 1]])
            ->push(['data' => [...$this->export(), 'format' => 'collection.v1', 'segment_count' => 1025]])
            ->push(['data' => ['url' => 'https://storage.example.test', 'filename' => '../private.zip', 'expires_in_seconds' => 120]])
            ->push(['data' => ['url' => 'https://storage.example.test', 'filename' => 'safe.zip', 'expires_in_seconds' => 121]]);
        foreach ([0, 1025, -1] as $part) {
            try {
                $this->client->exportDownload(self::ID, $part);
                $this->fail('Invalid part accepted.');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->http->assertNothingSent();
        foreach ([fn () => $this->client->export(self::ID), fn () => $this->client->export(self::ID), fn () => $this->client->exportDownload(self::ID), fn () => $this->client->exportDownload(self::ID)] as $operation) {
            try {
                $operation();
                $this->fail('Invalid response accepted.');
            } catch (ApiException $failure) {
                $this->assertSame('invalid_response', $failure->errorCode);
            }
        }
        $this->http->assertSentCount(4);
    }

    public function test_collection_get_retry_preserves_selected_part_and_exhaustion_is_bounded(): void
    {
        $this->http->fakeSequence()->pushStatus(503)->pushStatus(503);
        try {
            $this->client->exportDownload(self::ID, 1024);
            $this->fail('Failure expected.');
        } catch (ApiException $failure) {
            $this->assertSame(503, $failure->status);
        }
        $requests = $this->http->recorded();
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0][0]->url(), $requests[1][0]->url());
        $this->assertStringEndsWith('?part=1024', $requests[0][0]->url());
    }
}

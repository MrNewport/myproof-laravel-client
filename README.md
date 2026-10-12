# MyProof Laravel client

Typed client for the implemented `/api/v1` delivery API. PHP 8.3+, Laravel 12/13. Tagged releases depend on the stable `myproof/evidence` package. Repository tags establish released versions; no Packagist listing is assumed.

For installation from the public repositories, configure both repositories in your consuming application before selecting a released version:

```sh
composer config repositories.myproof-evidence vcs https://github.com/MrNewport/myproof-evidence
composer config repositories.myproof-client vcs https://github.com/MrNewport/myproof-laravel-client
composer require myproof/laravel-client:^1.0
```

The application monorepo's development copy uses an adjacent evidence path repository. Standalone releases remove that development binding; Composer consumers resolve the released evidence dependency through their own configured repository.

Register `MyProofServiceProvider` through Laravel package discovery, set `MYPROOF_API_ORIGIN` to the configured primary HTTPS origin (no path) and `MYPROOF_API_TOKEN` to a scoped token issued in the seller browser. Resolve `MyProofClient` from the container. Never expose the token to a buyer or frontend bundle.

```php
use MyProof\LaravelClient\MyProofClient;
use MyProof\LaravelClient\Data\OrderInput;
use MyProof\LaravelClient\Data\DeliveryInput;

$client = app(MyProofClient::class);
// Persist these request keys with your job before the first attempt.
$order = $client->createOrder(new OrderInput(
    reference: 'ORD-100', title: 'Digital guide', buyerEmail: $buyerEmail,
    amountMinor: 1200, currency: 'USD', currencyExponent: 2,
    assetVersionId: $readyVersionId,
), $orderRequestKey);
$delivery = $client->createDelivery(new DeliveryInput(
    orderId: $order->id, brandId: $brandId,
), $deliveryRequestKey);
$link = $client->revealDelivery($delivery->id, $revealRequestKey);
// Explicitly handle $link->url(); never log or persist it in browser history.
```

Upload flow: `beginUpload(UploadInput, key)` returns a typed `UploadAuthorization`. POST file bytes and its exact `fields()` directly to `url()` without the API bearer. Obtain the S3 version ID from the provider response; call `finalizeUpload(sessionId, exactVersionId, key)`. Poll `version(versionId)` until readiness; pending/quarantined versions cannot be ordered. The client never proxies file bytes through MyProof.

Other methods: `assets`, `versions`, `deliveries`, `delivery`, `events`, `rotateDelivery`, `revokeDelivery`, `revokeUpload`, `requestExport`, `export`, `exportDownload`. Lists return typed `Page` objects with `items`, `nextCursor`, `limit`. Events use sequence cursors. `events($deliveryId, afterSequence: 0, limit: 25, source: 'stripe')` filters a source without renumbering or reordering late observations. Sources are `app`, `merchant`, `stripe`, `ses`, `cloudfront`, `client`; null/empty means all. This timeline is not independent cryptographic verification. Event payloads may include provider account/payment IDs: keep them out of browser history, analytics and logs. Exports are masked only. `exportDownload` returns a `DownloadLink`; fetching that URL is an explicit separate operation without the API bearer. `EvidenceExport::format` is `v2` or `collection.v1`; `segmentCount` is zero for a single bundle or before collection segments are available. Only a complete collection is downloadable. `exportDownload($id)` selects its signed collection index archive; `exportDownload($id, part: 2)` selects one exact segment (1–1024). `DownloadLink::filename` is a bounded safe base name and `expiresInSeconds` may be less than 120 near retention expiry. The SDK neither downloads archives nor follows returned links.

The transport uses Laravel HTTP/Guzzle with a 20-second request timeout, 5-second connection timeout and two attempts by default (configurable within hard bounds of 60 seconds/three attempts). It retries connection failures before a response or HTTP 429/502/503/504 only for GET or the fixed POST methods that require an idempotency key, preserving exact JSON and key. It never follows redirects. Failures such as 401/403/409/422 are not retried. `ApiException` exposes bounded status/code/request ID; it does not retain raw response, URL, bearer or provider exception. A network failure does not prove the server rolled back: keep the same key. Expired replay records fail with 409.

Success reads are streamed with a 2 MiB bound. Credentials target one fixed HTTPS origin, resource identifiers cannot inject paths, cookie jars are disabled, and no arbitrary request/path method is exposed. Host applications remain responsible for disabling HTTP request/response telemetry for this client; framework listeners or debugging tools may observe sensitive requests. Do not dump client requests, link results or raw provider form fields.

## Webhook receiver

```php
use MyProof\LaravelClient\Webhooks\NotificationVerifier;

$verifier = new NotificationVerifier($wholeWhsecSecret, $expectedWorkspaceId, 'live');
$notice = $verifier->verify(
    $request->getContent(),
    (string) $request->header('MyProof-Event-Id'),
    (string) $request->header('MyProof-Timestamp'),
    (string) $request->header('MyProof-Signature'),
);
// Transactionally persist a unique (workspaceId, notice.id) receipt before effects.
// Retries retain notice.id and carry fresh transport timestamps.
```

The verifier uses the entire returned `whsec_...` value as the HMAC key, not a hex-decoded key. It authenticates `timestamp + '.' + exact raw body`, requires a five-minute window in both directions, checks header/body event ID agreement, and then uses the shared evidence package to require bounded canonical JSON and a closed typed payload. Current envelopes are at most 8192 bytes. Workspace and mode are pinned by receiver configuration. Topics are `delivery.created`, `observation.added`, `help.requested`, `export.ready`; exported notices expose only masked readiness metadata. The verifier authenticates each delivery attempt; durable duplicate suppression is the receiver's responsibility. Maintain a reliable receiver clock. No notification establishes buyer identity, complete receipt, or an independent witness.

The server enforces token workspace, current role, expiry, idempotency and exact resource ownership. Each order currently captures exactly one ready immutable file version. There is no API endpoint for arbitrary evidence append or full-private exports. Run `composer test` in this directory. Application feature tests also decode real local API responses through this client, separately from transport fakes. No mock result is live provider verification.

See [the release checklist](docs/release.md) and [package-only manifest](release-manifest.json) before publication. License: [MIT](LICENSE).

## Independent export verification

For `v2`, fetch the explicitly returned URL with a separate unauthenticated HTTP client, bound the download and safely unpack the ZIP, then run `vendor/bin/myproof-verify --bundle=DIR --public-key=PINNED_HEX_FILE --key-id=ID --mode=live|test|demo`.

For `collection.v1`, fetch the collection index archive and every selected segment via `exportDownload($id, part: $index)`, preserve the verifier's expected relative file layout, and use `--collection=DIR` in place of `--bundle`. Independently pin the public key, key ID and mode outside the downloaded directory. An included public key is not a trust anchor. The SDK metadata and an authenticated webhook do not verify archive signatures or claim that a buyer read a file.

## Owner-authorized customer domains

`claimDomain(new DomainInput($brandId, 'files.example.com'), $stableKey)` calls `POST /api/v1/domains` and returns `DomainClaim`. Read its public `domain` metadata or explicitly access `ownershipRecord()` for the private TXT record. The record is omitted from JSON/debug output, and PHP serialization of the claim is refused. Do not place the TXT value in logs, jobs or browser history. Publish the exact returned DNS record yourself; this client never changes DNS.

Poll `domain($claim->domain->id)` for `pending`, `provisioning`, `active`, `suspended`, `removing` or `removed`. `providerRecords` contains only the actual provider-returned CNAME/HTTP validation instructions (at most two), never an assumed universal target. Pending certificate/error/date fields can be null. URLs in validation records are metadata and are never fetched by the SDK. Activation still requires ownership, issued TLS, correct routing and the application's authenticated HTTPS probe.

Both scopes are owner-only and always intersect current persisted membership: `domains:read` polls status; `domains:write` creates claims. A browser-confirmed password grants domain creation only until the original confirmation time plus five minutes, even if the token itself lasts longer. Existing tokens have no domain-write grant. On HTTP 423 / `forbidden`, return to the seller browser, confirm the password and save the token's permissions to renew the grant. Do not keep retrying an expired grant. A different owner's confirmation cannot refresh someone else's token. Changed payloads, expired/removed claims or incompatible replay state fail with 409; repeated requests keep the same idempotency key. Retry/removal and challenge re-reveal remain explicit seller-browser actions; this package does not invent additional API routes.

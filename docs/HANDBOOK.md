# MyProof Laravel Client handbook

`myproof/laravel-client` is a typed Laravel SDK for the implemented scoped MyProof delivery API. It maps fixed routes to typed inputs/results, bounds transport data, preserves stable idempotency keys and verifies notification envelopes. It does not host the seller application, upload/download file bytes, change DNS or independently verify an evidence archive merely by reading API metadata.

This handbook describes the public1.0 source and its fixture tests. The separate private MyProof application is not part of this package.

## Install and configure

Requirements are PHP8.3+, Laravel12/13, Guzzle7.9+ and `myproof/evidence:^1.0`, including that package's JSON/mbstring/Sodium requirements. Collection archive verification separately needs ext-zip.

```sh
composer config repositories.myproof-evidence vcs https://github.com/MrNewport/myproof-evidence
composer config repositories.myproof-client vcs https://github.com/MrNewport/myproof-laravel-client
composer require myproof/laravel-client:^1.0
php artisan vendor:publish --tag=myproof-client-config
```

Configure both VCS sources when the public tags are not available through your package registry. Composer discovery registers `MyProofServiceProvider`; configuration publishing is optional. The package adds no public routes, migrations, scheduler or account system.

```dotenv
MYPROOF_API_ORIGIN=https://your-configured-primary-origin.example
MYPROOF_API_TOKEN=your-scoped-server-token
```

| Configuration | Default | Admission bounds |
| --- | --- | --- |
| `origin` | `MYPROOF_API_ORIGIN` | Fixed HTTPS origin with no path beyond `/`, credentials, query or fragment |
| `token` | `MYPROOF_API_TOKEN` | Scoped token shape validated by the constructor |
| `timeout_seconds` |20 |1–60 seconds |
| `connect_timeout_seconds` |5 |1 through request timeout |
| `max_attempts` |2 |1–3 total attempts |

Resolve `MyProofClient` from the container after configuration is loaded. The singleton captures its origin/token when constructed. Separate tenant credentials or origins require deliberate host bindings; never take a token or target origin from an untrusted request.

## First order and delivery

```php
use MyProof\LaravelClient\MyProofClient;
use MyProof\LaravelClient\Data\OrderInput;
use MyProof\LaravelClient\Data\DeliveryInput;

$client = app(MyProofClient::class);

// Persist keys with the authorized operation before the first request.
$order = $client->createOrder(new OrderInput(
    reference: 'ORD-100',
    title: 'Workshop guide',
    buyerEmail: $authorizedBuyerEmail,
    amountMinor: 1200,
    currency: 'USD',
    currencyExponent: 2,
    assetVersionId: $readyImmutableVersionId,
), $stableOrderKey);

$delivery = $client->createDelivery(new DeliveryInput(
    orderId: $order->id,
    brandId: $authorizedBrandId,
), $stableDeliveryKey);
```

Each order captures exactly one immutable asset version. `OrderInput::toArray()` maps that one ID to the one-element `asset_version_ids` list. It does not support an arbitrary bundle of versions. The server enforces readiness, ownership, role, scopes, expiry and business validation; input DTOs are mappings, not a replacement for those checks.

`amountMinor` is an integer interpreted with the explicit currency exponent. The SDK does not assume cents or supply application pricing policy.

## Input DTOs

| Input | Constructor fields |
| --- | --- |
| `UploadInput` | name,filename,sizeBytes,mime,rightsAcknowledged,optional assetId |
| `OrderInput` | reference,title,buyerEmail,amountMinor,currency,currencyExponent,assetVersionId |
| `DeliveryInput` | orderId,brandId,accessMode=`email_code`,expiresInDays=30 |
| `DomainInput` | brandId,hostname |

DTO `toArray()` methods produce the fixed API field names. `DomainInput` additionally validates a brand ULID and an ASCII hostname up to244 bytes. Host authorization and the server's complete field rules still apply.

`OrderInput` has a public buyer-email property even though debug output redacts it. Do not JSON-encode or log input DTOs containing personal data.

## Upload workflow

```php
use MyProof\LaravelClient\Data\UploadInput;

$authorization = $client->beginUpload(new UploadInput(
    name: 'Workshop guide',
    filename: 'guide.pdf',
    sizeBytes: $reviewedSize,
    mime: 'application/pdf',
    rightsAcknowledged: true,
), $stableUploadKey);
```

1. Explicitly access `UploadAuthorization::url()` and `fields()` only where needed.
2. POST the file directly to that returned storage URL with its exact fields, using a separate upload transport and **no MyProof bearer**.
3. Obtain the exact storage object version ID from the provider response.
4. Call `finalizeUpload($sessionId, $exactObjectVersionId, $stableFinalizeKey)`.
5. Poll `version($assetVersionId)` until the server reports readiness. Pending/quarantined versions cannot be ordered.

The SDK neither proxies file bytes nor automatically follows an upload URL. It does not scan a file or manufacture a storage version ID. `revokeUpload()` is the explicit API revocation operation.

## Complete route/method reference

All API requests use the configured origin plus `/api/v1` and a fixed route. Resource IDs cannot inject paths.

| SDK method | HTTP route | Result |
| --- | --- | --- |
| `beginUpload(UploadInput,key)` | POST `/uploads` | `UploadAuthorization` |
| `finalizeUpload(uploadId,objectVersionId,key)` | POST `/uploads/{id}/finalize` | `AssetVersion` |
| `revokeUpload(uploadId,key)` | POST `/uploads/{id}/revoke` | `Revocation` |
| `assets(cursor=null,limit=25)` | GET `/assets` | `Page<Asset>` |
| `versions(assetId,cursor=null,limit=25)` | GET `/assets/{id}/versions` | `Page<AssetVersion>` |
| `version(versionId)` | GET `/asset-versions/{id}` | `AssetVersion` |
| `createOrder(OrderInput,key)` | POST `/orders` | `Order` |
| `createDelivery(DeliveryInput,key)` | POST `/deliveries` | `Delivery` |
| `deliveries(cursor=null,limit=25)` | GET `/deliveries` | `Page<Delivery>` |
| `delivery(deliveryId)` | GET `/deliveries/{id}` | `Delivery` |
| `revealDelivery(deliveryId,key)` | POST `/deliveries/{id}/reveal` | `AccessLink` |
| `rotateDelivery(deliveryId,key)` | POST `/deliveries/{id}/rotate` | `AccessLink` |
| `revokeDelivery(deliveryId,key)` | POST `/deliveries/{id}/revoke` | `Revocation` |
| `events(deliveryId,afterSequence=0,limit=25,source=null)` | GET `/deliveries/{id}/events` | `Page<EvidenceEvent>` |
| `requestExport(deliveryId,key)` | POST `/deliveries/{id}/exports` | `EvidenceExport` |
| `export(exportId)` | GET `/exports/{id}` | `EvidenceExport` |
| `exportDownload(exportId,part=null)` | GET `/exports/{id}/download` | `DownloadLink` |
| `claimDomain(DomainInput,key)` | POST `/domains` | `DomainClaim` |
| `domain(domainId)` | GET `/domains/{id}` | `Domain` |

There is no public arbitrary request/path method, evidence-append method, full-private export request, DNS-change method or invented domain-removal endpoint.

## Pagination and event order

List page sizes are1–50. Resource/list cursor arguments must be ULIDs; they are not arbitrary numbers or URLs. `Page` exposes `items`, `nextCursor` and `limit` and refuses non-list or oversized result lists.

```php
$page = $client->deliveries(limit: 25);
if ($page->nextCursor !== null) {
    $next = $client->deliveries($page->nextCursor, 25);
}
```

Events use an integer sequence cursor rather than a list ULID cursor:

```php
$page = $client->events($deliveryId, afterSequence: 0, limit: 25, source: 'stripe');
```

The cursor is nonnegative and bounded to the shared safe-integer range. Sources are `app`, `merchant`, `stripe`, `ses`, `cloudfront` and `client`; null/empty means all. Filtering does not renumber or reorder late observations. An event's occurrence time can precede an earlier committed sequence.

A typed event timeline is API data, not independent archive signature/chain verification. Provider account/payment IDs may appear in event payloads; keep them out of logs, analytics and browser history.

## Stable request keys and retries

Every public POST method requires a stable idempotency key of1–128 characters from letters,digits,period,underscore,colon and hyphen. Choose it once per logical operation and persist it before sending. The same key and exact payload must survive uncertain responses and worker retries.

The transport serializes request JSON once as an object and reuses those bytes/key. Maximum serialized request size is64KiB. GET requests use query parameters. Redirects and cookie jars are disabled.

It retries connection failures before a response and HTTP429,502,503 and504, within `max_attempts` total attempts. Fixed POST routes are safe for this retry policy because they require request keys. HTTP401,403,409 and422 are not retried. The short backoff is100ms multiplied by the attempt index, capped at500ms; the implementation does not process `Retry-After`.

A response-stream read failure becomes `connection_failed`; it is not a fresh automatic resend after partially consuming a response. A network failure does not establish whether the server committed. Keep the same key and inspect current state. The server can reject changed payloads, expired replay records or incompatible state with409.

Durable job leases, duplicate effect suppression, authorized payload construction and recovery records remain host responsibilities.

## Bounded response and error contract

Responses are read as streams with a2MiB ceiling, regardless of a missing/misleading `Content-Length`. Streams are closed. Success must have JSON content type and decode to the expected typed shape. Typed decoders require their declared scalar/array types and apply additional limits for sensitive/domain/download results.

`ApiException` exposes only:

```php
$exception->status;
$exception->errorCode;
$exception->requestId;
```

The error code is selected from a closed allowed vocabulary or replaced with `unexpected_response`. Invalid provider data produces `invalid_response` or `response_too_large`; exhausted transport failures use status0/`connection_failed`. Exceptions do not retain the raw response, bearer, URL or provider exception chain.

Catch the exception at the host's operation boundary, show an appropriate bounded message and preserve the stable request key for recovery. Constructor/input mistakes raise `InvalidArgumentException` before transport.

The SDK cannot prevent application-level HTTP listeners or debugging tools from observing a request. Disable request/response telemetry for this credentialed client and protect the host's logs.

## Sensitive result objects

| Result | Explicit sensitive accessor | Default display behavior |
| --- | --- | --- |
| `UploadAuthorization` | `url()`, `fields()` | Private properties omitted from ordinary JSON; debug redacts authorization |
| `AccessLink` | `url()` | Private URL omitted from ordinary JSON; debug redacts |
| `DownloadLink` | `url()` | Private URL omitted from ordinary JSON; debug redacts |
| `DomainClaim` | `ownershipRecord()` | Private TXT record omitted; serialization explicitly refused |

Default omission is not permission to serialize these objects into jobs or store them in browser history. Access only where the workflow needs the value, and persist public IDs instead. Explicit accessors return sensitive data deliberately.

## Delivery links and evidence exports

`revealDelivery()` and `rotateDelivery()` return an `AccessLink`; the SDK never opens that link. Rotation and revocation are explicit server operations, not local changes to a URL string.

Exports are masked. `EvidenceExport::format` identifies `v2` or `collection.v1`; `segmentCount` is zero for a single bundle or before collection segments are ready. Only a complete ready collection is downloadable under the server contract.

```php
$export = $client->requestExport($deliveryId, $stableExportKey);
$current = $client->export($export->id);
$link = $client->exportDownload($export->id);
$partLink = $client->exportDownload($export->id, part: 2);
```

`part` is1–1,024. A `DownloadLink` supplies a safe bounded base filename and an expiry of at most120 seconds, possibly shorter near retention expiry. The SDK does not download an archive or forward its API bearer to storage.

For independent verification, use a separate unauthenticated bounded downloader, safe archive handling and `myproof/evidence` with an independently pinned key outside the export. Use `--bundle` for v2 and `--collection` for a complete index plus every numbered segment. An authenticated API call or notification does not verify those archive bytes.

## Owner-authorized custom domains

```php
use MyProof\LaravelClient\Data\DomainInput;

$claim = $client->claimDomain(
    new DomainInput($authorizedBrandId, 'files.example.com'),
    $stableClaimKey,
);
$domainId = $claim->domain->id;
$txt = $claim->ownershipRecord(); // Private; deliver only through the authorized owner flow.
$current = $client->domain($domainId);
```

`DomainClaim` validates an exact `_myproof.{hostname}` TXT record with the documented `myproof-domain=` value format. It never writes DNS. Publish the returned record through your own authorized DNS process.

Domain states are `pending`, `provisioning`, `active`, `suspended`, `removing` and `removed`. Provider validation metadata contains at most two actual CNAME/HTTP-validation records, not a presumed universal target. Validation URLs remain data and are never fetched by the SDK. Nullable lifecycle/certificate/error fields can be absent pending provisioning.

The server contract requires owner-only `domains:read`/`domains:write` scopes intersected with current membership. Domain creation also requires a recent browser password confirmation and renewed token permission grant; an older token does not gain domain-write access automatically. On423/forbidden, return to the owner browser to complete that flow instead of retrying indefinitely.

Activation depends on actual ownership, issued TLS, routing and the server's authenticated HTTPS probe. Retry/removal/challenge re-reveal remain explicit seller-browser actions; the client exposes no additional lifecycle endpoint.

## Notification verification

The package does not register a webhook route. The host supplies its receiver, body-size limit, clock policy and durable receipt transaction.

```php
use MyProof\LaravelClient\Webhooks\NotificationVerifier;

$verifier = new NotificationVerifier($wholeWhsecSecret, $expectedWorkspaceId, 'test');
$notice = $verifier->verify(
    $request->getContent(),
    (string) $request->header('MyProof-Event-Id'),
    (string) $request->header('MyProof-Timestamp'),
    (string) $request->header('MyProof-Signature'),
);
```

Use the **entire** `whsec_...` secret string as the HMAC key. Do not hex-decode its suffix. Signing input is the timestamp, one literal period and the exact raw body. Verify before decoding/re-encoding the body.

The verifier requires:

- At most8,192 body bytes and a valid `v1=` SHA-256 HMAC.
- A timestamp within300 seconds in either direction, using the trusted receiver clock.
- Bounded canonical JSON with the closed `myproof.notification.v1` shape.
- Matching header/body event IDs, pinned workspace/mode and typed source/topic payloads.

Topics are `delivery.created`, `observation.added`, `help.requested` and `export.ready`. Export notifications expose only masked readiness metadata. Invalid signatures, stale/future attempts, substituted scope, duplicate JSON keys and invalid closed payloads become `ApiException(401, 'invalid_notification')`.

After verification, transactionally persist a unique `(workspaceId, notice.id)` receipt before applying effects. Retries retain the notice ID and carry fresh transport timestamps. HMAC authentication does not itself provide durable duplicate suppression, prove a buyer's identity or verify a complete evidence export.

The optional `$now` argument is for an application-controlled testing clock, never a caller-supplied request timestamp.

## Fully local fixture recipe

```php
use Illuminate\Http\Client\Factory;
use MyProof\LaravelClient\MyProofClient;

$http = new Factory;
$http->preventStrayRequests();
$http->fake([
    'https://myproof.example.test/api/v1/assets*' => $http->response([
        'data' => [[
            'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'name' => 'Fictional guide',
            'mode' => 'test',
        ]],
        'meta' => ['next_cursor' => null, 'limit' => 5],
    ]),
]);
$client = new MyProofClient(
    $http,
    'https://myproof.example.test',
    '12|'.str_repeat('t', 43), // Public fictional fixture value, never a real credential.
);
$page = $client->assets(limit: 5);
```

Use exact fake endpoints and `preventStrayRequests()` to fail on unexpected network attempts. Add response sequences to inspect transient retries,409 non-retry behavior and malformed typed responses. Keep live credentials out of the test environment.

## Tests and adoption

```sh
composer install
composer test
```

`ClientTest`, `DomainClientTest` and `NotificationVerifierTest` cover fixed routes, typed decoding, credential/path boundaries, response streaming limits, request-key retries, sensitive result handling, domain metadata and notification authentication. These local contracts do not claim live storage/DNS/provider acceptance.

The host owns authentication, workspace policy, token issuance/storage/revocation, operation persistence, upload/download transport, DNS changes, clock reliability, notification receipts, outboxes and evidence trust pins. See [the feature inventory](FEATURES.md), [release checklist](release.md) and [public client source](../src/MyProofClient.php).

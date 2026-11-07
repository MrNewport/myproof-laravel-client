# MyProof Laravel Client feature inventory

| Capability | Implemented API | Host/server boundary |
| --- | --- | --- |
| Laravel discovery/configuration | `MyProofServiceProvider` | No public routes or migrations |
| Fixed HTTPS origin and scoped token | `MyProofClient` constructor | Trusted configuration and token lifecycle |
| Closed resource/path methods | Public SDK methods | No arbitrary URL/path request surface |
| Typed upload authorization/finalization | `beginUpload`, `finalizeUpload`, `revokeUpload` | Separate direct-to-storage upload, exact provider version |
| Typed asset/version reads | `assets`, `versions`, `version` | Ownership/readiness enforced by server |
| One-version order creation | `OrderInput`, `createOrder` | Host pricing/buyer policy; server validates ownership |
| Delivery lifecycle and sensitive links | create/read/reveal/rotate/revoke methods | Host handles link values explicitly |
| Bounded list pagination | `Page`, limit1–50, ULID list cursors | Host iteration/job budget |
| Source-filtered event timeline | `events` with sequence cursor | Timeline is not independent archive verification |
| Masked export requests/metadata | `requestExport`, `export` | Server readiness/retention policy |
| v2/collection download metadata | `exportDownload`, optional part1–1,024 | Separate bounded unauthenticated downloader/verifier |
| Owner domain claim/poll | `claimDomain`, `domain` | Recent owner grant, DNS/TLS/probe belong to server/host |
| Private domain TXT access | `DomainClaim::ownershipRecord()` | No DNS mutation; serialization refused |
| Stable-key retry transport | Fixed POST methods plus GET | Host persists keys and resolves uncertain effects |
| Request/response byte ceilings |64KiB requests,2MiB streamed responses | No unlimited archive transport |
| Redirect/cookie refusal | HTTP pending request options | Disable other HTTP telemetry too |
| Bounded typed failures | `ApiException` | No raw request/response/previous exception retained |
| Sensitive result default omission | Private link/form/TXT fields | Explicit accessors still reveal sensitive values |
| Raw-body HMAC notifications | `NotificationVerifier` | Host route, trusted clock and durable dedupe receipts |
| Shared canonical/closed evidence types | `myproof/evidence` | Independent key/chain verification remains explicit |
| Intercepted transport and notification tests | `tests/*` | No live provider/cloud claim |

The SDK does not append arbitrary evidence, provide full-private API exports, follow returned storage links, upload/download files, change DNS, issue tokens or guarantee receipt/identity/dispute outcomes. Read the [handbook](HANDBOOK.md) for every method, transport bound and integration recipe.

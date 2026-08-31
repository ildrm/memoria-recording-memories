# Deployment and operations

This how-to guide targets operators deploying Memoria with PostgreSQL, Redis, private object storage, workers, a scheduler, TLS, email, monitoring, and automated backups.

## Production topology

- TLS-terminating reverse proxy/load balancer
- immutable PHP 8.5 application image behind PHP-FPM or an equivalent managed runtime
- PostgreSQL 17 with encrypted storage and point-in-time recovery
- Redis 8 for cache, sessions, locks, and queues
- at least one general worker and separately scalable `social`/`exports` workers
- one scheduler invocation per minute or one `schedule:work` process
- private S3-compatible storage with distinct boundaries for originals, sanitized derivatives, and exports
- ClamAV (or an equivalent future scanner adapter) with continuously updated signatures
- transactional email provider
- uptime/error/worker monitoring with privacy-safe scrubbing

## Build the release

```bash
docker build --target production -t memoria:release .
```

The image installs production Composer dependencies, builds Vite assets in a Node stage, and runs as an unprivileged user. Put a production web server or managed runtime in front of the PHP-FPM target; the local Compose `artisan serve` command is not the production server.

## Configure secrets

Provide environment values through the platform's secret manager. Do not bake `.env` into the image.

Required groups:

- `APP_KEY`, canonical HTTPS `APP_URL`, locale/timezone, and `APP_DEBUG=false`
- a per-release `LIVEWIRE_RELEASE_TOKEN` such as the immutable Git commit SHA or image digest
- distinct `MEMORIA_PRIVACY_NOTICE_URL` and `MEMORIA_TERMS_OF_SERVICE_URL` values pointing to operator-reviewed public HTTPS documents
- PostgreSQL credentials with TLS requirements
- Redis connection and `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`
- `SESSION_SECURE_COOKIE=true`, HTTP-only, SameSite policy, and an explicit comma-separated `TRUSTED_PROXIES` allowlist containing only platform-controlled proxy IPs/CIDRs
- mail transport/from identity
- private object storage credentials and distinct bucket/prefix values for originals, sanitized derivatives, and exports
- `MEMORIA_ATTACHMENT_SCANNER=clamav`, an executable `MEMORIA_CLAMAV_BINARY`, and a bounded scan timeout
- OAuth client IDs/secrets with exact production callback URLs
- `MEMORIA_SOCIAL_DRIVER=disabled`, or `real` with version pins, credentials, callbacks, and approved scopes only for adapters actually enabled
- an infrastructure or application monitoring integration configured outside Memoria with body/header scrubbing; the application does not ship a vendor-specific DSN client

Keep `APP_KEY` in a separately backed-up secret store. Losing it makes encrypted TOTP/OAuth values unreadable; exposing it compromises them.

`LIVEWIRE_RELEASE_TOKEN` is a public deployment identifier embedded in Livewire browser snapshots, not a secret. Give every process running the same artifact the same value, change it whenever application code changes, and never derive it from `APP_KEY`. During a rolling release, old processes keep the old token until they are drained while the new artifact receives its new token; do not reuse one token across different application revisions. Run `php artisan optimize` only after the release environment has injected the final value so the configuration cache contains the correct token.

## Enable real social delivery

Keep `MEMORIA_SOCIAL_DRIVER=fake` in local/test environments. A production deployment that has completed provider review can opt in explicitly:

```dotenv
MEMORIA_SOCIAL_DRIVER=real
MEMORIA_LINKEDIN_VERSION=202606
MEMORIA_FACEBOOK_GRAPH_VERSION=v25.0
```

Before enabling it:

1. Register each exact HTTPS callback shown in `.env.example`; store client IDs/secrets in the platform secret manager and request only the documented publishing scopes.
2. Verify the provider application, consent screen, account type, publishing permission, rate limits, and deletion/revocation procedure in staging. The app never asks a user for a provider password or accepts a pasted password/token through the UI.
3. Confirm the `social` queue has a live worker and the once-per-minute scheduler is running. Monitor publish and remote-deletion pending age, retry age, token-expired/disconnected states, permanent failures, and deletion requests whose retries are exhausted.
4. Publish fictional content to each exact connected identity, confirm the remote permalink, then test unpublish and disconnect through the queued remote-delete lifecycle before testing same-identity reconnect and an explicit publish retry. A publish retry reuses the local idempotency key, but a provider may still duplicate a post after an ambiguous lost response. A repeated remote DELETE treats provider `404`/`410` as already removed.
5. Pin and record API versions. Review [LinkedIn versioning](https://learn.microsoft.com/en-us/linkedin/marketing/versioning?view=li-lms-2026-05) and the [Meta Graph API v25 changelog](https://developers.facebook.com/docs/graph-api/changelog/version25.0/) before changing either value; stage the new value before rolling workers.
6. Rotate OAuth client secrets through the provider's supported overlap/revocation process. Verify callbacks with the new secret before revoking the old one, then require an exact-account reconnect wherever the provider invalidated existing access or refresh tokens. Never rotate `APP_KEY` as a shortcut: it protects all stored OAuth/TOTP material and needs its own planned re-encryption procedure.

Provider-specific readiness:

- X requires an approved OAuth application and the post-management permissions described by the [X API](https://docs.x.com/x-api/posts/manage-tweets/introduction).
- LinkedIn requires an approved member-author flow and `w_member_social`; use the [Posts API](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2026-06) and [permalink guidance](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares?view=li-lms-2026-05) for release verification.
- Facebook delivery requires a selected Page ID and Page access token obtained through a reviewed Page-token flow, not a personal-profile connection. The shipped onboarding remains disabled until that chooser/exchange is implemented. Re-verify the [Pages publishing contract](https://developers.facebook.com/docs/pages-api/posts/) and Meta's [official Page-token flow collection](https://www.postman.com/meta/facebook/documentation/r56bjfd/facebook-api) before enabling; the final documentation audit received an upstream 429, so this is an explicit operator gate.
- Mastodon requires a safe HTTPS instance origin and OAuth application/token issued for that instance. The shipped onboarding remains disabled until instance-specific registration is implemented; verify against the instance and the [Mastodon statuses API](https://docs.joinmastodon.org/methods/statuses/).

The persisted adapter contract expects X/LinkedIn `provider_user_id` values to come from the verified OAuth identity, a Facebook account to have a numeric `metadata.page_id` while its encrypted `access_token` is the Page token, and Mastodon to have the exact HTTPS `server_url` origin that issued its encrypted token. A reviewed onboarding integration must populate these fields; do not hand-edit rows, paste tokens into unrelated forms, or repurpose a personal access token.

Disconnecting an account cancels local pending/scheduled work and first writes encrypted, deduplicated deletion requests for provider-confirmed posts. The account token is then cleared immediately; the deletion jobs use only their minimal encrypted snapshot. Success, permanent rejection, or exhausted retries erases that snapshot. Provider-confirmed posts may still remain remotely, and failed cleanup records retain no credential or remote identifier. Reconnect always names an existing exact account and rejects a callback that returns a different provider identity.

## Release sequence

1. Back up and verify current database/media recovery points.
2. Build, scan, and stage the immutable image.
3. Configure the release environment and run the fail-closed preflight inside the exact image that will receive traffic:

   ```bash
   php artisan memoria:release-check
   ```

   Do not continue while it reports a blocker. This validates canonical/legal URLs, secret shape, the per-deployment Livewire release token, resolvable mail/log transports, an operational ClamAV signature database using an in-memory EICAR probe, physical storage boundaries, and PHP/runtime limits; it does not replace infrastructure connectivity checks, signature-freshness monitoring, a staging upload-path test, or legal review.

4. Put the application into maintenance mode only when a migration cannot be performed online.
5. Run migrations once:

   ```bash
   php artisan migrate --force
   ```

   Configure `MEMORIA_PRIVATE_DISK=memoria_private_s3`, `MEMORIA_SANITIZED_MEDIA_DISK=memoria_sanitized_s3`, and `MEMORIA_EXPORT_DISK=memoria_exports_s3` with private buckets or distinct private prefixes. Keep `MEMORIA_PUBLIC_DISK` physically separate from all three protected boundaries, not merely under a different Laravel disk name. In a multi-instance deployment, also set `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=memoria_private_s3`; configure that bucket's CORS to permit uploads only from the canonical application origin and configure a 24-hour lifecycle rule for `livewire-tmp/` (or run `php artisan livewire:configure-s3-upload-cleanup` once). Memoria streams approved derivatives through guarded controllers, so no public storage symlink is required.

6. Warm safe caches in the release environment:

   ```bash
   php artisan optimize
   php artisan filament:optimize
   ```

7. Roll application instances, then restart queue workers so they load new code:

   ```bash
   php artisan queue:restart
   ```

8. Verify `/up`, the `/privacy` and `/terms` redirects, database/Redis/storage access, worker heartbeats, one fake/staging publication, and owner isolation.
9. Exit maintenance mode if used.

## Worker processes

Run queues under a process manager or platform worker service:

```bash
php artisan queue:work redis --queue=security,maintenance,default,notifications --sleep=1 --tries=3 --timeout=120 --max-time=3600
php artisan queue:work redis --queue=social --sleep=2 --tries=5 --timeout=120 --max-time=3600
php artisan queue:work redis --queue=exports --sleep=2 --tries=3 --timeout=900 --max-time=3600
```

Set `REDIS_QUEUE_RETRY_AFTER=960` (or a larger reviewed value) for this worker layout. Laravel requires every job timeout to remain below the connection's `retry_after`; the export job may run for 900 seconds. Using the framework default of 90 seconds can release an in-progress export to a second worker. Database-queue development uses the same `DB_QUEUE_RETRY_AFTER=960` baseline. If SQS is substituted, set its visibility timeout above the longest job timeout as well.

Size worker memory/time limits for media/export archives. Alert on queue depth, oldest-job age, failed jobs, missing worker heartbeats, repeated provider authentication failures, and pending/failed `social_post_deletions`. The scheduler requeues stranded remote deletion requests every five minutes, but it does not replace a live `social` worker. Failed-job payload access is an administrative security capability; remote-deletion job payloads contain only the deletion-row ID, never a token or remote identifier.

The image includes the ClamAV client, but intentionally does not bake a quickly stale signature database into the immutable application layer. Provision `/var/lib/clamav` from a continuously updated, read-only runtime volume (or an equivalent platform-managed signature source) before starting application and `security` worker containers. Run `freshclam` from a dedicated updater/init container with write access to that volume; the application containers need read access only. `memoria:release-check` rejects a missing or non-functional database by scanning an in-memory EICAR probe. Also verify an EICAR upload is rejected through the complete staging upload/queue path before accepting user files, and alert on stale signatures or update failures. If the scanner is missing or unhealthy, Memoria leaves files unavailable rather than treating them as clean.

## Scheduler

Use exactly one of these approaches:

```cron
* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
```

or a supervised process:

```bash
php artisan schedule:work
```

The scheduler releases due publications and reminders, deletes expired export archives, and requeues stranded personal-file cleanup. A cleanup that exhausts its queue attempts is retried after `MEMORIA_FILE_CLEANUP_RETRY_HOURS` (24 hours by default), so a temporary storage outage cannot permanently strand deleted personal files. Alert on `storage.file_cleanup_failed` audit events and investigate recurring failures. Scheduled times are stored in UTC after interpreting the user's IANA timezone. Share-row, trash, public-media-orphan, and audit retention are operator-defined policies and are not silently purged by the application.

## Storage

Private originals, sanitized derivatives, and exports require private ACLs and blocked anonymous access. Memoria streams them through policy-checked application routes with appropriate cache headers; do not expose their storage paths directly. Sanitized publication media belongs in a distinct prefix/bucket containing only intentionally copied derivatives.

The bundled PHP profile accepts uploads up to 21 MB with a 25 MB request body. PHP and PHP-FPM do not impose a hard execution cutoff because authorized attachment and export streams may legitimately outlive a normal dynamic request; CLI workers use Laravel's per-worker and per-job timeouts. Configure reviewed connection, request-body (at least 25 MB), idle, and upstream timeouts at the TLS proxy or ingress, and test them against the largest supported download. Otherwise valid uploads or slow private downloads may be terminated outside Laravel.

Configure lifecycle cleanup to complement—not replace—application reference checks. Test large uploads/downloads, MIME headers, range requests for media, public cache headers, authorization failures, and export expiry.

## Backups and restore

Back up PostgreSQL and both media boundaries. Encrypt backups with a key separate from application credentials and restrict restore access.

Recommended starting objectives (adjust to business requirements):

- database PITR/RPO: 15 minutes
- service RTO: 4 hours
- daily logical backup retained 35 days
- monthly encrypted archive retained 12 months
- object versioning/lifecycle aligned with documented deletion retention
- quarterly restore exercise in an isolated environment with fictional verification accounts

A restore runbook must replay deletion/revocation records created after the restored point before exposing service. Document that encrypted backups can retain deleted data until rotation expiry.

## Health and monitoring

`/up` is a minimal liveness endpoint and must not reveal versions, connection strings, queue contents, filenames, or user counts. Use authenticated/internal probes for database writes, Redis locks, object storage, and worker freshness.

Every HTTP response includes a server-generated `X-Request-ID`. Laravel adds only that opaque identifier to log context and carries it into jobs dispatched by the request. Preserve the response header at the reverse proxy and use it to correlate sanitized logs; do not replace it with an unvalidated client value.

Set `TRUSTED_PROXIES` to the exact reverse-proxy/load-balancer addresses or CIDRs used by the deployment. Memoria accepts forwarded scheme, host, port, prefix, and client IP headers only from those peers. Do not use a wildcard on an internet-reachable origin unless the hosting platform strips and rewrites every forwarded header; an over-broad trust boundary lets clients spoof HTTPS and source-IP metadata.

Monitor latency/error rate, database saturation, slow queries, queue depth/age, scheduler heartbeat, storage errors, mail failure, OAuth/token expiry rates, and public availability. Never attach private request bodies, diary content, tokens, exact locations, authorization headers, or cookies to telemetry.

## Rollback

Prefer rolling back application code while keeping backward-compatible expanded schema. For a migration failure:

1. stop writes/workers when necessary;
2. restore the previous image;
3. run only a reviewed reversible migration rollback when it cannot destroy newly written data;
4. otherwise restore from the verified backup/PITR point;
5. restart workers and execute privacy/authorization smoke tests.

Never use `migrate:fresh`, destructive manual SQL, or an untested backup in production.

## Local Compose operations

```bash
docker compose up -d postgres redis
docker compose run --rm app php artisan migrate:fresh --seed
docker compose up app worker scheduler node
docker compose ps
docker compose logs -f app worker scheduler
```

Local credentials in `compose.yaml` are development-only and must never be reused outside a developer machine.

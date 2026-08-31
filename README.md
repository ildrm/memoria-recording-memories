# Memoria

Memoria is a privacy-first personal diary, memory vault, and selective publishing platform built with Laravel 13, Filament 5, Livewire 4, Blade, and Tailwind CSS 4.

The central product rule is structural: a private diary entry is never the public object. Publishing creates an independently editable `Publication` snapshot, runs a privacy review, shows a faithful preview, and requires a final explicit publish action. Saving an entry is never publishing it.

## Screenshots

Release screenshots are intentionally not committed yet. Capture them from fictional seeded data after configuring the target deployment so no real diary content, share token, email address, or operational secret enters repository history.

## What is included

- Private journals, rich diary entries, autosave, versions, tags, people, moods, locations, favorites, archive, trash, timeline, calendar, search, and “On This Day”.
- Private image/document/audio/video metadata and owner-authorized downloads.
- View-only sharing to registered users and revocable, expiring, optionally password-protected unlisted links.
- Independent publication drafts, privacy review, preview, scheduling, public profiles, article pages, RSS, sitemap, and robots controls.
- OAuth-connected account records, exact-account target selection, queue-backed social delivery, audited retries, truthful per-destination history, and a deterministic local/test provider.
- Queued private exports, reminders, notifications, public comments/reactions/reports, and moderation.
- Separate consumer `/app` and administrative `/admin` Filament panels. Administrative screens never provide ordinary browsing of private diary bodies.
- TOTP multi-factor authentication with encrypted secrets and recovery codes, email verification, password reset, owner-scoped policies, rate limits, security headers, and privacy-safe audit events.
- Pest tests, Pint, Larastan, Vite, Docker Compose, and GitHub Actions CI.

## Requirements

- PHP 8.3–8.5 with `bcmath`, `exif`, `fileinfo`, `gd`, `intl`, `mbstring`, `pdo_sqlite` (local tests), `pdo_pgsql` (PostgreSQL), and `zip`
- Composer 2
- Node.js 24+ and npm
- SQLite for the zero-configuration local path, or PostgreSQL 17 and Redis 8 through Docker

## Quick start with SQLite

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --seed
npm ci
npm run build
php artisan serve
```

Open `http://localhost:8000`. The seeded local accounts are fictional development data and are listed by the seeder output. Change every seeded password outside local development.

Run durable background work in separate terminals:

```bash
php artisan queue:work --queue=security,maintenance,default,social,exports,notifications --tries=3 --timeout=120
php artisan schedule:work
```

Mail uses the log driver and social publishing uses the deterministic fake provider by default. No external post or email is sent in local or test environments.

## Quick start with Docker

```bash
cp .env.example .env
docker compose build
docker compose up -d postgres redis
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate:fresh --seed
docker compose up app worker scheduler node
```

Docker overrides the local database/cache/queue/session drivers with PostgreSQL and Redis. See [deployment](docs/deployment.md) for production configuration.

## Development commands

```bash
# All PHP tests
php artisan test --compact

# One test file or one named test
php artisan test --compact tests/Feature/Publications/PublicationWorkflowTest.php
php artisan test --compact --filter="prevents cross-user entry access"

# Format modified PHP files
vendor/bin/pint --dirty --format agent

# Static analysis
composer analyse

# Frontend production build
npm run build

# Dependency audits
composer audit
npm audit
```

The full local quality gate is:

```bash
composer validate --strict
php artisan migrate:fresh --seed
vendor/bin/pint --format agent
composer analyse
php artisan test --compact
npm run build
```

## Application URLs

- `/` — public landing page
- `/app` — private diary application and authentication
- `/admin` — moderation and operations for authorized roles
- `/@username` — an enabled public profile
- `/@username/{publication}` — a published article; indexing follows that publication's explicit preference
- `/shares/{token}` — an unlisted share; deliberately excluded from discovery and indexing
- `/up` — minimal liveness response

## Configuration

Copy `.env.example`; never commit `.env`. Important production values include:

- `APP_KEY` — encrypts TOTP and OAuth credentials; protect and back it up separately.
- `MEMORIA_PRIVACY_NOTICE_URL` and `MEMORIA_TERMS_OF_SERVICE_URL` — distinct, operator-reviewed public HTTPS documents. Production refuses to serve the bundled drafting templates.
- PostgreSQL `DB_*` and Redis `REDIS_*` settings.
- `MEMORIA_PRIVATE_DISK`, `MEMORIA_SANITIZED_MEDIA_DISK`, and `MEMORIA_EXPORT_DISK` — originals, guarded sanitized derivatives, and exports must use private visibility boundaries. `MEMORIA_PUBLIC_DISK` identifies a directly public disk that the sanitizer explicitly refuses; current public media is always streamed through guarded application routes.
- `MEMORIA_ATTACHMENT_SCANNER=clamav` plus a maintained signature database mounted for `clamscan` in production; the release check proves EICAR detection, and scanner failure remains fail-closed. Every worker topology must consume the `security` queue so pending uploads are actually scanned.
- OAuth client IDs, secrets, exact callbacks, and provider-approved scopes. Memoria never asks for a provider password or accepts one as a substitute for OAuth.
- `MEMORIA_SOCIAL_DRIVER=fake` is local/testing-only. Production must explicitly use `disabled`, or `real` only after staging the enabled adapters, workers, app review, version pins, and reconnect runbook.
- `MEMORIA_LINKEDIN_VERSION` and `MEMORIA_FACEBOOK_GRAPH_VERSION` are explicit production pins; review and test provider changelogs before rotating either value.
- `MEMORIA_SHARE_*` and `MEMORIA_EXPORT_*` expiration and resource limits.
- secure session cookie flags when HTTPS is active, plus an exact `TRUSTED_PROXIES` IP/CIDR allowlist for the deployment's reverse proxies.

Run `php artisan memoria:release-check` inside the configured release runtime before migration or traffic cutover. It fails on placeholder legal, security, mail, storage, scanner, database, queue, and runtime settings.

## Privacy model in one minute

- All entries, versions, private attachments, people, tags, journals, exports, and social credentials are owner-scoped on the server.
- Moderator/administrator roles do not bypass private-entry policies.
- A publication stores its own title, excerpt, body, media list, revisions, status, and timestamps. Later entry edits do not silently change public content.
- Public media is a separate approved derivative/copy. Public pages never render private storage URLs.
- Unlisted link credentials are high-entropy; only their SHA-256 hash is stored. Optional passwords use Laravel password hashing.
- Entry text is not field-encrypted because the implemented owner-scoped database search must query it. Production deployments must use encrypted disks/database backups. This is not a zero-knowledge or end-to-end-encrypted product.

Read [privacy architecture](docs/privacy-architecture.md) and [security](docs/security.md) before production use.

## Social delivery boundaries

Publishing and scheduling select an exact connected account, not merely a provider or the most recently connected identity. The delivery history distinguishes pending, processing, published, retrying, failed, disconnected, token-expired, cancelled, and simulated results. A provider-confirmed remote link is shown only when it passes an HTTPS provider-origin allowlist.

Explicit retry reuses the same local delivery and idempotency key, is owner-authorized and audited, and is offered only for a retryable failure or a reconnected exact identity. Some provider APIs do not guarantee remote idempotency; if a response was lost after provider acceptance, the user must check the provider before retrying because a duplicate may still be possible.

Unpublish, published-version edit/archive withdrawal, moderation, disconnect, and account deletion durably schedule best-effort provider deletion before credentials or local rows are destroyed. Failed deletion compensation after a concurrent cancellation enters the same retry path. The `social` worker retries transient failures, the scheduler recovers stranded requests every five minutes, and repeated provider `404`/`410` responses count as already removed. Minimal encrypted cleanup credentials are erased on success or terminal failure. External copies may still remain, so Memoria never presents local privacy changes as guaranteed provider erasure.

The shipped X and LinkedIn connection buttons require configured OAuth clients. Facebook Pages requires an explicit Page chooser and Page-token exchange, and Mastodon requires instance-specific OAuth application registration; those two onboarding buttons remain unavailable until those flows are implemented and verified. The UI never asks for provider passwords and does not fabricate a generic OAuth flow. See the [social deployment runbook](docs/deployment.md#enable-real-social-delivery) and [provider boundary](docs/architecture.md#social-provider-boundary).

## Documentation

- [Architecture](docs/architecture.md)
- [Database and ER model](docs/database.md)
- [Privacy architecture](docs/privacy-architecture.md)
- [Security model and review](docs/security.md)
- [Deployment, workers, monitoring, backups, and rollback](docs/deployment.md)

## Third-party packages

The application deliberately keeps dependencies small:

- Filament supplies the two authenticated panels, forms, tables, rich editor, notifications, and native TOTP support.
- Livewire supplies server-driven interactivity and autosave behavior.
- Laravel Socialite supplies verified OAuth redirect/callback primitives for supported connections.
- Pest and its Laravel plugin provide the test suite.
- Larastan provides Laravel-aware PHPStan analysis.
- Laravel Boost is development-only, supplying installed-version-aware project guidance.

No community Filament plugin is required.

## License

Memoria is available under the [MIT License](LICENSE).

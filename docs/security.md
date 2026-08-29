# Security model

This is the engineering security reference for Memoria. Review it with the deployment environment before production launch.

## Security objectives

1. A user cannot read or modify another user's private records by changing a URL, Livewire payload, form field, or job identifier.
2. Public rendering can access only explicitly published snapshots and approved public media.
3. Administrators can operate and moderate the service without an ordinary private-diary browser.
4. Credentials, tokens, recovery codes, and private content do not enter logs or serialized responses.
5. Retried/concurrent jobs cannot duplicate external publication.

## Identity and sessions

The `/app` panel supports registration, email verification, throttled login, password reset, password changes, session regeneration, and native Filament TOTP with recovery codes. TOTP secrets/recovery codes use encrypted casts and hidden serialization. Passwords use Laravel's configured adaptive hasher.

Production must set HTTPS, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, an appropriate `SESSION_SAME_SITE` value, and an exact `TRUSTED_PROXIES` IP/CIDR allowlist. Forwarded scheme and client-IP headers are ignored unless the immediate peer is trusted, so HSTS, URL generation, throttling, and audit metadata retain the correct boundary. Rotating `APP_KEY` requires a deliberate re-encryption plan because encrypted OAuth/TOTP values depend on it.

## Authorization matrix

| Capability | User | Moderator | Administrator | Super administrator |
| --- | --- | --- | --- | --- |
| Own private diary and files | yes | only own | only own | only own |
| Explicit private share received | view only | view only | view only | view only |
| Publish own snapshot | yes | yes | yes | yes |
| Moderate public comments/reports | no | yes | yes | yes |
| Manage account/system metadata | no | no | yes | yes |
| Manage roles/system configuration | no | no | limited | yes |
| Browse another user's private body | no | no | no | no |

Policies cover view/list/create/update/delete/restore/force-delete plus domain actions such as share, publish, and download. Controllers, Livewire actions, jobs, and Filament custom actions authorize on the server. Owner IDs are derived from the authenticated user, never accepted as trusted form data.

The last super administrator cannot be removed or demoted through normal operations.

## Input and output safety

- Form Requests/Filament rules validate types, lengths, enums, dates, URL fields, ownership, and upload constraints.
- Eloquent/query-builder bindings prevent SQL value injection; user-selected sort/filter fields use allow-lists.
- Blade escapes by default. Rich public HTML is sanitized with the installed Symfony/Filament sanitizer before unescaped rendering.
- State-changing browser requests retain CSRF protection.
- Uploads validate server-detected MIME, expected extension, size, and image content; storage names are randomized and originals are private.
- Download responses use safe filenames and `nosniff` headers.

## Link and credential security

Unlisted tokens use cryptographically secure random bytes and SHA-256 hashes at rest. Optional link passwords use Laravel hashing, with a dedicated rate limit for attempts. OAuth access and refresh tokens use encrypted casts and are hidden. Authorization headers and provider payload secrets are never logged.

## Rate limits

Named limiters cover authentication flows, unlisted-link passwords, comments, reports, social actions, exports, and other expensive/public operations. Limits key by a privacy-preserving combination of account and network identity where appropriate. Upstream edge/WAF denial-of-service controls remain recommended.

## Headers

Security middleware applies a conservative baseline:

- `X-Content-Type-Options: nosniff`
- frame protection (`frame-ancestors` CSP and/or `X-Frame-Options`)
- strict referrer policy
- permissions policy disabling unneeded sensors
- Content Security Policy compatible with Livewire/Filament assets and OAuth flows
- HSTS only when the request is securely terminated and production HTTPS is guaranteed

Validate CSP against rich-text media, Livewire, OAuth callbacks, and Vite assets whenever frontend dependencies change.

## Queue and external HTTP safety

Provider clients set connect/request timeouts, parse non-success responses, classify permanent versus retryable errors, and use Laravel HTTP fakes in tests. Jobs do not mark success before provider confirmation. Each delivery has a unique idempotency key plus a distributed lock. Database transactions finish before external HTTP begins.

Queue payloads contain record IDs, not diary bodies or raw credentials. Failed-job/admin screens show safe error class/code and attempts, never authorization headers or private content.

## Security logging

Privacy-safe events include authentication/security changes, 2FA changes, connected-account changes, publication status changes, share creation/revocation, export lifecycle, deletion requests, role changes, and moderation actions. IP/user-agent values are hashed where stored.

Never log request bodies on private routes. The server-generated `X-Request-ID` and Laravel's internal authenticated user ID are safe correlation metadata; no client-supplied request ID, URL, body, header, email, username, or private record identifier is added to shared log/job context. Any infrastructure or application error-monitoring integration must be reviewed and configured with request-body and sensitive-header scrubbing; Memoria does not ship a vendor-specific monitoring client or inactive DSN setting.

Database query exceptions receive a separate privacy-safe report containing only a normalized database error code and the normal request correlation context. The original exception still drives Laravel's response and retry behavior, but its interpolated SQL and bindings are not sent to the default log stack because those bindings can contain diary text.

## Dependency and release checks

Run before every release:

```bash
composer audit
npm audit
composer validate --strict
vendor/bin/pint --format agent
composer analyse
php artisan test --compact
npm run build
```

The test suite must include cross-user/IDOR attempts for every private resource, unpublished-publication denial, link expiry/revocation/password limits, invalid uploads, sanitized XSS, admin privacy, social idempotency, and export isolation.

## Incident response outline

1. Preserve privacy-safe evidence and identify affected account/public records without copying diary bodies into tickets.
2. Revoke sessions/OAuth/share links and pause relevant workers.
3. Patch, test, and deploy; rotate affected credentials without casually rotating `APP_KEY`.
4. Identify remote social artifacts separately from local records.
5. Notify affected users and authorities according to applicable policy/law.
6. Document corrective actions and test the failure path.

## Reporting vulnerabilities

Do not open a public issue containing private data or exploit details. Contact the repository owner privately with impact, affected route/model, reproduction steps using fictional data, and a proposed mitigation if available.

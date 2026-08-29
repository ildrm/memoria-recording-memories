# Privacy architecture

This document states Memoria's actual privacy properties, limitations, and operational assumptions. It is not a legal compliance certification.

## Product promise

> My diary is private unless I deliberately choose to share a controlled version of something.

The promise is enforced by domain separation, server-side authorization, private storage, and explicit user workflow—not by hiding buttons.

## Private by default

Creating or changing any of the following remains private: a journal, entry, draft, tag, person, mood, location, attachment, version, or publication draft. There is no `entries.is_public` shortcut.

An entry reaches the public website only through these separate steps:

1. The owner chooses **Create public version**.
2. The server snapshots allowed entry fields into a new draft `Publication`.
3. Sensitive fields and media are omitted unless deliberately selected.
4. The owner reviews warnings for people, contact details, exact locations, private media, and image metadata.
5. The owner previews the exact public representation.
6. A final explicit confirmation changes the publication state or schedules it.

Automated warnings are assistance, not a guarantee that text contains no identifying detail.

## What administrators can see

Moderators and administrators can see account metadata required for operation, published/public content, comments, reports, social delivery state, aggregate counts, health state, and privacy-safe audit events.

They do not receive policy access to private entries, journals, versions, people, tags, attachments, shares, or exports. Those resources are also absent from ordinary admin navigation and global search. Super administrators manage system capabilities but do not gain a casual private-content browser.

Memoria does not implement a break-glass private-content access path. If one is ever introduced, it needs a distinct capability, recorded reason, strong re-authentication, immutable audit, and organizational approval.

## Private and public media

Private uploads stay in private storage. Selecting media for a publication creates a separate public copy/derivative with a randomized name. The public copy should be re-encoded and stripped of EXIF metadata where the configured image pipeline supports that operation. The original is never destructively modified.

Unpublishing removes the local public record and its discoverability. Cleanup deletes unreferenced public copies. Deleting a private original does not make a public URL point into private storage because they are separate objects.

Public profile and RSS aggregate pages are always `noindex` because they may list intentionally public stories whose author disabled search-engine indexing. The paginated sitemap contains only the landing page and website publications with explicit indexing enabled; it never includes profiles, drafts, social-only targets, or unlisted shares.

## Controlled private sharing

Registered-user shares are view-only. An unlisted link is a bearer credential with optional password, expiration, and view limit. The database stores only a token hash, so a database reader cannot directly reconstruct the URL. Revocation and expiry are checked on every access. Unlisted pages are `noindex`, excluded from profiles/RSS/sitemaps, and never treated as public.

An unlisted URL can still be copied by a recipient. Passwords and expiry reduce exposure; they cannot prevent screenshots or downstream copying.

## Encryption statement

Memoria uses Laravel application encryption for secrets that the server must later recover, including TOTP and OAuth credentials. Passwords and unlisted-link passwords are one-way hashed.

Diary titles/bodies are not application-field-encrypted because the implemented owner-scoped search must query them. Production operators must enable encryption at rest for database volumes, object storage, snapshots, and backups, plus TLS in transit. The application server can read content while serving an authorized request.

Therefore Memoria is **not** zero-knowledge and **not** end-to-end encrypted. Do not market it as either. Adding end-to-end encryption would require client-side key management and a different search, sharing, recovery, and multi-device design.

## Logs, analytics, and audit

Application logs, errors, analytics, and audit metadata must not contain diary bodies, titles, tags, exact locations, filenames, share passwords/tokens, OAuth credentials, TOTP data, or export contents. Audit events identify the action, actor, target type/ID, time, and allow-listed metadata.

Product analytics are disabled by default. If enabled later, they may record generic route/feature events but not private content or sensitive metadata.

## Deletion and retention

- Trashed entries remain recoverable until the owner explicitly permanently deletes them; operators may add a separately reviewed retention policy.
- Export downloads expire after 72 hours by default and the scheduler deletes their archives.
- Share links stop immediately when revoked or expired. Expired database rows may be retained as privacy-safe revocation history until an operator-defined cleanup policy removes them.
- Audit events have no automatic application retention window; operators must set and document a policy appropriate to incident response. Operational logs should normally use a shorter retention.
- Account deletion revokes shares/connections, cancels scheduled local work, and removes owned local private/public records and generated media after the documented confirmation flow.

Encrypted backups may retain deleted data until their rotation expires. Backups are not queried as live data and must be protected, access-controlled, and deleted on the documented retention schedule. Restoring an older backup requires replaying deletion records before reopening service.

## External publishing limitation

Once a provider confirms a social post, that provider or other people may copy/cache it. Before unpublish, published-version edits or archive, moderation, disconnection, or account deletion destroys local credentials or rows, Memoria records an encrypted best-effort deletion request for provider APIs that support deletion. A post created while cancellation wins is removed immediately when possible, and a failed compensation attempt is queued for the same durable cleanup. Cleanup runs asynchronously with bounded retries and scheduler recovery; repeated `404`/`410` responses count as already removed. The minimal copied credential and remote identifier are erased after success, permanent rejection, or exhausted retries. A failed request is retained only as privacy-safe status/fingerprint metadata, and the interface continues to disclose that the external copy may remain. Making a local publication private never guarantees that every provider, cache, or third-party copy disappeared.

## Legal-sensitive use

Memoria provides privacy engineering controls, not automatic GDPR, HIPAA, SOC 2, or other legal certification. Operators remain responsible for applicable notices, lawful basis, data-processing agreements, residency, retention, breach response, and data-subject workflows.

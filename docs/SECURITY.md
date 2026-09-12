# Security model

WP Native Builder Bridge is designed as a bounded WordPress capability layer, not a general remote shell.

## Layered authorization

A successful operation must satisfy every applicable layer:

1. valid OAuth-authenticated WordPress identity for direct ChatGPT connections;
2. the relevant Bridge access group;
3. the required WordPress capability or object-level/meta authorization rule;
4. any provider-native permission check used by an integration;
5. operation-specific live-state, destructive, and stale-state rules.

OAuth never enables a Bridge access group and never grants a WordPress capability.

## Access groups

- **Site Read** — read-only inspection surfaces.
- **Builder Write** — bounded content/site-building mutations.
- **Live Content** — publishing and other live-state transitions.
- **Site Configuration** — bounded global configuration.
- **Advanced Metadata** — protected/private post metadata for WordPress post objects the connected user may edit; disabled by default and intentionally separate from ordinary Site Read/Builder Write access.
- **Code & Extensions** — managed snippets and extension lifecycle.
- **Users & Destructive** — user administration and destructive operations. Generic post-meta deletion requires this group in addition to Advanced Metadata.

Only Site Read is enabled by default.

## Advanced post metadata boundary

Advanced Metadata exists for legitimate theme/plugin/builder state that is stored in `post_meta` instead of `post_content`. It is provider- and post-type-neutral and does not require a new hardcoded allowlist entry for every theme, plugin, CPT, or meta key.

The boundary is deliberately layered:

- the Advanced Metadata group must be enabled by a WordPress administrator;
- the target must be a real WordPress post object and the connected WordPress user must be able to edit that exact object;
- revision IDs are canonicalized to the parent before metadata authorization, read/hash identity, or mutation, matching the target used by WordPress post-meta mutation wrappers;
- the target post type does not need to be public, REST-exposed, or editor-capable;
- Bridge-private Workspace post types (`wpnb_doc` and `wpnb_task`) are explicitly excluded;
- normal post-meta capabilities remain authoritative for public keys and for keys where Core/provider code registered metadata or installed an explicit authorization filter;
- protected/private unregistered keys may use the target post's `edit_post` authority once Advanced Metadata is enabled, because WordPress otherwise denies such keys generically merely for being protected;
- additional capability requirements and `do_not_allow` returned by the final `map_meta_cap` pipeline remain authoritative;
- credential-like key names are excluded through a provider-neutral normalization rule that covers common separator, camelCase, and compact token/secret forms;
- list operations return keys/state summaries only; exact values require an explicitly named key;
- mutation identity is based on physical stored rows rather than registered default expansion, so absent-with-default and stored-with-the-same-value are distinct states;
- updates/deletes require an exact `state_hash`, reject stale writes, and refuse ambiguous multi-row keys;
- creation uses WordPress unique-row semantics; replacement/deletion condition on the inspected previous value where Core can do so and verify the resulting state;
- when WordPress cannot safely bind an empty/null-like previous value to a conditional mutation, the generic surface fails closed rather than falling back to an unconditional write;
- metadata values containing PHP objects/resources at any depth, or values that do not survive the generic JSON contract structurally unchanged, are not generically replaceable;
- delete additionally requires Users & Destructive access.

This surface does not grant direct database access and does not expose arbitrary options or user meta.

## Stale-write protection

Overwrite-sensitive content, post-metadata, and Workspace operations return change identities. A later update must present the expected current identity. If the object changed after inspection, the Bridge rejects the write and requires the caller to refresh.

Post metadata combines deterministic physical-row state identity with the strongest safe conditional mutation available through WordPress Core APIs: unique creation for an absent key and previous-value-conditioned replacement/deletion for an existing single row. The Bridge re-reads after mutation and reports a deterministic stale conflict when concurrent state is detected. Cases Core cannot condition safely through its public metadata APIs fail closed rather than weakening the concurrency guarantee.

Workspace documents/tasks use monotonic `version` plus deterministic `state_hash` with an atomic compare-and-swap against the previous state payload.

## Content, Gutenberg, and metadata isolation

Generic content/Gutenberg operations remain limited to their existing eligible editor-capable content predicate. Advanced Metadata is intentionally broader because the site administrator explicitly opts into it, but Bridge-private Workspace storage remains excluded from the generic metadata surface and is reachable only through the dedicated Workspace contract.

## Upload boundary

Media upload accepts bytes and a filename, writes only to a WordPress-generated temporary path, and hands the result to WordPress media/sideload handling. The caller cannot specify a server filesystem path.

The payload cap is the smaller of the WordPress upload limit and 20 MiB.

## Extension boundary

Plugin/theme installation accepts WordPress.org slugs resolved through WordPress Core APIs. Arbitrary package URLs, uploaded plugin ZIPs, PHP files, and caller-selected server paths are not accepted.

Deletion requires destructive access in addition to the action-specific WordPress capability. Active extensions are protected where deletion would be unsafe.

## Managed code snippets

Code Snippets integration is an intentional bounded provider integration. Submitted code is passed through the installed Code Snippets lifecycle API and its capability checks. The Bridge does not evaluate the code directly or provide a generic PHP execution endpoint.

## Explicitly absent generic surfaces

The Bridge does not expose:

- arbitrary SQL;
- shell/process execution;
- WP-CLI execution;
- unrestricted filesystem access;
- arbitrary `wp_options` access;
- arbitrary user-meta administration;
- credential, session, OAuth-secret, or Application Password retrieval;
- arbitrary plugin ZIP/PHP upload;
- direct provider-table administration.

## OAuth storage

Authorization codes, access tokens, and refresh tokens are opaque. Secret-bearing values are not intentionally stored in plaintext. Refresh tokens rotate, revocation is supported, and the direct MCP resource is bound to the OAuth flow.

## Activity logging

The mutation log is bounded and metadata-oriented. It should not be treated as a content archive and must not be used to log credentials, metadata keys, metadata values, or full submitted payloads.

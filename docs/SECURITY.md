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
- revision IDs are canonicalized to the parent before metadata authorization, physical-state inspection, hashing, or mutation, matching the target used by WordPress post-meta mutation wrappers;
- the target post type does not need to be public, REST-exposed, or editor-capable;
- Bridge-private Workspace post types (`wpnb_doc` and `wpnb_task`) are explicitly excluded;
- normal post-meta capabilities remain authoritative for public keys and for keys where Core/provider code registered metadata or installed an explicit authorization filter;
- protected/private unregistered keys may use the target post's `edit_post` authority once Advanced Metadata is enabled, because WordPress otherwise denies such keys generically merely for being protected;
- additional capability requirements and `do_not_allow` returned by the final `map_meta_cap` pipeline remain authoritative;
- credential-like key names are excluded through a provider-neutral normalization rule that covers common separator, camelCase, and compact password/secret/credential, API/private-key, OAuth/access/refresh, session, identity/ID, JWT, bearer/auth, and related security-token forms without banning unrelated uses of the generic word `token`;
- list operations return keys/state summaries only; exact values require an explicitly named key;
- mutation identity comes from the physical `wp_postmeta` rows, including physical row IDs and raw stored values, rather than registered defaults or `get_post_metadata` virtual-read short circuits;
- updates/deletes require an exact `state_hash` and refuse ambiguous multi-row keys;
- existing-row update/delete uses a narrowly bounded internal compare-and-swap against the exact inspected `meta_id + post_id + meta_key + raw meta_value`, so an identical concurrent row cannot be fanned out through a value-wide Core mutation;
- when post-mutation verification detects concurrent interference, update rolls back only the row changed by that invocation and delete restores only the exact row deleted by that invocation before returning a stale conflict;
- absent-row creation still uses WordPress `add_post_meta(..., true)` for normal Core sanitization/hooks; because Core uniqueness is a SELECT-then-INSERT check rather than a database constraint, the Bridge verifies the returned meta ID and removes only its own newly created row if contention produced multiple rows;
- metadata values containing PHP objects/resources at any depth, or values that do not survive the generic JSON contract structurally unchanged, are not generically replaceable or deletable;
- delete additionally requires Users & Destructive access.

The exact-row compare-and-swap helper is an internal fixed-purpose implementation detail, not an API surface. It accepts no SQL, table name, column name, meta ID, or database selector from the MCP client; it is hard-bound to the already-authorized `wp_postmeta` row. Static safety checks keep direct database use forbidden everywhere else in production source. The Bridge still exposes no arbitrary SQL or general database administration.

## Stale-write protection

Overwrite-sensitive content, post-metadata, and Workspace operations return change identities. A later update must present the expected current identity. If the object changed after inspection, the Bridge rejects the write and requires the caller to refresh.

Post metadata uses deterministic physical-row identity plus exact-row compare-and-swap. The Bridge re-inspects physical rows after mutation. Concurrent duplicate/add/update/delete interference is reported as a deterministic stale conflict; where the Bridge itself already changed one row before discovering the interference, it performs a row-scoped compensation step rather than overwriting or deleting concurrent rows. If that exact compensation cannot be completed safely, the operation returns a dedicated compensation failure instead of claiming success.

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

- arbitrary SQL or database administration;
- shell/process execution;
- WP-CLI execution;
- unrestricted filesystem access;
- arbitrary `wp_options` access;
- arbitrary user-meta administration;
- credential, session, OAuth-secret, private-key, security-token, or Application Password retrieval;
- arbitrary plugin ZIP/PHP upload;
- direct provider-table administration.

## OAuth storage

Authorization codes, access tokens, and refresh tokens are opaque. Secret-bearing values are not intentionally stored in plaintext. Refresh tokens rotate, revocation is supported, and the direct MCP resource is bound to the OAuth flow.

## Activity logging

The mutation log is bounded and metadata-oriented. It should not be treated as a content archive and must not be used to log credentials, metadata keys, metadata values, or full submitted payloads.

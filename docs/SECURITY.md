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
- **Advanced Metadata** — protected/private post and term metadata for exact WordPress objects the connected user may edit; disabled by default and intentionally separate from ordinary Site Read/Builder Write access.
- **Code & Extensions** — managed snippets and extension lifecycle.
- **Users & Destructive** — user administration and destructive operations. Generic post-meta and term-meta deletion require this group in addition to Advanced Metadata.

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
- credential-like key names are excluded through a provider-neutral normalization rule that covers common separator, camelCase, compact, singular, and plural password/secret/credential, API/private-key, OAuth/access/refresh, session, identity/ID, JWT, bearer/auth, and related security-token forms without banning unrelated uses of the generic word `token`;
- list operations return keys/state summaries only; exact values require an explicitly named key;
- mutation identity comes from the physical `wp_postmeta` rows, including physical row IDs and raw stored values, rather than registered defaults or `get_post_metadata` virtual-read short circuits;
- SQL `NULL` is preserved as a distinct physical raw state and cannot alias the empty string in `state_hash`;
- updates/deletes require an exact `state_hash` and refuse ambiguous multi-row keys;
- existing-row update/delete uses a narrowly bounded internal compare-and-swap against the inspected `meta_id + post_id + meta_key + raw meta_value`; key/value predicates are byte-exact rather than text-collation equality, and SQL `NULL` uses an explicit `IS NULL` branch;
- after an exact mutation, verification remains inside the persistence boundary. If interference is detected, update rolls back only its own unchanged written row and delete restores only its own exact deleted row before returning a stale conflict;
- compensation emits the corresponding WordPress metadata lifecycle actions for the restored final physical state. Integrity compensation is not vetoable by metadata short-circuit filters after the Bridge has already committed the first exact mutation;
- absent-row creation still uses WordPress `add_post_meta(..., true)` for normal Core unslashing, one-pass sanitization, and hooks. The Bridge observes the already-sanitized value without invoking the sanitizer a second time, then treats the returned meta ID as its own only while that exact post/key/raw identity is unchanged;
- because Core uniqueness is a SELECT-then-INSERT check rather than a database unique constraint, a create race can still yield multiple rows. In that case the Bridge byte-exactly removes only its own still-unchanged row and emits the corresponding delete lifecycle. If an observer changed the returned row first, the Bridge leaves it untouched and reports stale instead of claiming ownership;
- metadata values containing PHP objects/resources at any depth, or values that do not survive the generic JSON contract structurally unchanged, are not generically replaceable or deletable;
- delete additionally requires Users & Destructive access.

The exact-row compare-and-swap helper is an internal fixed-purpose implementation detail, not an API surface. It accepts no SQL, table name, column name, meta ID, query fragment, or database selector from the MCP client; it is hard-bound to the already-authorized `wp_postmeta` row. The only raw SQL is the fixed prepared byte-exact update/delete predicate required to avoid database-collation equivalence. Static safety checks confine these two logical exact-row operations to six fixed prepared SQL branches in the post-meta store and keep direct database use forbidden elsewhere in production source except the separately confined term-meta store described below. The Bridge still exposes no arbitrary SQL or general database administration.

This mechanism provides a bounded row-level stale-write/compensation protocol, not a database transaction or serializable isolation guarantee. A write that occurs after the Bridge's verified linearization point is simply a newer state and can make the returned `state_hash` stale immediately, as with any optimistic-concurrency token.

## Advanced term metadata boundary

Issue #36 extends the same default-off group to term metadata, without changing the post-meta persistence or authorization implementation. The secret-key normalization is shared verbatim with post metadata rather than duplicated.

Term identity is **both `term_id` and `taxonomy`**: the taxonomy must be registered, explicit and canonical term resolution must agree, Core's term metadata subtype must agree, and shared legacy term IDs fail closed. WordPress `edit_term` and operation-specific `add_term_meta`, `edit_term_meta`, or `delete_term_meta` mapping determine authority; no global `manage_categories` or `manage_options` substitutes for it. Protected unregistered metadata may override only Core's default protected-key denial under explicit administrator opt-in. The narrowly scoped temporary authorization callback preserves subsequent provider/mapped denials, additional primitive requirements, `do_not_allow`, and original `user_has_cap` object/key context. Registered/global/subtype metadata authorization and explicit authorization filters, including priority zero, are never replaced. Delete additionally requires Users & Destructive.

The term store is separate because term identity, subtype resolution, shared-term behavior and lifecycle differ from posts. It is fixed to `$wpdb->termmeta`: two fixed prepared bounded reads, six fixed byte-exact update/delete CAS branches, and one fixed-column restoration of the original deleted row. There is no client-supplied table, SQL/query fragment, physical row ID or schema selector. Key-only listing queries never load metadata values. Exact reads are limited to two rows and 1 MiB per stored value; SQL errors are not interpreted as absence. Static checks retain the existing post-store restrictions and separately confine the term store.

Term state identity includes physical row ID and typed raw bytes, preserving SQL `NULL` versus empty string and distinguishing absent rows from registered defaults. Stored serialization is preflighted as a complete scalar/array-only stream before native decoding, preventing object/enum autoloading. Decoding additionally disables classes and bounds depth; objects/resources, malformed/non-canonical serialization and structures that cannot round-trip through JSON are not generically exposed as values, replaced, or deleted. Sanitized output is checked before serialization/persistence. Core sanitization runs once, not twice.

Existing-row update/delete is guarded after the pre-mutation lifecycle hook, then byte-exactly conditions only the previously authorized physical row. Normal `add_term_meta` owns creation. Original-invocation add observation distinguishes actual row ownership from nested/provider-returned IDs. Create contention cleans only the unchanged Bridge-owned row; observer-modified state is not adopted or removed. Update compensation can restore only bytes the Bridge still owns; delete compensation restores only the original physical row ID and refuses to recreate a deleted/shared term's metadata. Compensating lifecycle events and metadata cache invalidation accompany actual compensation. All compensation paths pin the originally authorized taxonomy and term-taxonomy row identity, then recheck that identity before and after their pre-mutation hooks. They refuse to touch a transferred/replaced target. Only removal of an unchanged row created by the current invocation may proceed when the term has disappeared, to avoid leaving that invocation's orphan behind; restoration never recreates an orphan. Compensation is not an unrestricted rollback of concurrent writes.

The protocol provides bounded optimistic integrity, not serializable isolation. Trusted installed WordPress code can independently change state; newer writes after verification can stale a response immediately. A compensation failure is a diagnostic boundary requiring fresh inspection, not permission to overwrite newer state. The mutation log contains only ability/target type/target ID/status/error code, never term-meta keys, values, full payloads or credentials.

## Stale-write protection

Overwrite-sensitive content, post-metadata, term-metadata, and Workspace operations return change identities. A later update must present the expected current identity. If the object changed after inspection, the Bridge rejects the write and requires the caller to refresh.

Post metadata uses deterministic physical-row identity plus byte-exact row compare-and-swap. Verification is performed within the bounded persistence operation. Concurrent duplicate/add/update/delete interference detected before that verification completes is reported as stale; where the Bridge already changed one row, it performs row-scoped compensation and corresponding lifecycle actions rather than overwriting/deleting concurrent state. If the exact compensation predicate no longer matches, the operation returns a dedicated compensation failure instead of overwriting newer bytes or claiming success.

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

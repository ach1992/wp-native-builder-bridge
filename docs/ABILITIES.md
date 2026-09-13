# Ability reference

Bridge-owned Ability names use the `wp-native-builder/` namespace.

The baseline installation registers the core Bridge surfaces below. Optional Gravity Forms and Code Snippets fallbacks are registered only when their supported provider APIs are available. Astra and other suitable provider Abilities are reused rather than duplicated.

| Ability | Primary access group | Purpose |
| --- | --- | --- |
| `bridge-info` | Site Read | Bridge/dependency state and enabled groups. |
| `site-context` | Site Read | Bounded WordPress/theme/plugin/content-type context. |
| `abilities-read` | Site Read | Paginated public Core/Bridge/provider contract list and exact named schema inspection; never executes target callbacks or grants permission. |
| `integration-status` | Site Read | Optional-provider availability and observed Ability mode. |
| `content-read` | Site Read | Read eligible posts/pages/custom post types. |
| `content-upsert` | Builder Write | Create/update eligible content; Live Content is additionally required for live status. |
| `content-delete` | Users & Destructive | Trash/delete content with WordPress delete authority. |
| `revisions-read` | Site Read | Read revisions for one content object. |
| `revision-restore` | Builder Write | Restore a revision with stale-state checks. |
| `blocks-read` | Site Read | Parse Gutenberg blocks for eligible content. |
| `blocks-mutate` | Builder Write | Targeted Gutenberg append/insert/replace/remove with stale-state protection. |
| `post-meta-read` | Advanced Metadata | Discover physical post-meta keys or read one exact physical key for a WordPress post object the connected user may edit. Values are returned only for an explicitly named key. |
| `post-meta-update` | Advanced Metadata | Create/replace one single-value post-meta key with exact physical-row state identity and stale-write protection. Ambiguous or non-lossless cases fail closed. |
| `post-meta-delete` | Advanced Metadata + Users & Destructive | Delete one single-value post-meta row with exact physical-row state identity and row-scoped stale-write protection. |
| `term-meta-read` | Advanced Metadata | List bounded physical term-meta key/count summaries or read one exact key for an authorized `term_id` + `taxonomy` target. |
| `term-meta-update` | Advanced Metadata | Create/replace one losslessly representable term-meta value using exact physical state and row-level stale-write protection. |
| `term-meta-delete` | Advanced Metadata + Users & Destructive | Delete one exact term-meta row with stale-write protection; ambiguous or lossy values fail closed. |
| `media-read` | Site Read | Read Media Library attachments. |
| `media-upload` | Builder Write | Upload bounded file bytes through WordPress Media APIs. |
| `media-update` | Builder Write | Update bounded attachment metadata/parent. |
| `media-delete` | Users & Destructive | Permanently delete an attachment when WordPress permits it. |
| `terms-read` | Site Read | Read terms from eligible taxonomies. |
| `term-upsert` | Builder Write | Create/update a taxonomy term. |
| `terms-assign` | Builder Write | Assign existing terms to content. |
| `term-delete` | Users & Destructive | Delete a taxonomy term. |
| `navigation-read` | Site Read | Inspect classic menus, locations, and block navigation. |
| `classic-navigation-mutate` | Builder Write | Create/update/reorder classic navigation; permanent item removal is destructive. |
| `site-settings-read` | Site Read | Read the bounded site-settings allowlist. |
| `site-settings-update` | Site Configuration | Update bounded site settings. |
| `extensions-read` | Site Read | Read installed plugin/theme metadata. |
| `extension-lifecycle` | Code & Extensions | WordPress.org install/update/activate/deactivate; deletion is destructive. |
| `users-read` | Site Read | Read bounded user/role information without credential material. |
| `user-upsert` | Users & Destructive | Create/update a user and assign an editable role. |
| `user-remove` | Users & Destructive | Remove a user with explicit reassignment. |
| `workspace-resume` | Site Read | Return compact durable Workspace orientation. |
| `workspace-document` | Site Read / Builder Write | List/read/create/update/archive private Workspace documents. |
| `workspace-task` | Site Read / Builder Write | List/read/create/update/transition/archive private Workspace tasks. |

## Advanced Metadata boundary

`Advanced Metadata` is disabled by default and must be enabled by a WordPress administrator from **WP Native Builder → Settings**. It is intentionally provider- and post-type-neutral: the Bridge does not maintain an Astra/plugin/theme meta-key or CPT allowlist.

Once enabled, a real WordPress post object can be targeted when the connected WordPress user may edit that exact object. The post type does not need to be public, REST-exposed, or editor-capable. Bridge-private Workspace types (`wpnb_doc` and `wpnb_task`) remain explicitly excluded. Revision IDs are canonicalized to their parent post before metadata authorization, physical-state inspection, hashing, or mutation so the authorized object is the object whose post metadata is changed.

Protected/private keys (including keys beginning with `_`) can be reached under that administrator-controlled boundary. When Core or a provider explicitly registers a key or installs a post-meta authorization filter, that explicit authorization contract remains authoritative. Protected unregistered keys with no explicit authorization contract use the target post's `edit_post` authority once Advanced Metadata is enabled instead of WordPress's generic protected-meta default denial. Additional primitive capabilities or `do_not_allow` injected by `map_meta_cap` remain authoritative.

The generic metadata surface deliberately does not expose arbitrary WordPress options, user meta, Bridge Workspace internals, or credential-like metadata keys. Credential filtering is provider-neutral and normalizes separator, camelCase, compact, singular, and plural forms for password/secret/credential, API/private-key, OAuth/access/refresh, session, identity/ID, JWT, bearer/auth, and related security-token concepts. Unrelated metadata such as a design token is not blocked merely for containing the word `token`.

`state_hash` is computed from physical stored row identity (physical meta ID plus typed raw stored value) for the exact canonical key, not from registered default expansion and not from a `get_post_metadata` virtual-read short circuit. SQL `NULL` and an empty string are distinct states. An absent registered key and a stored row equal to that key's default are also distinct mutation states.

Existing-row update/delete is bound to the one inspected physical row through an internal fixed-schema, byte-exact compare-and-swap. The raw key/value predicate does not use database text-collation equivalence, and SQL `NULL` is matched with an explicit null branch. Identical or collation-equivalent concurrent rows/values therefore cannot be silently updated/deleted as if they were the inspected bytes.

Verification and compensation remain inside the persistence boundary. If interference is detected before verification completes, update restores only its own unchanged written row and delete restores only its own exact deleted row. The compensating change emits the corresponding WordPress metadata lifecycle actions so observers can reconcile to the final physical state. If the exact compensation predicate no longer matches newer bytes, the Bridge returns a compensation failure instead of overwriting them.

Creation uses normal WordPress `add_post_meta(..., true)` semantics. Core performs the one sanitizer pass and normal add hooks; the Bridge observes the resulting sanitized value without sanitizing it again. The returned meta ID counts as Bridge-owned only while the exact post/key/raw value is unchanged. If Core's non-atomic uniqueness check races, the Bridge removes only its still-unchanged created row and emits the corresponding delete lifecycle. If an `added_post_meta` observer changed that same row, the Bridge preserves the observer state and returns stale rather than cleaning it up.

The compare-and-swap helper is not a database tool exposed to callers: it accepts no caller-controlled SQL, table, column, query fragment, or row ID and is hard-bound to the authorized `wp_postmeta` row. Its fixed prepared raw update/delete statements exist only to provide byte-exact predicates that WordPress's generic text-column helpers cannot express. Generic SQL/database administration remains absent. This is an optimistic row-level integrity protocol, not a transaction/serializable-isolation guarantee; a later write after the verified operation can immediately make a returned state hash stale.

Metadata values containing PHP objects/resources at any depth, or other values that cannot round-trip through the generic JSON contract without structural loss, are not generically replaceable or deletable. Metadata deletion additionally requires **Users & Destructive**.

## Generic term metadata

The same default-off **Advanced Metadata** group controls `term-meta-read`, `term-meta-update`, and `term-meta-delete`. Every request requires both an integer `term_id` and its exact `taxonomy` name. Core categories, tags, and registered custom taxonomies (including non-public/non-REST taxonomies) use the same provider-neutral implementation. There is no taxonomy or meta-key allowlist and no WooCommerce/theme-specific adapter.

The term must resolve unambiguously through WordPress, agree with Core's metadata subtype, and pass `edit_term` for that exact target. Legacy shared term IDs are refused even when a taxonomy was supplied, because the physical term-meta owner and Core capability/subtype resolution would otherwise be ambiguous. Registered metadata and all explicit provider authorization filters remain authoritative. For a protected unregistered key only, a temporary exact user/term/key/operation authorization filter replaces Core's default protected-key denial; it does not remove any final `map_meta_cap` requirement or change the original `user_has_cap` context. This filter is removed before the Ability returns.

Read inputs add `page` (1..10000, default 1) and `per_page` (1..100, default 50). Without a key, items contain **only `key` and `count`**. They never contain values, value-derived hashes, or types. Pagination advances through physical key pages before authorization/secret filtering, so a page may be short or empty while `has_more` is true. Treat `has_more` as navigation, not a total-count promise; concurrent metadata changes may move keys between pages.

A named-key read returns the normal `key`, `count`, `state_hash`, `value_types`, and `values` fields inside `items`. `include_values=true` is allowed only for a named key. Read output also identifies `term_id`, `taxonomy`, `page`, `per_page`, and `has_more`. Exact reads inspect at most two physical rows and reject a multi-row key; listing can still report its count. Read that exact key again immediately before a mutation to obtain `expected_state_hash`.

Update accepts `key`, `value_json`, and `expected_state_hash` in addition to the target. It returns the verified metadata item. Delete accepts `key` and `expected_state_hash`, also requires **Users & Destructive**, and returns `term_id`, `key`, `deleted`, and `state_hash`. Deleting an already absent key with the current empty-state hash returns `deleted=false`.

Term values have a 1 MiB byte bound for submitted JSON and physical/sanitized stored data. PHP objects/enums/resources, excessive depth, non-finite numbers, malformed/non-canonical serialization, reference structures lost by JSON, integer overflow, and JSON object shapes that PHP's array contract would silently change are refused. For example, `{}` and an object consisting only of sequential numeric keys cannot be losslessly mapped by this interface. Safe scalars/arrays still use normal WordPress metadata coercion: a newly stored scalar is normally returned as a string, while SQL `NULL` is a distinct physical state. Existing opaque metadata may be inspected for type/state without exposing its value, but cannot be replaced or deleted generically.

Registered defaults and `get_term_metadata` virtual reads do not determine physical state. Creation uses Core `add_term_meta(..., true)` with one sanitizer pass; a non-null provider short circuit is refused instead of being treated as ownership of its returned row ID. Updates/deletes use a term-only fixed-schema persistence helper for byte-exact row CAS, normal term metadata lifecycle actions, cache invalidation, and bounded compensation. Create contention removes only the original invocation's unchanged row, including when other rows precede it; an observer-modified row is preserved and reported as stale. Compensation also pins the original taxonomy and term-taxonomy row identity; a concurrent taxonomy transfer or target replacement is not treated as the original authorized target. Compensation failure is explicit and requires fresh inspection rather than an automatic overwrite/retry.

This is an optimistic row-level integrity protocol, not a transaction or serializable isolation guarantee. A later external write may immediately stale a returned hash. Nothing in these abilities grants arbitrary SQL/options/user-meta/provider-table access, changes the administrator's group defaults, or deploys a release.

## Optional Code Snippets fallback

- `snippets-read`
- `snippet-upsert`
- `snippet-lifecycle`
- `snippet-delete`

## Optional Gravity Forms fallback

- `gravity-forms-read`
- `gravity-form-upsert`
- `gravity-form-status`
- `gravity-form-delete`

Provider-native Abilities discovered in the WordPress registry may also be available to the MCP client. Their schemas and permissions remain owned by the provider.

## Public Ability contract inspection

Use `wp-native-builder/abilities-read` with `action: "list"` (the default), optional exact `namespace`, case-insensitive `search` over public name/label/description, `page` (default 1) and `per_page` (default 25, maximum 100). Results sort by exact Ability name and report `total` and `total_pages`; all pages of the current registry are reachable. The registry is a live view, not an immutable snapshot across requests.

An exact `action: "get"` plus `name` returns the provider's actual input/output schemas. An absent native schema stays empty; the Bridge does not invent one. Public name, namespace, label, description, category, MCP type and the three standard boolean-or-null annotations are selected explicitly. Arbitrary provider metadata and callbacks are not returned. Explicit MCP opt-out remains authoritative for both list and exact reads, with no name-guessing bypass. Malformed MCP metadata and non-boolean inherited public flags fail closed, matching the pinned official Adapter exposure rule.

Both actions require **Site Read** and native `read` authority, including when called through the official Adapter. They do not call the target's permission or execute callback. `execution_permission: "not_evaluated"` means the target's real input and current authorization must be checked at execution; public discovery or a read-only annotation is never permission. `bridge-info` remains the source for the current Bridge group settings. The current groups do not uniformly govern all reused provider-native operations; see [architecture and coverage](ARCHITECTURE.md#delegation-current-behavior-and-required-evolution).

Responses are bounded to 1 MiB; an oversized or unrepresentable contract returns an explicit error, never a truncated schema. Reduce a list's page size or consult the native provider contract for a larger exact schema. Ordinary lists omit schemas and do not read object values, source files or private site data. Provider-authored public schemas/descriptions are the same public contract data the native inspection interfaces expose; providers must not embed credentials in them.

This capability supplements the existing compact `site-context` reuse hints. It does not implement missing administrative workflows or grant new write/executable authority. Required coverage remains defined by `MASTER-SPEC.md`, with implementation gaps described in `ARCHITECTURE.md`.

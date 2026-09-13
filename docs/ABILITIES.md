# Ability reference

Bridge-owned Ability names use the `wp-native-builder/` namespace.

The baseline installation registers the core Bridge surfaces below. Optional Gravity Forms and Code Snippets fallbacks are registered only when their supported provider APIs are available. Astra and other suitable provider Abilities are reused rather than duplicated.

| Ability | Primary access group | Purpose |
| --- | --- | --- |
| `bridge-info` | Site Read | Bridge/dependency state and enabled groups. |
| `site-context` | Site Read | Bounded WordPress/theme/plugin/content-type context. |
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
| `media-read` | Site Read | Read Media Library attachments. |
| `media-upload` | Builder Write | Upload bounded file bytes through WordPress Media APIs. |
| `media-import-url` | Remote Media + Builder Write | Stream a safe HTTP(S) resource into the Media Library using current upload/parent authority. |
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

## URL media import

`wp-native-builder/media-import-url` requires `url` and `filename`. Optional `post_id`, `title`, `caption`, `description`, and `alt_text` use the normal attachment contract. The result is the same attachment summary returned by `media-upload`. No server path, arbitrary headers, cookies, HTTP method, credentials, or package-install action is accepted.

An administrator must explicitly enable **Remote Media** and **Builder Write**. Existing settings without Remote Media remain disabled on upgrade. The current WordPress principal also needs `upload_files` and authority to edit any supplied parent. Permission is checked before HTTP and again before upload/attachment mutation.

Downloads use `wp_http_validate_url()` and `wp_safe_remote_get()` with native initial/redirect URL validation, verified TLS, a 30-second request timeout, at most five redirects, and uncompressed streaming to a WordPress-generated temporary file. The size limit is the current `wp_max_upload_size()` with a one-byte overflow sentinel, not the in-memory Base64 upload's 20 MiB ceiling. WordPress/hosting network and upload policies remain authoritative.

Only complete HTTP 200 responses with nonempty, permitted-size bytes and a consistent Content-Length (when supplied) are accepted. WordPress validates MIME/extension and creates the attachment and image metadata through its normal lifecycle. Executable filenames are rejected; media import never extracts or installs an extension package. Temporary files are removed on ordinary success/error paths; an attachment-insertion failure also removes this invocation's uploaded file. Source URLs, response bodies, and file payloads are not logged or returned in errors.

Import is not idempotent: importing the same URL again can create another attachment. An interrupted request is not proof of failure; inspect the Media Library before retrying. This is not a transaction or a sandbox for third-party hooks; process termination and provider side effects may require inspection and recovery. WordPress's own network policy and installed filters remain the runtime trust boundary; the Bridge does not override them or claim to replace host egress controls.

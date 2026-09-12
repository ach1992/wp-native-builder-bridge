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
| `post-meta-read` | Advanced Metadata | Discover post-meta keys or read one exact key for a WordPress post object the connected user may edit. Values are returned only for an explicitly named key. |
| `post-meta-update` | Advanced Metadata | Create/replace one single-value post-meta key with stale-state protection. Ambiguous multi-row keys and PHP-object replacement fail closed. |
| `post-meta-delete` | Advanced Metadata + Users & Destructive | Delete one single-value post-meta key with stale-state protection. |
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

Once enabled, a real WordPress post object can be targeted when the connected WordPress user may edit that exact object. The post type does not need to be public, REST-exposed, or editor-capable. Bridge-private Workspace types (`wpnb_doc` and `wpnb_task`) remain explicitly excluded.

Protected/private keys (including keys beginning with `_`) can be reached under that administrator-controlled boundary. When Core or a provider explicitly registers a key or installs a post-meta authorization filter, that explicit authorization contract remains authoritative. Protected unregistered keys with no explicit authorization contract use the target post's `edit_post` authority once Advanced Metadata is enabled instead of WordPress's generic protected-meta default denial.

The generic metadata surface deliberately does not expose arbitrary WordPress options, user meta, Bridge Workspace internals, or credential-like metadata keys. Updates and deletes require the exact `state_hash` returned by `post-meta-read`; generic mutation refuses ambiguous multi-row keys and PHP-object values rather than guessing or performing lossy conversion. Metadata deletion additionally requires **Users & Destructive**.

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

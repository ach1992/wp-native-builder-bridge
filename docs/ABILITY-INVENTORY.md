# v0.1 Ability inventory and safety map

This inventory is the Issue #5 review surface for Bridge-owned abilities. It records the primary access group and WordPress/provider authority. Object-level capabilities, live-state checks, stale-state checks, and provider-native visibility checks remain enforced in the implementation in addition to the primary group below.

The normal provider-absent installation registers 27 Bridge abilities. Gravity Forms and Code Snippets can add four bounded Bridge fallback abilities each, for a maximum Bridge-owned surface of 35. Astra and verified WooCommerce abilities are reused from their providers and are not re-registered by the Bridge.

| Ability | Primary group | Authority / additional boundary |
| --- | --- | --- |
| `bridge-info` | Site Read | `read`; environment/version only |
| `site-context` | Site Read | `read`; bounded site/current-user capability context |
| `integration-status` | Site Read | `read`; observed provider contracts only |
| `content-read` | Site Read | object/post-type read capability |
| `content-upsert` | Builder Write | post-type create/edit; Live Content for live status; full `state_hash` on update |
| `content-delete` | Users & Destructive | `delete_post`; dedicated destructive path |
| `revisions-read` | Site Read | `read_post` |
| `revision-restore` | Builder Write | `edit_post`; stale current-state identity; Live Content when applicable |
| `blocks-read` | Site Read | `read_post`; editor-capable content types only |
| `blocks-mutate` | Builder Write | `edit_post`; Live Content for live content; targeted content hash |
| `media-read` | Site Read | attachment read authority |
| `media-upload` | Builder Write | `upload_files`; bounded bytes to WordPress temp/sideload APIs only |
| `media-update` | Builder Write | `edit_post` on attachment |
| `media-delete` | Users & Destructive | `delete_post` on attachment |
| `terms-read` | Site Read | taxonomy visibility/read authority |
| `term-upsert` | Builder Write | taxonomy `manage_terms` / `edit_terms` |
| `terms-assign` | Builder Write | taxonomy assignment + object edit; Live Content for live objects |
| `term-delete` | Users & Destructive | taxonomy `delete_terms` |
| `navigation-read` | Site Read | `edit_theme_options` for administration detail |
| `classic-navigation-mutate` | Builder Write | `edit_theme_options`; item removal additionally Users & Destructive; mixed tool annotated destructive |
| `site-settings-read` | Site Read | `manage_options`; bounded allowlist only |
| `site-settings-update` | Site Configuration | `manage_options`; bounded allowlist; rewrite/front-page impact |
| `extensions-read` | Site Read | bounded installed plugin/theme metadata |
| `extension-lifecycle` | Code & Extensions | action-specific Core capability; deletion additionally Users & Destructive; WordPress.org slug-only install |
| `users-read` | Site Read | `list_users`; no credential/session material |
| `user-upsert` | Users & Destructive | create/edit/promote authority; generated credential never returned/logged |
| `user-remove` | Users & Destructive | `delete_user`; explicit reassignment; no self-removal |
| `gravity-forms-read` *(optional fallback)* | Site Read | Gravity Forms view capability; `GFAPI` only; suppressed by native `gravityforms/*` surface |
| `gravity-form-upsert` *(optional fallback)* | Builder Write | Gravity Forms create/edit capability; `GFAPI` only |
| `gravity-form-status` *(optional fallback)* | Builder Write | Gravity Forms edit capability; `GFAPI` only |
| `gravity-form-delete` *(optional fallback)* | Users & Destructive | Gravity Forms delete capability; `GFAPI` only |
| `snippets-read` *(optional fallback)* | Site Read | current `Code_Snippets\code_snippets()->get_cap()` |
| `snippet-upsert` *(optional fallback)* | Code & Extensions | provider-managed snippet lifecycle; scope validated by provider; Bridge never evaluates code |
| `snippet-lifecycle` *(optional fallback)* | Code & Extensions | provider-managed activate/deactivate/trash/restore; locked snippets rejected |
| `snippet-delete` *(optional fallback)* | Users & Destructive | provider-managed permanent deletion; snippet must already be trashed |

## Explicitly absent generic surfaces

The v0.1 Bridge does not expose arbitrary SQL, shell commands, process execution, raw filesystem paths, arbitrary package URLs, arbitrary `wp_options`, credential/session retrieval, or provider-table access. Managed Code Snippets code is the intentional narrow exception to generic code mutation: it is available only when that plugin's supported lifecycle API exists and remains behind Code & Extensions/provider capabilities. The Bridge never evaluates the submitted snippet itself.

Media upload accepts bytes plus a filename, writes exactly one WordPress-generated temporary path, and then hands the file to WordPress sideload/media APIs. A caller cannot supply a server path.

## Optimistic concurrency boundary

Full content update and revision restore compare `modified_gmt` plus the mutation-relevant `state_hash` immediately before the WordPress write. Targeted Gutenberg mutation compares `modified_gmt` plus the owned `post_content` hash. These are optimistic stale-write guards through supported WordPress APIs; the Bridge does not implement a private lock manager or direct SQL compare-and-swap layer.

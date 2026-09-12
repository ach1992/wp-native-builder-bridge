# WP Native Builder Bridge — Master Specification

Status: Canonical project specification

Repository: `ach1992/wp-native-builder-bridge`

Companion project: `ach1992/wp-native-builder`

## How to use this specification

This file owns durable project-level intent, architecture boundaries, non-goals, compatibility rules, and success criteria. It is intentionally not a live task log.

For current implementation truth use the code and tests on the current target branch. For active work use GitHub Issues and pull requests. For validation use CI tied to the relevant commit. For published state use immutable Git tags and GitHub Releases.

The complete pre-public-release specification and the detailed documents that existed before the v0.1.1 documentation cleanup are preserved unchanged under [`docs/maintainer/reference/v0.1-pre-release/`](./docs/maintainer/reference/v0.1-pre-release/). They are historical design evidence, not current task status.

## 1. Purpose

`wp-native-builder-bridge` is a small, free, self-hosted WordPress plugin that exposes the WordPress operations an AI site-building and administration assistant needs through machine-readable, permission-checked WordPress Abilities and MCP.

Its purpose is to reduce manual WordPress administration while keeping WordPress capabilities, explicit Bridge access groups, typed operations, and normal WordPress APIs as the primary safety boundary.

The plugin is the WordPress-side integration layer. The companion `wp-native-builder` project may plan and orchestrate site-building workflows, but the two repositories remain independently owned. This repository must not depend on chat history or on mutable state stored only in the companion repository.

### Administrator-controlled capability coverage

The accepted product direction includes broad legitimate WordPress administration, including URL-based media import and installed plugin/theme source-code editing. Implement generic workflows ahead of individual site requests where supported contracts exist; do not require a Bridge source change merely because a provider, taxonomy, metadata key, installed extension, or public download origin was not hardcoded previously.

This is a product requirement, not a claim that every capability is already implemented. The implementation and public Ability inventory must distinguish available, implemented-but-disabled, missing, and upstream/environment-blocked operations. New compatible registered provider Abilities should be reusable through their real schemas and permission callbacks rather than duplicated in provider-specific Bridge adapters. An absent or private third-party execution contract must remain an honest capability gap, not a fictional permission toggle.

[Issue #38](https://github.com/ach1992/wp-native-builder-bridge/issues/38) owns the bounded administration-coverage implementation slices. The separate term-metadata contract in [Issue #36](https://github.com/ach1992/wp-native-builder-bridge/issues/36) remains independently scoped. This direction does not authorize live permission changes, deployment, or release publication.

## 2. Architecture

Use official WordPress AI building blocks rather than implementing a parallel generic remote-administration stack.

```text
ChatGPT / MCP client
        |
        | HTTPS + OAuth / MCP
        v
WP Native Builder Bridge transport/auth boundary
        |
        v
Official WordPress MCP Adapter
        |
        | WordPress Abilities API / registry
        v
+---------------------------------------------+
| Registered WordPress Abilities              |
|                                             |
| - Bridge-owned typed abilities              |
| - compatible Core/provider abilities        |
+---------------------------------------------+
        |
        v
WordPress / Gutenberg / supported providers
```

The Bridge is both an Ability provider and an Ability-aware integration layer.

For every logical operation:

1. inspect the current WordPress Ability registry for a suitable stable provider Ability;
2. reuse that public contract directly when it is sufficient;
3. add only a thin normalization wrapper when a stable normalized Bridge contract is materially useful;
4. otherwise use a supported public WordPress/provider API to implement a bounded typed fallback;
5. when the public API cannot provide a required correctness, integrity, or concurrency guarantee, use the smallest fixed-purpose internal persistence primitive that is hard-bound to the already-authorized object/data model, accepts no caller-selected SQL/table/column/query/command surface, preserves relevant WordPress sanitization/cache/authorization semantics, fails closed on ambiguity, and has focused validation plus high-assurance review;
6. if no bounded safe mechanism exists, report a capability gap rather than guessing or opening a generic execution channel.

Public WordPress/provider APIs are the default implementation path, not an absolute prohibition on internal persistence needed to make a bounded typed operation correct.

Do not use fuzzy semantic matching to invoke unknown third-party operations merely because an Ability name or description looks similar.

## 3. Supported platform baseline

- WordPress 6.9+ because the Abilities API is part of WordPress Core from 6.9.
- Official `WordPress/mcp-adapter` installed and active for MCP exposure in the supported architecture.
- `wp-native-builder-bridge` installed and active.
- HTTPS for direct remote ChatGPT connections.
- Current development and CI baseline includes PHP 8.4.
- Minimum PHP support must follow the tested compatible intersection of the supported WordPress version and MCP Adapter; do not invent an untested compatibility promise.
- No WPVibe, SaaS bridge, external database, daemon, queue, or additional MCP server is required by default.
- Site plugins/themes such as Astra, Gravity Forms, Code Snippets, WooCommerce, ACF, SEO plugins, page builders, and caching plugins are optional site-stack dependencies, not Bridge installation requirements.

Do not bundle a private copy of MCP Adapter. Detect required dependencies at runtime and give a concise administrator-facing setup signal when they are unavailable.

## 4. Design principles

1. **WordPress-native** — use WordPress Core and supported provider contracts first; use a fixed-purpose internal persistence primitive only when a public API cannot provide a required correctness/integrity guarantee, never as a generic administration surface.
2. **Typed abilities** — expose specific operations with closed schemas, not arbitrary execution.
3. **Capability enforcement** — every Bridge-owned operation checks the current WordPress user's authority.
4. **Admin-controlled exposure** — powerful groups are disabled until explicitly enabled.
5. **Reuse first** — prefer suitable registered Abilities over duplicate Bridge tools.
6. **Fill gaps, do not mirror everything** — Bridge-owned code exists only where it adds required coverage, normalization, or safety.
7. **Reversible editing** — preserve revisions where WordPress provides them and protect overwrite-sensitive operations from stale writes.
8. **Stack-adaptive coverage** — generic WordPress registrations should work wherever public contracts make that safe.
9. **Provider convergence** — provider-specific fallbacks should be removable when upstream publishes a suitable stable Ability.
10. **Small administration surface** — keep settings and permission concepts understandable.
11. **No helper-plugin dependency sprawl** — the baseline remains MCP Adapter plus this Bridge.
12. **Recoverable development** — project intent and development rules must be discoverable from the repository and GitHub control plane without chat history.

## 5. Security boundary

Security is layered:

| Layer | Responsibility |
| --- | --- |
| OAuth / MCP transport | Authenticate the remote client/session and bind it to the intended WordPress installation. |
| WordPress user | Own effective WordPress capabilities. |
| Bridge access groups | Decide which classes of Bridge operations are exposed. |
| Ability permission callback | Enforce the required access group and WordPress capability. |
| Ability implementation | Validate input, apply object-level checks, and use supported APIs or narrowly bounded internal correctness primitives. |
| Existing provider Ability | Retain its own registered permission callback and public contract. |
| AI workflow | Follow any higher-level user approval requirement for consequential work. |

Enabling a Bridge group never grants a WordPress capability the connected user does not already have. The deliberate Advanced Metadata rule for protected unregistered post metadata is not a new WordPress role capability: the administrator explicitly exposes that Bridge surface, the connected user must still have `edit_post` authority for the exact target object, and explicit Core/provider metadata authorization contracts remain authoritative when present.

Configurable administrative policy is distinct from authentication and integrity protection. Administrative settings may widen legitimate workflows within WordPress and hosting constraints; they must not disable target authority, provider denial, path containment, SSRF protection, resource bounds, stale-write protection, or privacy-safe logging. Ordinary generic data/provider routes must not enable their own access policy or bypass a disabled elevated-code boundary.

Executable code is a separate trust boundary. Once an administrator authorizes executable source changes, snippets, or extension installation, the resulting PHP runs with the WordPress runtime's authority and can affect application data and policy. Access groups, secret-key exclusions, and mutation logs are not a sandbox against deliberately authorized PHP. Entry authorization and exact change/recovery controls still apply, but documentation must not promise containment or transactional reversal of already-executed code side effects.

### Explicit exclusions

Do not expose generic abilities equivalent to:

- a raw PHP evaluator;
- arbitrary SQL execution;
- arbitrary shell or WP-CLI execution;
- unrestricted filesystem read/write;
- arbitrary `wp_options` administration;
- arbitrary user-meta administration;
- executable package installation through ordinary media-upload or generic data routes;
- retrieval of credentials, salts, private keys, application passwords, bearer tokens, or secret configuration values.

A fixed-purpose internal persistence primitive used only to preserve the correctness of an already-authorized typed operation is not an exposed generic SQL/database surface. Such code must be hard-bound to the intended data model, accept no caller-selected SQL/table/column/query fragments, use prepared/structured WordPress database operations, remain statically constrained to its narrow owner, and be covered by focused real-runtime tests and review.

Legitimate elevated workflows require their own bounded contract and authorization model instead of silently expanding an unrelated Ability. Installed plugin/theme source editing and separately gated package workflows are accepted requirements, not permanent provider-specific prohibitions; they do not authorize a raw evaluator, unchecked HTTP proxy, unrestricted filesystem endpoint, or credential extraction.

## 6. Access groups

Keep a small grouped permission model rather than dozens of per-tool switches.

| Group | Typical scope | Default |
| --- | --- | --- |
| **Site Read** | environment, content, blocks, media, navigation, extensions, integrations, Workspace inspection | Enabled |
| **Builder Write** | drafts/content/blocks, media, taxonomies, navigation, forms, Workspace mutations | Disabled |
| **Live Content** | publish/update live content and other live-status transitions | Disabled |
| **Site Configuration** | bounded global WordPress/theme configuration | Disabled |
| **Advanced Metadata** | generic post-meta inspection/update for WordPress post objects the connected user may edit; delete additionally requires Users & Destructive | Disabled |
| **Code & Extensions** | supported managed snippets and plugin/theme lifecycle | Disabled |
| **Users & Destructive** | user/role administration and destructive operations | Disabled |

Settings changes require `manage_options`.

Powerful additions must remain disabled on both fresh installations and upgrades until an administrator explicitly enables their intended boundary. In particular, introducing plugin/theme source editing must not silently widen prior consent when Code & Extensions was already enabled. Use an explicit source-editing opt-in through a justified subpermission or small group; do not advertise it as available until its implementation and validation exist.

## 7. Ability contract

Bridge-owned Abilities use the namespace:

```text
wp-native-builder/<ability-name>
```

Every Bridge-owned Ability must have:

- a precise label and description;
- a closed JSON input schema;
- a JSON output schema where practical;
- a permission callback;
- deterministic errors (`WP_Error` where appropriate);
- no hidden side effects beyond the described operation;
- compact outputs that provide enough identity/state for the next decision without dumping broad site data unnecessarily.

The exact current Ability inventory and schemas are owned by the implementation and [`docs/ABILITIES.md`](./docs/ABILITIES.md).

Required capability families include:

- site/environment and connection inspection;
- posts, pages, and eligible custom post types;
- Gutenberg block inspection and targeted mutation;
- administrator-controlled generic post metadata for WordPress post objects, including protected/private metadata stored by themes/plugins/builders;
- media inspection, bounded upload/URL import/update/delete;
- taxonomies and terms;
- navigation/menu management;
- bounded site configuration;
- supported plugin/theme lifecycle operations and separately authorized source-file editing;
- users and roles behind the high-impact access group;
- Persistent Workspace documents/tasks/state;
- verified optional-provider integrations where the active stack supports them.

## 8. Content, metadata, blocks, and optimistic concurrency

Generic content and Gutenberg operations use an explicit eligibility predicate appropriate to authoring content. Internal/private Bridge storage must never become reachable merely because it is implemented as a WordPress post type.

Advanced Metadata is intentionally a separate, broader administrator-controlled surface. It must not use provider, post-type, or meta-key allowlists that force Bridge development for each legitimate `post_meta` workflow. Any real WordPress post object may be targeted when Advanced Metadata is enabled and the connected user can edit that exact object, regardless of whether the post type is public, REST-exposed, or editor-capable. Bridge-private Workspace post types remain explicitly excluded.

Protected/private unregistered metadata may use the exact target post's `edit_post` authority once Advanced Metadata is enabled; this deliberately supersedes WordPress's generic default denial that exists solely because the key is protected. If Core/provider code explicitly registered the key or installed a metadata authorization filter, that explicit authorization contract remains authoritative. Credential-like keys, including credential/session/identity/security token forms, remain outside the generic surface.

Metadata value discovery should be compact: broad inspection may enumerate authorized keys and state identity, but callers must name an exact key before receiving its value. Mutation identity must describe actual physical stored rows, including stable row identity and raw stored value, rather than registered defaults or a filter-short-circuited virtual metadata view. Ambiguous multi-row metadata and values that cannot be losslessly represented by the typed contract must fail closed rather than being guessed or collapsed. Metadata deletion also requires Users & Destructive access.

For existing single-row metadata, update/delete must bind to the exact inspected physical row so a concurrently introduced identical-value row cannot be overwritten or deleted as collateral. When public Core metadata mutation APIs cannot supply that single-row compare-and-swap guarantee, a fixed-purpose internal post-meta persistence helper may perform the exact-row conditional mutation and compensating restoration needed to preserve the caller's inspected state. For absent-state creation, prefer normal `add_post_meta(..., true)` semantics; if a concurrent create crosses Core's non-atomic uniqueness window, remove only the row created by the current invocation and return a conflict rather than leaving Bridge-created duplicate state.

The Advanced Metadata internal persistence helper is not a generic database abstraction. It is restricted to fixed `postmeta` identity/value operations after the Ability layer has already authorized one exact post/key, must not accept SQL/table/column/query text from callers, and must preserve relevant WordPress sanitization, cache invalidation, metadata hooks, and fail-closed behavior. Static checks and real WordPress integration tests must enforce that boundary.

For overwrite-sensitive full-content and Workspace mutations, likewise require current object identity/fingerprints sufficient to reject stale writes. If inspected state changed, return a conflict and require refresh instead of silently overwriting newer data.

Targeted block operations may use a block/content fingerprint appropriate to the mutation boundary while preserving unrelated blocks.

Destructive states and permanent deletion remain separately permission-gated.

## 9. Media, extension installation, and source editing

Media upload must use WordPress Media Library handling. Client input may contain file bytes and a filename, but must not select an arbitrary server filesystem path. Enforce the smaller of the configured WordPress upload limit and the Bridge's bounded absolute upload cap.

URL-to-Media-Library import is a required typed workflow, not an unchecked download proxy. Use bounded safe HTTP handling, validate redirects and destinations, apply WordPress upload/MIME/attachment handling, and clean up temporary files deterministically. Public origins must not require a hardcoded per-origin Bridge adapter. Importing media must never substitute for executable extension installation.

Plugin/theme installation must stay narrower than a generic uploader. The shipped installation baseline uses supported WordPress.org/provider lifecycle APIs. Additional package-source workflows require separate explicit authorization, validated package/target identity, and their own lifecycle/rollback contract; they must not become an arbitrary ZIP/PHP execution path through an unrelated upload Ability.

Plugin/theme source editing must provide actual discovery, authorized read, exact change preview, apply, and recovery for files discovered through WordPress's installed-extension and editable-file contracts. Enforce current `edit_plugins` or `edit_themes` authority, multisite/Super Admin rules, `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, and actual filesystem permissions. A host or WordPress policy denial must be explained, not silently overridden.

Resolve the exact installed extension and relative file, verify real-path containment, and reject traversal, symlink escapes, arbitrary server paths, and unrelated configuration/credential files. Bind changes to current file bytes/hash and candidate identity, reject stale state, verify persisted bytes, and retain an exact preimage with bounded private recovery storage. Recovery must not clobber concurrent legitimate edits. Reuse supported WordPress editor/lifecycle APIs where suitable, but verify their actual PHP validation, active/inactive/network-active, loopback-authentication, cache, and failure behavior rather than assuming those guarantees.

Recommend hooks, custom plugins, and child themes for routine customization because upstream updates can replace vendor/parent-theme edits; this is guidance, not a permanent Bridge ban on an explicitly authorized source edit. Changes to the Bridge, MCP Adapter, or other control-plane code require a connection-loss warning and a proven recovery mechanism, not an undisclosed provider blacklist. Source and diff output require elevated source access and must not enter ordinary list output or mutation logs.

These additions are accepted implementation requirements. The public Ability inventory must continue to describe only implemented behavior until each bounded slice is validated and integrated.

## 10. Persistent Workspace

Persistent Workspace is a first-class Bridge subsystem for durable project continuity inside WordPress.

Core requirements:

- Workspace documents and tasks are stored in private Bridge-owned WordPress object types.
- Workspace objects are explicitly excluded from generic content, Gutenberg, and Advanced Metadata operations.
- Current Workspace state is authoritative and must not depend on WordPress revisions being enabled or retained.
- Updates use explicit version/state identity and stale-write protection.
- Workspace exposes a compact typed surface for resume/orientation, documents, tasks, export, and explicit destructive clearing.
- Admin UX provides Dashboard, Documents, Tasks, Activity, and Settings views without exposing private storage as ordinary authoring content.
- Uninstall may remove disposable settings/auth metadata while preserving durable Workspace content unless an explicit destructive lifecycle action clears it.

Detailed original design rationale is preserved in the maintainer reference snapshot.

## 11. Optional integrations

Provider integration follows the same resolution order: verified native Ability first, then supported public provider API fallback when justified.

Durable rules include:

- **Astra / Astra Pro:** prefer Astra's registered Abilities when its Abilities feature is enabled; do not require a second Astra MCP server. Generic post metadata stored by Astra remains reachable through Advanced Metadata without an Astra-specific Bridge meta schema.
- **Gravity Forms:** prefer stable native provider Abilities; otherwise use a bounded GFAPI fallback when available.
- **Code Snippets:** manage snippets only through the provider's supported lifecycle/API surface; Bridge code must never directly evaluate submitted snippet code.
- **WooCommerce:** reuse verified provider Abilities for bounded site-building operations; Advanced Metadata does not automatically become broad order/customer/commerce administration beyond the exact post objects and metadata the connected WordPress user is authorized to edit.

Other providers may be added only through the same evidence-based contract rules.

## 12. OAuth and MCP transport

The supported direct connection model uses WordPress-backed OAuth and an HTTPS MCP endpoint suitable for ChatGPT Workspace Apps, with the official MCP Adapter underneath the Bridge's Ability exposure.

Authentication behavior is version-sensitive. Verify current ChatGPT and MCP Adapter requirements from current primary documentation when modifying this boundary.

Do not add a custom proxy, tunnel, identity platform, or alternate transport merely for architectural completeness. Add one only when a real supported deployment requirement makes it necessary and the change preserves the typed WordPress Ability architecture.

## 13. Logging and privacy

Mutation/activity logging must be bounded and metadata-oriented. Do not log secrets, bearer tokens, passwords, metadata keys/values, file payloads, arbitrary content bodies, or other sensitive request payloads merely for debugging convenience.

Outputs and logs should minimize data to what is needed for operation, diagnosis, or the next workflow decision.

## 14. Compatibility and evolution

- Preserve WordPress-native behavior and public provider contracts rather than private implementation details.
- Prefer public Core/provider APIs, but do not weaken an accepted integrity/concurrency guarantee merely because Core lacks an atomic public primitive; use the bounded internal persistence rule instead of opening a generic execution/data surface.
- Keep provider-specific code isolated enough to retire when upstream support makes it redundant.
- Re-check version-sensitive upstream APIs before relying on them.
- Avoid hardcoded provider/post-type/meta-key allowlists where an administrator-controlled generic WordPress contract is the correct boundary.
- Do not silently broaden permissions when adding support for a new provider or content type; use existing generic contracts only within their documented access-group boundary.
- Public Ability contract changes that would break established workflows require deliberate versioning/release treatment.

## 15. Validation and release discipline

Development quality must include, as applicable:

- strict Composer validation;
- PHP syntax checks;
- functional/unit tests;
- WordPress Coding Standards;
- PHP compatibility checks;
- Persian localization/catalog validation;
- static checks for prohibited generic execution/data surfaces and for confinement of any fixed-purpose internal persistence helper;
- isolated WordPress integration tests against the supported baseline and current WordPress;
- OAuth/MCP discovery and representative execution paths;
- optional-provider compatibility where the repository claims support;
- release ZIP construction and validation from the exact release candidate.

A release candidate is identified by an exact commit. Merge/release evidence must correspond to that candidate or to a verified equivalent tree after integration.

Published tags are immutable. Never force-move a public version tag; use a new patch/minor/major release as appropriate.

The plugin package should contain only runtime/user-facing release material. Maintainer/recovery references remain repository-only unless a future packaging decision explicitly changes that boundary.

## 16. Documentation model

Public documentation should explain the product as it exists now: installation, connection, permissions, capabilities, integrations, security, troubleshooting, and normal development/testing.

Do not put chat transcripts, Master handoffs, recovery summaries, resolved Issue narratives, or temporary release coordination into the public README.

Maintainer/recovery material belongs under [`docs/maintainer/`](./docs/maintainer/) and should contain only durable architecture, source-of-truth pointers, and reference material that materially helps future development.

## 17. Non-goals

The project is not intended to become:

- a generic remote shell or database console;
- a secrets/credential extraction API;
- a full security/identity product replacing WordPress permissions;
- a SaaS control plane;
- a second general-purpose MCP server stack when the official Adapter is suitable;
- a plugin-pack installer whose purpose is merely to increase AI tool count;
- a duplicate of every Ability exposed by Core or third-party providers;
- a chat-history-dependent project management archive.

## 18. Success model

The project is successful when a supported WordPress site can install the Bridge and official MCP Adapter, connect a compatible ChatGPT/MCP client, discover a bounded useful WordPress capability surface, perform authorized site-building/admin operations through typed contracts, preserve WordPress permission boundaries, and continue project work through the Persistent Workspace without relying on conversation history.

The repository itself must remain recoverable: a future maintainer with no access to this chat can identify project intent, architecture, current implementation, active work, validation state, and published release state from authoritative repository/GitHub sources.

## 19. Source of truth and recovery

Use this order when resuming development:

1. **Project intent and durable constraints:** this `MASTER-SPEC.md`.
2. **Current product/user behavior:** `README.md` and the current files under `docs/`.
3. **Exact implementation:** source code and tests on the target branch.
4. **Active work, dependencies, and decisions:** GitHub Issues and pull requests.
5. **Validation:** GitHub Actions/CI tied to the exact relevant commit.
6. **Published state:** immutable tags and GitHub Releases.
7. **Deeper historical design evidence:** `docs/maintainer/reference/`, only when a current decision requires it.

Start with [`docs/maintainer/README.md`](./docs/maintainer/README.md) for the maintainer recovery map.

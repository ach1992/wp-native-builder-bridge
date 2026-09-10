# WP Native Builder Bridge — Master Specification

Status: Canonical project specification
Repository: `ach1992/wp-native-builder-bridge`
Companion project: `ach1992/wp-native-builder`

## 1. Purpose

`wp-native-builder-bridge` is a small, free, self-hosted WordPress plugin that exposes the WordPress operations an AI site-building assistant needs through machine-readable, permission-checked abilities.

Its purpose is practical: reduce manual WordPress administration while the companion `wp-native-builder` Skill plans, designs, edits, reviews, and maintains sites.

The plugin should provide broad useful access without becoming a large security platform or a proprietary service. The WordPress administrator decides which access groups are enabled, receives clear warnings for powerful access, and WordPress capabilities plus the MCP transport authentication remain the primary authorization boundary.

## 2. Architecture

Use the official WordPress AI building blocks rather than implementing MCP from scratch.

```text
AI / MCP client
      |
      | MCP
      v
Official WordPress MCP Adapter
      |
      | WordPress Abilities API / registry
      v
+--------------------------------------------+
| Registered WordPress abilities             |
|                                            |
| - WP Native Builder Bridge abilities       |
| - compatible abilities from Core/plugins   |
+--------------------------------------------+
      |
      v
WordPress / Gutenberg / supported plugins
```

The Bridge is both an ability provider and an ability-aware integration layer. It should discover suitable abilities already registered by WordPress Core or installed plugins and reuse them where their public contract, permissions, and behavior satisfy the WP Native Builder use case. It should register its own typed abilities only for capability gaps or where a thin normalization wrapper materially improves the companion Skill's reliability.

### Required platform baseline

- WordPress 6.9+ because the Abilities API is part of WordPress core from 6.9.
- Official `WordPress/mcp-adapter` installed and active for MCP exposure in the initial supported architecture.
- `wp-native-builder-bridge` installed and active.
- No WPVibe or paid/SaaS bridge dependency.
- No additional MCP server, broad ability-pack plugin, or helper integration plugin is required by default.
- Existing site plugins such as Astra/Astra Pro, Gravity Forms, and Code Snippets Pro remain optional site-stack dependencies only when the site actually uses them; they are not installed merely to enlarge the AI tool surface.
- Primary development/test environment should include current WordPress and PHP 8.4; minimum PHP support must follow the tested compatible intersection of the target WordPress version and MCP Adapter rather than an invented version promise.

Do not bundle a private copy of MCP Adapter. Detect it at runtime and show a concise admin notice/setup instruction when it is unavailable. If a future compatible MCP transport/adapter is demonstrably a better supported fit, the transport boundary may be adapted without changing the Bridge's WordPress ability architecture. Do not run multiple overlapping MCP server/transport plugins by default merely to gain more tools.

## 3. Design principles

1. **Simple administration** — a small settings screen, understandable access groups, clear warnings.
2. **Broad useful coverage** — expose the operations needed to inspect, build, edit, and administer a WordPress site.
3. **Typed abilities** — specific operations with JSON schemas, not an arbitrary code execution endpoint.
4. **WordPress-native implementation** — use public WordPress and supported plugin APIs.
5. **Capability enforcement** — every Bridge-owned ability checks the current WordPress user's required capability.
6. **Admin-controlled exposure** — powerful Bridge ability groups can be disabled entirely.
7. **Reversible content editing** — preserve WordPress revisions and detect obvious stale writes.
8. **No unnecessary infrastructure** — no SaaS account, external database, daemon, queue, or custom identity platform.
9. **Reuse existing abilities first** — when WordPress Core or an already-installed plugin exposes a compatible, stable Ability, prefer discovery/reuse over implementing a duplicate Bridge tool.
10. **Fill gaps, do not mirror everything** — Bridge-owned abilities should provide missing capabilities, required normalization, or safer WP Native Builder-specific behavior rather than blindly duplicating the whole registry.
11. **No helper-plugin dependency sprawl** — the baseline installation remains MCP Adapter plus this Bridge; do not require extra MCP/ability plugins just to avoid implementing a bounded missing operation.
12. **Fast delivery** — avoid architecture that does not materially improve the first useful release.

## 4. Security boundary

The bridge is intentionally not a complete security product. Security responsibilities remain layered:

| Layer | Responsibility |
|---|---|
| MCP Adapter / transport | Authenticate the remote MCP client/session according to the supported transport configuration |
| WordPress user | Own the effective WordPress capabilities |
| Bridge settings | Decide which Bridge ability groups are exposed/enabled |
| Ability permission callback | Require the appropriate WordPress capability and enabled access group for Bridge-owned operations |
| Ability callback | Validate inputs and use safe WordPress/plugin APIs |
| Existing external Ability | Retains its own registered permission callback and public contract; do not bypass it when reused |
| AI workflow | Obtain user approval where the user's workflow requires it, including live publishing |

Enabling an ability means the authenticated WordPress user may invoke it. It does **not** mean an AI should skip a user-level approval requirement from the companion Skill.

### Explicit exclusions

Do not expose generic abilities equivalent to:

- arbitrary PHP execution;
- arbitrary SQL execution;
- arbitrary shell/WP-CLI execution;
- unrestricted filesystem read/write;
- retrieval of credentials, auth salts, private keys, application passwords, or secret configuration values.

If a future real use case needs a privileged operation, add a bounded typed ability for that operation instead of opening an arbitrary execution channel.

## 5. Admin settings model

Keep the UI small. Use one settings page under WordPress administration, for example:

```text
Settings -> WP Native Builder
```

The page should show:

- connection/dependency status;
- enabled access groups;
- concise warning text for high-impact groups;
- effective MCP endpoint/setup information when it can be detected safely;
- a small recent-action view if action logging is enabled/implemented.

### Access groups

Use a small number of grouped switches rather than dozens of per-tool permissions.

| Group | Typical scope | Default |
|---|---|---|
| Site Read | environment, configuration summary, themes/plugins, content, blocks, media, forms, navigation inspection | Enabled |
| Builder Write | create/update drafts/content/blocks, media, taxonomies, navigation, forms | Disabled |
| Live Content | publish/update live content and other status transitions | Disabled |
| Site Configuration | supported global settings, theme/Astra configuration | Disabled |
| Code & Extensions | supported snippets plus plugin/theme lifecycle operations | Disabled |
| Users & Destructive | user/role administration and supported delete operations | Disabled |

The exact number of switches may be reduced during implementation if multiple groups have identical capability/risk behavior. Keep the mental model simple.

Settings changes themselves require `manage_options`.

## 6. Ability naming, discovery, and contract

Bridge-owned abilities use the namespace:

```text
wp-native-builder/<ability-name>
```

Every Bridge-owned ability must have:

- a precise label/description;
- JSON input schema;
- JSON output schema where practical;
- a permission callback;
- deterministic error responses (`WP_Error` where appropriate);
- no hidden side effects beyond the described operation.

Prefer compact tool outputs that give an AI the identity and state needed for the next decision rather than dumping the whole database/site.

### Existing Ability reuse policy

Before implementing/registering a Bridge-owned ability for a logical operation:

1. inspect the current WordPress Abilities registry for a compatible Ability already provided by Core or an installed plugin;
2. verify its public contract, permission behavior, side effects, stability, and output are suitable for the intended operation;
3. if suitable, prefer using that Ability directly rather than registering a duplicate Bridge tool;
4. if the external Ability is almost suitable but the companion Skill needs a stable normalized contract or additional bounded behavior, add only the smallest thin Bridge wrapper justified by that gap;
5. if no suitable Ability exists, implement the missing operation in the Bridge using supported WordPress/plugin APIs;
6. never bypass the external Ability's permission model, and never treat existence of an Ability as authorization for the companion Skill to perform a consequential action;
7. do not automatically install a helper plugin solely to gain an Ability. The default install must remain useful with the official MCP Adapter plus this Bridge alone.

Ability discovery is a reuse optimization and future-compatibility mechanism, not a reason to make every external Ability part of the Bridge's permanent public contract.

## 7. Required ability surface

The first usable release should cover the following functional surface. A requirement may be satisfied by a suitable existing registered Ability or by a Bridge-owned implementation. The goal is reliable capability coverage, not duplicate tools. Implementation may combine closely related operations when one typed operation with an explicit `action` field is clearer and safer than many tiny tools.

### 7.1 Site and environment inspection

Required read capabilities:

- WordPress version, locale, timezone, home/site URLs, permalink mode, front-page configuration;
- current user identity/capabilities relevant to the bridge;
- active theme and parent theme;
- installed/active plugin summaries;
- registered post types and taxonomies relevant to editing;
- site language/direction where discoverable;
- high-level editor/theme feature availability;
- bridge and MCP Adapter version/status;
- relevant registered Ability discovery information when needed for capability resolution.

Do not return secrets from arbitrary options.

### 7.2 Content and custom post types

Support WordPress posts, pages, and registered editable custom post types through suitable existing Abilities or normal WordPress APIs:

- list/search/get;
- create;
- update title/content/excerpt/status/template/featured image and supported public metadata;
- change status including draft/publish when the corresponding access group is enabled;
- trash/delete when destructive access is enabled;
- list revisions;
- restore a revision where WordPress supports it.

Preserve unrelated fields when performing targeted updates.

### 7.3 Gutenberg blocks

Provide AI-friendly block operations:

- parse a post/page into a structured block tree;
- return block names, attributes, hierarchy, content summaries, and stable-enough change identity;
- create/insert/replace/update/remove a targeted block or block subtree;
- serialize back to valid block markup;
- preserve unrelated blocks.

For overwrite-sensitive updates, require current object identity such as `modified_gmt` plus a targeted content/block fingerprint when practical. If the expected identity no longer matches, return a conflict instead of silently overwriting newer content.

Create a WordPress revision through normal content update behavior before replacing existing published content whenever WordPress revisions apply.

### 7.4 Media

Support:

- list/search/get media metadata;
- upload media;
- update title/caption/description/alt text;
- attach/set featured media where supported;
- delete only when destructive access is enabled.

Use WordPress upload handling and MIME validation. Do not accept arbitrary server paths from the client.

### 7.5 Taxonomies

Support registered editable taxonomies:

- list/search/get terms;
- create/update terms;
- assign/unassign terms to content;
- delete terms only when destructive access is enabled.

### 7.6 Navigation

Support the navigation mechanism available on the site without forcing one WordPress-era representation:

- inspect current navigation/menu structures;
- create/update/reorder/remove menu/navigation items;
- preserve unrelated navigation state;
- expose enough location/context information for an AI to choose the correct menu or navigation block.

Use suitable existing Abilities when present; otherwise use public WordPress APIs and feature detection.

### 7.7 Astra / Astra Pro

When Astra/Astra Pro is active, first check whether the installed version exposes suitable registered Abilities or another documented public interface. Prefer native registered Abilities when their contract is stable and sufficient; otherwise expose only operations that can be implemented through supported/public WordPress/Astra interfaces rather than undocumented database internals.

Target capabilities:

- identify Astra/Astra Pro and relevant feature availability;
- inspect layout/container/typography/color/header/footer configuration useful to page design;
- update supported theme/global settings when `Site Configuration` access is enabled;
- inspect and manage Astra-provided global/custom-layout content when a stable supported WordPress/API surface exists.

If a specific Astra feature lacks a stable public Ability/API, report it as unsupported instead of silently binding the bridge to fragile private internals. A generic WordPress custom-post-type operation may be used only when the underlying registered type and capabilities make that safe and supportable.

### 7.8 Gravity Forms

When Gravity Forms is active, first prefer suitable registered Abilities if the installed version provides them. Otherwise, when its public API is available, support through `GFAPI` or another documented public interface:

- list/get forms;
- create/update forms;
- activate/deactivate where supported;
- add/update/reorder/remove fields through complete form updates as required by the API;
- inspect entries only when an explicit future use case requires it and the applicable access group/capability is enabled;
- delete forms only under destructive access.

Do not reimplement form storage directly in the database.

### 7.9 Code Snippets Pro

When Code Snippets Pro is active, first prefer suitable registered Abilities if the installed version provides a stable public contract. Otherwise integrate only through its current documented public API/REST/WP-CLI/programmatic interfaces that are suitable for third-party use.

Target capabilities when support is verified:

- list/get snippets;
- create/update snippet content and metadata;
- activate/deactivate snippets;
- delete only under destructive access.

Managed snippet content may include PHP, CSS, JavaScript, HTML, or other snippet types supported by the verified Code Snippets interface. The exclusion of an `execute-arbitrary-php` Ability does **not** prohibit creating, updating, or activating managed PHP snippets through Code Snippets Pro. Treat activation/deactivation as explicit managed lifecycle operations; do not execute supplied PHP directly inside the Bridge request callback.

Do not manipulate Code Snippets database tables directly. Do not fall back to arbitrary PHP execution if the plugin does not provide a stable integration interface.

### 7.10 Plugin and theme administration

When `Code & Extensions` is enabled and the current WordPress user has the required capabilities, support normal WordPress lifecycle operations through suitable existing Abilities or WordPress APIs:

- list/get plugin/theme state;
- install from WordPress-supported sources or a clearly supplied package URL when WordPress itself accepts that source;
- activate/deactivate plugins;
- update plugins/themes;
- activate a theme;
- delete only when destructive access is enabled.

Do not silently edit third-party plugin/theme source files.

### 7.11 Site configuration

When `Site Configuration` is enabled, provide typed operations for the site settings genuinely useful to site building, whether through suitable existing Abilities or Bridge-owned operations, such as:

- site title/tagline;
- front page/posts page;
- reading/discussion settings that are safe and relevant;
- permalink structure with explicit impact information;
- supported theme mods/settings;
- other bounded configuration added as a named field/ability when needed.

Do not expose unrestricted arbitrary option enumeration or arbitrary `update_option` access.

### 7.12 Users and roles

When `Users & Destructive` is enabled and WordPress capabilities allow it, support bounded user/role administration needed for site management through suitable existing Abilities or WordPress APIs:

- list/get users without password/auth-secret material;
- create/update users and role assignment;
- remove users only with explicit reassignment semantics and destructive access;
- inspect roles/capabilities;
- perform bounded role/capability changes using WordPress APIs.

Never return password hashes, application passwords, session tokens, or other credential material.

## 8. Live publishing and approval semantics

The bridge must expose live status/publish operations when the administrator enables `Live Content`; otherwise an AI could never complete an approved publish request.

However, the bridge does not attempt to reproduce the user's conversational approval workflow. The companion `wp-native-builder` Skill requires explicit user approval immediately before live publishing and other consequential/global/destructive actions.

Therefore:

```text
Bridge permission = operation is technically available.
User approval = AI is authorized to perform that specific consequential action now.
```

These are separate concepts. Reusing an existing external Ability does not weaken this distinction.

## 9. Validation, sanitization, escaping, and permissions

Follow WordPress coding/security standards without turning the plugin into a policy engine.

At minimum:

- validate every structured input against the ability contract;
- sanitize input according to its semantic type;
- use WordPress APIs that perform their own domain validation where available;
- escape only at HTML rendering boundaries; do not corrupt structured API data by blanket escaping;
- perform capability checks in every Bridge-owned ability permission callback;
- preserve and respect the permission callbacks/contracts of reused external Abilities;
- require `manage_options` for bridge settings;
- use nonces for admin-browser form actions;
- never trust a client-provided user ID/capability as authorization;
- avoid logging secrets or full sensitive payloads.

## 10. Recent-action logging

Keep logging intentionally lightweight.

For mutating Bridge operations, retain a bounded recent record sufficient for troubleshooting, for example:

- timestamp;
- authenticated WordPress user ID;
- ability name;
- target object type/ID when applicable;
- success/failure and error code.

Do not log credentials, auth headers, full snippet contents, full page bodies, or other unnecessary payloads. Use a bounded WordPress option or another similarly simple storage mechanism unless implementation evidence justifies something more complex.

When an external Ability is invoked directly outside a Bridge wrapper, do not pretend the Bridge has logged or governed an operation it did not execute. Rely on the provider's own behavior and the companion Skill's approval rules.

## 11. MCP transport and authentication

Treat the official MCP Adapter as the initial transport implementation.

The current official adapter exposes an HTTP endpoint for the default server and supports WordPress permission callbacks. Current MCP-client authentication behavior is version-sensitive, so end-to-end compatibility with ChatGPT's current remote MCP app flow must be proven during integration testing rather than assumed from protocol support alone.

Preferred path:

1. use the official MCP Adapter HTTP transport directly if ChatGPT can authenticate to it with a supported mechanism;
2. reuse WordPress Application Passwords or another existing WordPress/MCP-Adapter-supported mechanism when compatible;
3. only if current ChatGPT authentication requirements make direct connection impossible, implement the smallest bounded custom transport/auth layer needed for interoperability;
4. if a future supported replacement for MCP Adapter materially simplifies or improves the same transport role, evaluate it as a single transport alternative rather than stacking overlapping MCP servers.

Do not build OAuth, JWT, a proxy service, or a custom MCP server preemptively.

The plugin/setup documentation must also account for caching/security headers on the MCP route in real WordPress hosting stacks and verify the current official adapter behavior before adding local workarounds.

## 12. Compatibility and extension rules

- Core WordPress functionality must not require Astra, Gravity Forms, Code Snippets Pro, or any helper MCP/ability-pack plugin.
- The default connection stack is the official MCP Adapter plus WP Native Builder Bridge.
- Optional site integrations activate only when their dependency is already available and a supported Ability/API exists.
- Before implementing an integration operation, check for a suitable registered Ability exposed by the installed dependency and reuse it when appropriate.
- If an optional integration is unavailable, return clear capability/discovery information instead of fatal errors.
- Do not automatically install another MCP server or helper ability plugin solely to gain more tools.
- Do not register a duplicate Bridge Ability when an existing stable Ability already satisfies the same contract, unless a thin wrapper is justified by normalization, security, or companion-Skill compatibility.
- Avoid direct database coupling to third-party plugins.
- Avoid global namespace pollution; use a project-specific PHP namespace/prefix.
- Make user-facing strings translation-ready with English source strings.
- Preserve RTL/LTR neutrality in the plugin admin UI.

## 13. Repository and implementation shape

Keep the codebase conventional and small. A likely shape is:

```text
wp-native-builder-bridge/
├── wp-native-builder-bridge.php
├── src/
│   ├── Plugin.php
│   ├── Admin/
│   ├── Abilities/
│   ├── Integrations/
│   └── Support/
├── tests/
├── readme.txt
└── README.md
```

Do not create a framework of abstractions before repeated behavior proves it useful. Ability resolution/reuse should remain a small focused mechanism rather than becoming a generic plugin orchestration framework.

## 14. Testing and quality

The release should have automated high-signal coverage for:

- ability registration/discovery;
- existing Ability resolution/reuse when a compatible provider is present;
- Bridge fallback registration/behavior when a compatible external Ability is absent;
- avoiding unnecessary duplicate tool registration;
- permission denial and disabled access groups;
- content CRUD and revision-safe updates;
- Gutenberg parse/targeted update/serialization;
- media and navigation happy paths;
- optional integration detection;
- Gravity Forms operations when the dependency is available in the test environment;
- managed Code Snippets lifecycle, including PHP snippets, when a supported interface is available;
- destructive/live operations remaining unavailable when their admin groups are disabled;
- stale-write conflict behavior;
- no credential material in standard inspection outputs;
- baseline functionality with no helper MCP/ability-pack plugin beyond MCP Adapter + Bridge.

Use WordPress-compatible testing infrastructure and CI. Prefer the official WordPress/MCP Adapter testing approach where practical, but do not require Docker or a large JavaScript toolchain at runtime in the production plugin.

## 15. Representative end-to-end scenarios

The bridge must make these workflows possible when the relevant access group is enabled:

1. Inspect the active site/theme/plugins and page structure.
2. Create a new draft page and populate native Gutenberg blocks.
3. Insert/update one Custom HTML section without replacing unrelated blocks.
4. Upload an image and use it in page content.
5. Create or update a Gravity Form and place/reference it from a page.
6. Inspect Astra settings and apply a supported site-building configuration change.
7. Publish an already-approved draft.
8. Install/activate/update a plugin after the AI has received the applicable user approval.
9. Create/update/activate a managed Code Snippet when a stable Code Snippets integration is available.
10. Reject a stale targeted page update after the page changed since the AI inspected it.
11. When an already-installed plugin exposes a suitable registered Ability for a required operation, use that Ability instead of creating/exposing an unnecessary duplicate Bridge implementation.
12. When that external Ability is absent, continue through the Bridge-owned fallback without requiring installation of a helper ability plugin.

## 16. Non-goals

- Replacing WordPress authentication/authorization.
- Building a hosted service.
- Building a complete security/governance platform.
- Implementing MCP protocol/transport from scratch when the official Adapter is fit.
- Requiring a collection of MCP servers, helper ability packs, or orchestration plugins.
- Installing extra plugins solely to enlarge the AI tool catalogue.
- Providing arbitrary PHP/SQL/shell/filesystem execution.
- Editing WordPress core or third-party source files directly.
- Supporting undocumented private internals merely to claim a larger feature list.
- Creating a complicated plugin UI or per-ability policy engine.
- Mirroring every registered external Ability into the `wp-native-builder/*` namespace.

## 17. Success criteria

The first complete release is successful when:

- installation on a current WordPress 6.9+ site is straightforward;
- the normal connection footprint requires only the official MCP Adapter plus WP Native Builder Bridge;
- MCP Adapter dependency/status is detected clearly;
- an administrator can understand and enable the desired access groups quickly;
- the bridge exposes a broad, discoverable typed ability surface covering ordinary site building and supported administration;
- compatible Abilities from already-installed plugins can be reused without making those providers mandatory dependencies or duplicating their functionality unnecessarily;
- the Bridge remains useful when no extra helper ability-pack plugins are installed;
- WordPress capability checks and admin switches reliably deny disabled/unauthorized Bridge-owned actions;
- an MCP client can inspect and modify a test WordPress site end-to-end;
- the companion Skill can create/edit/review a site with substantially less manual WordPress work;
- live/destructive capabilities exist when enabled but remain separately governed by the AI/user approval workflow;
- no arbitrary execution or credential-retrieval backdoor is introduced;
- core tests and CI pass and a normal installable WordPress plugin ZIP can be produced.

## 18. Delivery strategy

Move in a small number of vertical slices rather than designing every class first:

```text
Bootstrap + dependency + permissions
  -> Ability discovery/reuse + core inspection/content/blocks/media/navigation
  -> Optional integrations + advanced administration
  -> Safety/concurrency + automated tests
  -> Real MCP/ChatGPT integration test
  -> Documentation + installable release
```

The intended result is a small powerful bridge, not a large platform. Add complexity only when a real ability or verified interoperability requirement needs it. Reuse existing stable WordPress Abilities where that removes duplicate work without creating new dependency sprawl.
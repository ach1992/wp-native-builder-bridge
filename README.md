# WP Native Builder Bridge

A free, self-hosted WordPress plugin that exposes typed, permission-checked WordPress abilities for AI-assisted site building.

The bridge is the companion runtime for [`wp-native-builder`](https://github.com/ach1992/wp-native-builder). It exists to reduce manual WordPress admin work while keeping the site owner in control of which access groups are available.

## Repository ownership

This repository owns only the **WP Native Builder Bridge plugin/runtime**. A Master assigned to `ach1992/wp-native-builder-bridge` must not modify or administer the companion `ach1992/wp-native-builder` Skill repository unless the owner explicitly changes that assignment.

Cross-repository coordination is contract-based: when a Bridge decision changes what the Skill needs to know, persist the Bridge-side decision here and provide a concise handoff to the Skill Master. The Skill Master owns any Skill-side documentation, Issues, code, packaging, or release changes. Likewise, requirements arriving from the Skill are inputs to reconcile against this repository's current authoritative specification/issues; they do not authorize the Bridge Master to mutate the Skill repository or silently override Bridge-local contracts.

This ownership boundary applies to source files, documentation, Issues, branches, pull requests, releases, and project administration. It does not prevent read-only inspection of the companion project when a current interface/dependency must be verified.

## Architecture

```text
AI / MCP client
  -> official WordPress MCP Adapter
  -> WordPress Abilities API / registry
       -> compatible abilities already provided by WordPress/plugins
       -> WP Native Builder Bridge abilities for missing capabilities
  -> WordPress / Gutenberg / optional site integrations
```

The default connection stack is intentionally small: **MCP Adapter + WP Native Builder Bridge**. The project does not require a collection of MCP servers, helper ability packs, WPVibe, or another paid/SaaS WordPress bridge.

When WordPress Core or an already-installed plugin exposes a suitable stable Ability, the Bridge should prefer discovery/reuse over duplicating the same operation. If no suitable Ability exists, the Bridge fills that capability gap through supported WordPress/plugin APIs. Extra helper plugins are not installed merely to enlarge the AI tool catalogue.

## Target capabilities

- site/environment inspection;
- posts, pages, supported custom post types, revisions, and statuses;
- structured Gutenberg block inspection and targeted edits;
- media, taxonomies, and navigation;
- Astra/Astra Pro integration where supported public interfaces exist;
- Gravity Forms through its supported public API or suitable native Abilities when available;
- Code Snippets Pro where a stable supported integration API/Ability can be verified, including managed PHP/CSS/JavaScript/HTML snippet lifecycle where supported;
- site configuration;
- plugin/theme lifecycle operations;
- users/roles and destructive operations when explicitly enabled.

The bridge intentionally does **not** expose arbitrary PHP, SQL, shell/WP-CLI, unrestricted filesystem access, or credential retrieval. This does not prohibit managed PHP snippets: when the Code Snippets integration supports them, the bridge may create, update, activate, deactivate, and delete PHP snippets through the plugin's managed lifecycle instead of executing arbitrary PHP directly.

## Admin access model

The WordPress administrator controls a small set of grouped switches:

- Site Read
- Builder Write
- Live Content
- Site Configuration
- Code & Extensions
- Users & Destructive

WordPress user capabilities and MCP transport authentication remain authoritative. Bridge permission means an operation is technically available; it does not replace the companion Skill's requirement for explicit user approval before publishing or other consequential actions.

## Platform direction

- WordPress 6.9+ (Abilities API)
- official [`WordPress/mcp-adapter`](https://github.com/WordPress/mcp-adapter) as the initial/default MCP transport
- WP Native Builder Bridge as the project-specific capability layer
- current WordPress/PHP development baseline, with exact minimum PHP support finalized from tested compatibility
- no required helper MCP/ability-pack plugins
- no production runtime dependency on Node.js, Docker, or an external service

A future compatible transport may replace MCP Adapter if evidence shows that is a better supported path, but the normal architecture should use one transport rather than stacking overlapping MCP server plugins.

## Post-v0.1 Persistent Workspace

The accepted follow-on architecture adds a small WordPress-hosted Persistent Workspace so fresh companion-Skill chats can resume durable site-project context without receiving old chat history. This capability is **not implemented in v0.1 and does not block the v0.1 release path**.

Workspace uses private WordPress-native storage, dedicated typed abilities, explicit isolation from generic content/Gutenberg operations, and optimistic concurrency that does not depend on WordPress revision rows surviving. WordPress revisions may remain optional history; any required history that must survive ordinary revision pruning is owned by bounded Bridge-managed Workspace version/snapshot storage instead.

See [`docs/PROJECT-WORKSPACE-ARCHITECTURE.md`](./docs/PROJECT-WORKSPACE-ARCHITECTURE.md) and Issue #8 for the accepted Bridge-side contract and future implementation work.

## Project map

| Source | Purpose |
|---|---|
| [`MASTER-SPEC.md`](./MASTER-SPEC.md) | Canonical project-level architecture, ability surface, permissions, reuse policy, constraints, and completion criteria |
| [`docs/CORE-ABILITY-SAFETY-BOUNDARIES.md`](./docs/CORE-ABILITY-SAFETY-BOUNDARIES.md) | Canonical detailed v0.1 safety boundary for generic content/CPT eligibility, stale-write identity, destructive status/navigation rules, and verified Ability reuse |
| [`docs/PROJECT-WORKSPACE-ARCHITECTURE.md`](./docs/PROJECT-WORKSPACE-ARCHITECTURE.md) | Accepted post-v0.1 Workspace storage, isolation, versioning, admin UX, and lifecycle architecture |
| [Issue #1](https://github.com/ach1992/wp-native-builder-bridge/issues/1) | v0.1 program/outcome |
| [Issue #2](https://github.com/ach1992/wp-native-builder-bridge/issues/2) | Plugin/MCP foundation and access controls |
| [Issue #3](https://github.com/ach1992/wp-native-builder-bridge/issues/3) | Core WordPress/Gutenberg/media/navigation abilities and live implementation acceptance |
| [Issue #4](https://github.com/ach1992/wp-native-builder-bridge/issues/4) | Astra/Gravity Forms/Code Snippets/advanced admin abilities |
| [Issue #5](https://github.com/ach1992/wp-native-builder-bridge/issues/5) | Ability hardening, tests, and CI |
| [Issue #6](https://github.com/ach1992/wp-native-builder-bridge/issues/6) | Real ChatGPT MCP interoperability, docs, and v0.1 release |
| [Issue #8](https://github.com/ach1992/wp-native-builder-bridge/issues/8) | Post-v0.1 Persistent Workspace implementation and Bridge-local validation |
| [`wp-native-builder`](https://github.com/ach1992/wp-native-builder) | Companion ChatGPT Skill |

## Development path

```text
#2 foundation + permissions
  -> #3 ability reuse + core site-building abilities
  -> #4 optional integrations + advanced admin
  -> #5 contract/permission hardening + CI
  -> #6 real ChatGPT MCP test + v0.1 release

post-v0.1:
  -> #8 persistent Workspace storage + abilities + admin UX
```

This sequence is intentionally small. Implementation should add abstractions only when repeated code or a real interoperability requirement earns the complexity.

## Development

Implementation and review state is tracked in the linked GitHub Issues and pull requests rather than duplicated in this README.

The foundation has a dependency-free fast test runner:

```bash
php tests/run.php
```

A disposable WordPress 6.9+ environment with the official MCP Adapter can also run the integration smoke check through WP-CLI:

```bash
wp eval-file tests/integration/foundation-smoke.php --user=<administrator>
```

The production plugin does not require Node.js, Docker, Composer, or an external service at runtime.

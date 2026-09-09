# WP Native Builder Bridge

A free, self-hosted WordPress plugin that exposes typed, permission-checked WordPress abilities for AI-assisted site building.

The bridge is the companion runtime for [`wp-native-builder`](https://github.com/ach1992/wp-native-builder). It exists to reduce manual WordPress admin work while keeping the site owner in control of which access groups are available.

## Architecture

```text
AI / MCP client
  -> official WordPress MCP Adapter
  -> WordPress Abilities API
  -> WP Native Builder Bridge
  -> WordPress / Gutenberg / optional integrations
```

The project does not implement MCP from scratch and does not require WPVibe or another paid/SaaS WordPress bridge.

## Target capabilities

- site/environment inspection;
- posts, pages, supported custom post types, revisions, and statuses;
- structured Gutenberg block inspection and targeted edits;
- media, taxonomies, and navigation;
- Astra/Astra Pro integration where supported public interfaces exist;
- Gravity Forms through its supported public API;
- Code Snippets Pro where a stable supported integration API can be verified, including managed PHP/CSS/JavaScript/HTML snippet lifecycle where supported;
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
- official [`WordPress/mcp-adapter`](https://github.com/WordPress/mcp-adapter)
- current WordPress/PHP development baseline, with exact minimum PHP support finalized from tested compatibility
- no production runtime dependency on Node.js, Docker, or an external service

## Project map

| Source | Purpose |
|---|---|
| [`MASTER-SPEC.md`](./MASTER-SPEC.md) | Canonical architecture, ability surface, permissions, constraints, and completion criteria |
| [Issue #1](https://github.com/ach1992/wp-native-builder-bridge/issues/1) | v0.1 program/outcome |
| [Issue #2](https://github.com/ach1992/wp-native-builder-bridge/issues/2) | Plugin/MCP foundation and access controls |
| [Issue #3](https://github.com/ach1992/wp-native-builder-bridge/issues/3) | Core WordPress/Gutenberg/media/navigation abilities |
| [Issue #4](https://github.com/ach1992/wp-native-builder-bridge/issues/4) | Astra/Gravity Forms/Code Snippets/advanced admin abilities |
| [Issue #5](https://github.com/ach1992/wp-native-builder-bridge/issues/5) | Ability hardening, tests, and CI |
| [Issue #6](https://github.com/ach1992/wp-native-builder-bridge/issues/6) | Real ChatGPT MCP interoperability, docs, and v0.1 release |
| [`wp-native-builder`](https://github.com/ach1992/wp-native-builder) | Companion ChatGPT Skill |

## Development path

```text
#2 foundation + permissions
  -> #3 core site-building abilities
  -> #4 optional integrations + advanced admin
  -> #5 contract/permission hardening + CI
  -> #6 real ChatGPT MCP test + release
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

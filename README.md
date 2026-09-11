# WP Native Builder Bridge

A free, self-hosted WordPress plugin that exposes typed, permission-checked WordPress abilities for AI-assisted site building.

The bridge is the companion runtime for [`wp-native-builder`](https://github.com/ach1992/wp-native-builder). It exists to reduce manual WordPress admin work while keeping the site owner in control of which access groups are available.

## Repository ownership

This repository owns only the **WP Native Builder Bridge plugin/runtime**. A Master assigned to `ach1992/wp-native-builder-bridge` must not modify or administer the companion `ach1992/wp-native-builder` Skill repository unless the owner explicitly changes that assignment.

Cross-repository coordination is contract-based: when a Bridge decision changes what the Skill needs to know, persist the Bridge-side decision here and provide a concise handoff to the Skill Master. The Skill Master owns any Skill-side documentation, Issues, code, packaging, or release changes. Likewise, requirements arriving from the Skill are inputs to reconcile against this repository's current authoritative specification/issues; they do not authorize the Bridge Master to mutate the Skill repository or silently override Bridge-local contracts.

This ownership boundary applies to source files, documentation, Issues, branches, pull requests, releases, and project administration. It does not prevent read-only inspection of the companion project when a current interface/dependency must be verified.

## Architecture

The normal ChatGPT v0.1 path is a direct custom Workspace App over public HTTPS:

```text
ChatGPT Workspace App
  -> Bridge direct HTTPS MCP endpoint
  -> WordPress-backed OAuth 2.1 authorization
  -> official WordPress MCP Adapter HttpTransport
  -> WordPress Abilities API / registry
       -> compatible abilities already provided by WordPress/plugins
       -> WP Native Builder Bridge abilities for missing capabilities
  -> WordPress / Gutenberg / optional site integrations
```

The default WordPress install remains intentionally small: **official MCP Adapter + WP Native Builder Bridge**. The direct ChatGPT App path does not require Secure MCP Tunnel, a proxy daemon, a helper ability pack, WPVibe, a paid identity provider, or another MCP server.

When WordPress Core or an already-installed plugin exposes a suitable stable Ability, the Bridge prefers discovery/reuse over duplicating the same operation. If no suitable Ability exists, the Bridge fills that capability gap through supported WordPress/plugin APIs. Extra helper plugins are not installed merely to enlarge the AI tool catalogue.

The Bridge creates its ChatGPT-facing MCP route through the official Adapter's supported server/`HttpTransport` surface. The Adapter's default server remains available independently for other MCP clients.

## Direct ChatGPT App

After installation on an Internet-reachable WordPress site with valid HTTPS, **Settings -> WP Native Builder** displays the exact MCP URL to enter when creating the custom App:

```text
https://YOUR-SITE.example/wp-json/wp-native-builder/v1/mcp
```

The direct route is protected by a WordPress-native OAuth flow designed for current ChatGPT MCP authentication requirements: protected-resource metadata, authorization-server metadata, ChatGPT Client ID Metadata Document validation, Authorization Code + PKCE `S256`, exact resource binding, short-lived access tokens, rotating refresh tokens, revocation, and HTTP 401 discovery challenges.

The browser authorization step uses the normal WordPress account. OAuth never bypasses Bridge access groups or WordPress capabilities. Bearer/code/refresh secrets are opaque and are not persisted in plaintext.

See [`docs/INSTALLATION-AND-CONNECTION.md`](./docs/INSTALLATION-AND-CONNECTION.md) for the exact Workspace App setup.

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

Only Site Read is enabled by default. OAuth transport authorization identifies the WordPress user; it does not grant WordPress capabilities and it does not enable Bridge access groups. ChatGPT workspace/app permissions may add another independent confirmation boundary.

## Platform direction

- WordPress 6.9+ (Abilities API)
- official [`WordPress/mcp-adapter`](https://github.com/WordPress/mcp-adapter) as the initial/default MCP transport
- WP Native Builder Bridge as the project-specific capability and direct-ChatGPT authorization layer
- current WordPress/PHP development baseline, with exact minimum PHP support finalized from tested compatibility
- no required helper MCP/ability-pack plugins
- no production runtime dependency on Node.js, Docker, Composer, a tunnel process, or an external identity provider

A future compatible transport may replace MCP Adapter if evidence shows that is a better supported path, but the normal architecture should use one transport rather than stacking overlapping MCP server plugins.

## Localization

The plugin uses the WordPress text domain `wp-native-builder-bridge` and keeps user-facing strings translation-ready through WordPress gettext APIs. Bundled locale catalogs live under `languages/`; v0.1 ships a complete Persian (`fa_IR`) runtime catalog using WordPress's `.l10n.php` format. Additional locales can be added without changing the plugin's ability contracts or transport architecture.

Localization is validated both statically and in a real WordPress runtime. Future admin surfaces, including the post-v0.1 Workspace UI, must preserve translation readiness and RTL/LTR neutrality.

## Post-v0.1 Persistent Workspace

The accepted follow-on architecture adds a small WordPress-hosted Persistent Workspace so fresh companion-Skill chats can resume durable site-project context without receiving old chat history. This capability is **not implemented in v0.1 and does not block the v0.1 release path**.

Workspace uses private WordPress-native storage, dedicated typed abilities, explicit isolation from generic content/Gutenberg operations, and optimistic concurrency that does not depend on WordPress revision rows surviving. WordPress revisions may remain optional history; any required history that must survive ordinary revision pruning is owned by bounded Bridge-managed Workspace version/snapshot storage instead.

See [`docs/PROJECT-WORKSPACE-ARCHITECTURE.md`](./docs/PROJECT-WORKSPACE-ARCHITECTURE.md) and Issue #8 for the accepted Bridge-side contract and future implementation work.

## Project map

| Source | Purpose |
|---|---|
| [`MASTER-SPEC.md`](./MASTER-SPEC.md) | Canonical project-level architecture, ability surface, permissions, reuse policy, constraints, and completion criteria |
| [`docs/CORE-ABILITY-SAFETY-BOUNDARIES.md`](./docs/CORE-ABILITY-SAFETY-BOUNDARIES.md) | Canonical detailed v0.1 safety boundary for generic content/CPT eligibility, stale-write identity, destructive status/navigation rules, and verified Ability reuse |
| [`docs/OPTIONAL-INTEGRATIONS-AND-ADMIN.md`](./docs/OPTIONAL-INTEGRATIONS-AND-ADMIN.md) | v0.1 provider reuse/fallback rules and bounded site settings, extensions, snippets, forms, users, and destructive administration |
| [`docs/ABILITY-INVENTORY.md`](./docs/ABILITY-INVENTORY.md) | Complete v0.1 Bridge-owned Ability inventory, access-group/capability map, excluded generic surfaces, and optimistic-concurrency boundary |
| [`docs/INSTALLATION-AND-CONNECTION.md`](./docs/INSTALLATION-AND-CONNECTION.md) | v0.1 installation, direct ChatGPT Workspace App OAuth connection, update/rollback, and uninstall behavior |
| [`docs/TROUBLESHOOTING.md`](./docs/TROUBLESHOOTING.md) | Layered troubleshooting for direct MCP/OAuth, permissions, providers, stale writes, and local validation |
| [`docs/RELEASE-CHECKLIST.md`](./docs/RELEASE-CHECKLIST.md) | Automated, OAuth/security, real-ChatGPT, metadata, license, and publication gates for v0.1 |
| [`docs/PROJECT-WORKSPACE-ARCHITECTURE.md`](./docs/PROJECT-WORKSPACE-ARCHITECTURE.md) | Accepted post-v0.1 Workspace storage, isolation, versioning, admin UX, and lifecycle architecture |
| [Issue #1](https://github.com/ach1992/wp-native-builder-bridge/issues/1) | v0.1 program/outcome |
| [Issue #2](https://github.com/ach1992/wp-native-builder-bridge/issues/2) | Plugin/MCP foundation and access controls |
| [Issue #3](https://github.com/ach1992/wp-native-builder-bridge/issues/3) | Core WordPress/Gutenberg/media/navigation abilities and live implementation acceptance |
| [Issue #4](https://github.com/ach1992/wp-native-builder-bridge/issues/4) | Astra/Gravity Forms/Code Snippets/advanced admin abilities |
| [Issue #5](https://github.com/ach1992/wp-native-builder-bridge/issues/5) | Ability hardening, tests, and CI |
| [Issue #6](https://github.com/ach1992/wp-native-builder-bridge/issues/6) | Direct ChatGPT App interoperability, docs, and v0.1 release |
| [Issue #8](https://github.com/ach1992/wp-native-builder-bridge/issues/8) | Post-v0.1 Persistent Workspace implementation and Bridge-local validation |
| [`wp-native-builder`](https://github.com/ach1992/wp-native-builder) | Companion ChatGPT Skill |

## Development path

```text
#2 foundation + permissions
  -> #3 ability reuse + core site-building abilities
  -> #4 optional integrations + advanced admin
  -> #5 contract/permission hardening + CI
  -> #6 direct ChatGPT App OAuth + real interoperability + v0.1 release

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

For the complete local quality gate, install development-only tooling and run:

```bash
composer install
composer check
```

`composer check` runs strict Composer package validation, PHP syntax checks, the dependency-free test suite, `WordPress-Core` plus `PHPCompatibilityWP` checks for the current PHP 8.4+ development baseline, Persian source/runtime catalog validation, the static safety-surface audit, and the installable ZIP validator. The resulting local package is `build/wp-native-builder-bridge.zip`. Composer and these quality tools are development dependencies only; they are not shipped in or required by the production plugin.

The repository also contains a disposable Docker integration runner that builds the release ZIP, installs that ZIP into WordPress, installs the pinned official MCP Adapter `v0.6.1`, and then runs integration-only tests in isolated volumes:

```bash
bash bin/run-integration.sh 6.9-php8.4-apache
bash bin/run-integration.sh php8.4-apache
RUN_OPTIONAL_PROVIDERS=1 bash bin/run-integration.sh php8.4-apache
```

The Issue #6 integration lane exercises the Adapter's default HTTP session contract, the Bridge direct OAuth endpoint (PKCE, resource binding, Bearer validation, refresh rotation, revocation, direct MCP initialize), the bundled Persian runtime catalog, and the raw STDIO MCP discovery/execution workflow for local/non-ChatGPT clients.

The optional-provider lane installs and exercises the explicitly tested Code Snippets `3.9.6` and `3.10.2` provider generations plus Astra `4.13.11`. Gravity Forms is represented in automated transport tests by an explicitly test-only GFAPI contract fixture; this is not a claim that the commercial binary is present. GitHub Actions runs the same quality and integration gates on pull requests and `main`.

Disposable WordPress environments with the official MCP Adapter can also run individual integration checks through WP-CLI:

```bash
wp eval-file tests/integration/foundation-smoke.php --user=<administrator>
wp eval-file tests/integration/issue3-content-block-smoke.php --user=<administrator>
wp eval-file tests/integration/issue3-safety-regressions.php --user=<administrator>
wp eval-file tests/integration/issue3-provider-smoke.php --user=<administrator>
wp eval-file tests/integration/issue4-core-admin-smoke.php --user=<administrator>
wp eval-file tests/integration/issue5-hardening-smoke.php --user=<administrator>
wp eval-file tests/integration/issue6-http-transport-smoke.php --user=<administrator>
wp eval-file tests/integration/issue6-direct-oauth-smoke.php --user=<administrator>
wp eval-file tests/integration/issue6-i18n-smoke.php --user=<administrator>
```

Optional provider fixtures have focused checks in `tests/integration/issue4-code-snippets-smoke.php` and `tests/integration/issue4-astra-reuse-smoke.php`. They are designed for disposable environments and skip cleanly when the relevant provider is unavailable. Astra's native Ability test assumes Astra is active and its own Abilities toggle is enabled; the Bridge never enables that setting itself.

The production plugin does not require Node.js, Docker, Composer, a tunnel client, or an external identity-provider plugin at runtime.
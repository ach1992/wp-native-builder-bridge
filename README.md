# WP Native Builder Bridge

[![CI](https://github.com/ach1992/wp-native-builder-bridge/actions/workflows/ci.yml/badge.svg)](https://github.com/ach1992/wp-native-builder-bridge/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/ach1992/wp-native-builder-bridge)](https://github.com/ach1992/wp-native-builder-bridge/releases/latest)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](./LICENSE)

WP Native Builder Bridge connects a WordPress site to ChatGPT through a direct HTTPS MCP endpoint, WordPress-backed OAuth, the official WordPress MCP Adapter, and the WordPress Abilities API.

It exposes bounded, typed site-management abilities while keeping WordPress capabilities and explicit Bridge access groups in control.

## What it provides

- Direct ChatGPT Workspace App connection over HTTPS with OAuth 2.1 and PKCE.
- Read and update posts, pages, supported custom post types, revisions, and Gutenberg blocks.
- Admin-controlled generic post-metadata access for WordPress post objects the connected user may edit, including protected/private builder metadata without provider/post-type/meta-key allowlists.
- Media Library read, upload, update, and delete operations.
- Taxonomy and classic navigation management.
- Bounded WordPress site settings.
- WordPress.org plugin/theme lifecycle operations.
- User and role administration behind an explicit destructive-access group.
- Persistent Workspace documents and tasks for durable project context.
- Native Astra Ability reuse when Astra Abilities are enabled.
- Managed Code Snippets lifecycle support for compatible Code Snippets versions.
- Gravity Forms fallback through GFAPI when no native Gravity Forms Ability surface is active.
- Persian (`fa_IR`) localization and RTL-compatible admin screens.

## Requirements

- WordPress **6.9 or newer**.
- The official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter), currently validated with `0.6.1`.
- HTTPS and a publicly reachable WordPress REST API for direct ChatGPT Workspace App connections.
- A WordPress account with the capabilities required for the operations you enable.

## Install

1. Download `wp-native-builder-bridge.zip` from the [latest GitHub release](https://github.com/ach1992/wp-native-builder-bridge/releases/latest).
2. In WordPress open **Plugins → Add Plugin → Upload Plugin**.
3. Upload the ZIP, install it, and activate **WP Native Builder Bridge**.
4. Install and activate the official WordPress MCP Adapter if it is not already active.
5. Open **WP Native Builder → Settings**.

See [Installation and connection](./docs/INSTALLATION.md) for the complete setup.

## Connect ChatGPT

On an HTTPS WordPress site, **WP Native Builder → Settings** shows the exact MCP endpoint for the site:

```text
https://YOUR-SITE.example/wp-json/wp-native-builder/v1/mcp
```

In a ChatGPT workspace with Developer Mode enabled:

1. Open **Workspace settings → Apps**.
2. Create a custom App.
3. Enter the MCP endpoint shown by WordPress.
4. Choose OAuth authentication and run **Scan Tools**.
5. Sign in to WordPress when prompted.
6. Review the WordPress consent page and authorize ChatGPT.

No tunnel or separate proxy service is required for the direct HTTPS setup.

## WordPress admin

The plugin adds a top-level **WP Native Builder** menu:

- **Dashboard** — Workspace summary and connection status.
- **Documents** — durable project documents.
- **Tasks** — durable work items with independent progress, review, and delivery state.
- **Activity** — bounded mutation activity.
- **Settings** — ChatGPT connection details and Bridge access groups.

Workspace state is private to WordPress and is not exposed through ordinary post, Gutenberg, or generic metadata abilities.

## Access groups

Bridge permissions are additive to normal WordPress capabilities. Enabling a Bridge group never grants a WordPress capability the connected user does not already have. Advanced Metadata is a deliberate exception to WordPress's generic protected-unregistered-meta default denial: when an administrator enables the group, protected unregistered post metadata may be accessed through the exact target post's `edit_post` authority. Explicit registered/provider metadata authorization contracts remain authoritative.

| Group | Purpose |
| --- | --- |
| **Site Read** | Inspect site information, content, media, navigation, extensions, integrations, and Workspace state. |
| **Builder Write** | Create and update drafts, content, Gutenberg blocks, media, taxonomies, navigation, forms, and Workspace objects. |
| **Remote Media** | Import safe HTTP(S) media into the Media Library; Builder Write and native upload authority are also required. |
| **Live Content** | Permit publishing and other live-content status changes when WordPress also permits them. |
| **Site Configuration** | Permit bounded global WordPress/theme configuration changes. |
| **Advanced Metadata** | Permit generic read/update of protected/private post metadata for post objects the connected user may edit, including private/non-REST CPTs; credential-like keys, options, user meta, and Workspace internals remain excluded. |
| **Code & Extensions** | Permit supported managed-snippet and plugin/theme lifecycle operations. |
| **Users & Destructive** | Permit user/role administration and destructive operations when WordPress also permits them. Metadata deletion requires this group in addition to Advanced Metadata. |

Only **Site Read** is enabled by default.

## Uploads and extension installation

`wp-native-builder/media-upload` accepts file bytes plus a filename and hands them to WordPress Media Library handling. The maximum payload is the smaller of the WordPress upload limit and **20 MiB**. The caller cannot choose a server filesystem path.

`wp-native-builder/media-import-url` accepts a public HTTP(S) URL and a filename, streams it within the current WordPress upload limit, and creates a normal attachment. It requires explicit **Remote Media** plus **Builder Write** access; upgrades do not enable it automatically. See [URL media import](./docs/ABILITIES.md#url-media-import).

Plugin/theme installation is deliberately narrower: it accepts WordPress.org slugs through WordPress administration APIs. The Bridge does **not** expose arbitrary ZIP/PHP upload, arbitrary package URLs, shell commands, SQL, generic filesystem access, arbitrary WordPress options/user-meta administration, or credential retrieval.

## Optional integrations

Integration is discovery-first: provider-owned public WordPress Abilities are reused at runtime, so a compatible new plugin or theme should normally require no Bridge-specific code. Provider fallbacks are reserved for bounded gaps with documented public APIs. Generic post metadata is provider- and post-type-neutral and does not require a new Bridge adapter merely because a plugin/theme stores state in `post_meta`. See [Architecture](./docs/ARCHITECTURE.md#discovery-first-provider-architecture).

- **Astra / Astra Pro:** enable Astra's **Abilities** setting. A separate Astra MCP server is not required for this Bridge setup.
- **Code Snippets:** compatible provider APIs are used for managed snippet lifecycle; the Bridge does not directly evaluate submitted code.
- **Gravity Forms:** native provider Abilities are preferred; otherwise the bounded GFAPI fallback is used when available.
- **WooCommerce:** verified provider Abilities may be reused. Broad commerce/customer/order fallbacks are not exposed automatically.

See [Integrations](./docs/INTEGRATIONS.md) for details.

## Security model

The Bridge is intentionally not a general remote-administration shell. It combines:

- WordPress OAuth identity;
- WordPress capabilities and object-level checks;
- explicit Bridge access groups;
- closed input/output schemas;
- stale-write protection for overwrite-sensitive operations;
- bounded mutation logging;
- provider-native permission checks where integrations are used.

Read [Security](./docs/SECURITY.md) before enabling write, Advanced Metadata, or destructive access on an important site.

## Documentation

- [Installation and connection](./docs/INSTALLATION.md)
- [User guide](./docs/USER-GUIDE.md)
- [Ability reference](./docs/ABILITIES.md)
- [Integrations](./docs/INTEGRATIONS.md)
- [Security](./docs/SECURITY.md)
- [Architecture](./docs/ARCHITECTURE.md)
- [Troubleshooting](./docs/TROUBLESHOOTING.md)
- [Development and testing](./docs/DEVELOPMENT.md)
- [Changelog](./CHANGELOG.md)

## License

WP Native Builder Bridge is licensed under **GPL-2.0-or-later**. See [LICENSE](./LICENSE).

## Author

**ACh** — https://ach.li

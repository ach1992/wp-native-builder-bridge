# Changelog

All notable public changes are documented here.

## 0.1.2

- Fixed Gutenberg targeted mutations so canonical top-level block path `0` works and non-canonical leading-zero aliases such as `00` are rejected.
- Fixed the Gravity Forms GFAPI fallback read permission to use the provider-supported `gravityforms_edit_forms` capability while preserving native `gravityforms/*` precedence.
- Added regression coverage for authorized and denied Gravity Forms fallback reads, including raw MCP transport coverage without the invalid capability.
- Codified the discovery-first, provider-agnostic integration architecture: reuse native Abilities first, use bounded public-API fallbacks only for real gaps, and never treat capability discovery as privilege escalation.

## 0.1.1

- Polished public plugin metadata and documentation.
- Set plugin author to ACh and plugin homepage to the GitHub repository.
- Kept the WP Native Builder Bridge product name untranslated as a brand name.
- Improved Tasks filter spacing in the WordPress admin UI.
- Corrected Code Snippets 3.9.x integration-status detection while retaining 3.10.x compatibility.
- Retained the complete direct ChatGPT OAuth/MCP, Persistent Workspace, Persian localization, and security boundaries introduced in 0.1.0.

## 0.1.0

- Initial public release.
- Direct ChatGPT Workspace App connection over HTTPS with WordPress-backed OAuth.
- Typed WordPress Abilities for content, Gutenberg blocks, media, taxonomies, navigation, site settings, extensions, users, and optional providers.
- Persistent Workspace with Dashboard, Documents, Tasks, Activity, Settings, optimistic concurrency, export, and explicit clear lifecycle.
- Astra native Ability reuse, compatible Code Snippets fallback, and Gravity Forms GFAPI fallback.
- Bundled Persian (`fa_IR`) localization and RTL-compatible admin UI.
- GPL-2.0-or-later license.

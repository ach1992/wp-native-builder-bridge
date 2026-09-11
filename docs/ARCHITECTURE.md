# Architecture

## Runtime path

The supported direct ChatGPT path is:

```text
ChatGPT Workspace App
  -> public HTTPS MCP endpoint
  -> WordPress-backed OAuth 2.1
  -> official WordPress MCP Adapter HttpTransport
  -> WordPress Abilities registry
     -> stable provider/Core abilities when available
     -> Bridge abilities for supported gaps
  -> WordPress / Gutenberg / optional integrations
```

The normal WordPress installation therefore requires only the official MCP Adapter and WP Native Builder Bridge.

## Discovery-first provider architecture

WP Native Builder Bridge is intentionally **provider-agnostic by default**. Installing another plugin or theme must not automatically create a maintenance requirement in the Bridge.

The canonical resolution order is:

```text
Discover registered WordPress Abilities at runtime
  -> reuse a suitable Core/plugin/theme Ability through its public contract
  -> otherwise use a bounded Bridge surface backed by a supported public WordPress/provider API
  -> add provider-specific fallback code only for a real capability gap with a stable documented contract
  -> otherwise report the surface as unavailable
```

Provider-owned Abilities take precedence over Bridge fallbacks. A fallback must not be registered in parallel when the provider already exposes the relevant native Ability surface, including when that provider surface is intentionally hidden from MCP.

This architecture aims for **maximum practical capability coverage with minimum provider-specific code**. A future plugin or theme that registers compatible public WordPress Abilities should normally become usable through discovery without editing Bridge source. Provider-specific code is an exception and must justify its implementation, security, compatibility, and long-term maintenance cost.

The Bridge does not guess private APIs, storage layouts, capability names, or admin-screen behavior. If neither a suitable Ability nor a supported public API exists, explicit unavailability is safer than brittle introspection.

## Capability coverage is not privilege escalation

Broad discovery does not grant authority. Every operation remains subject to the authorization layers that own it:

- the authenticated WordPress user and their effective WordPress/provider capabilities;
- the provider Ability's own permission callback when a native Ability is reused;
- explicit Bridge access groups for Bridge-owned operations;
- object-level checks and any operation-specific safety requirements.

Enabling a Bridge access group never grants a WordPress or provider capability the connected user does not already have. Discovery determines **what supported operations exist**; authorization determines **which of those operations this user may execute**.

## Provider examples

- **Astra / Astra Pro:** when Astra registers public `astra/*` Abilities, the Bridge discovers and reuses them. It does not maintain duplicate Astra tools.
- **Gravity Forms:** native `gravityforms/*` Abilities take precedence. When no native Gravity Forms Ability surface is registered and documented `GFAPI` is available, the Bridge may expose a bounded fallback. That fallback must use Gravity Forms' documented authorization contract rather than invented capability names.
- **Future unknown provider:** if a plugin or theme registers compatible public Abilities, discovery should make those operations available without a Bridge source change.
- **Provider without a usable Ability or public API:** the capability remains unavailable rather than being implemented through private internals or guessed contracts.

The Gravity Forms fallback permission regression tracked in issue #27 is the concrete reason for the last rule: the provider has no `gravityforms_view_forms` capability, while form-definition reads use the documented `gravityforms_edit_forms` authorization contract. Provider-specific fallbacks therefore must follow the provider's supported API and permission model exactly.

## Ability layer

Bridge abilities use closed schemas and explicit permission callbacks. Major surfaces are separated by concern: content, blocks, media, taxonomy, navigation, site configuration, extensions, users, integrations, and Workspace.

Discovery and execution are separate concerns. `wp-native-builder/integration-status` reports supported optional-provider modes, while the WordPress/MCP Ability registry remains the authoritative runtime source for actual provider Abilities.

## Persistent Workspace

Workspace documents and tasks use private WordPress-native object storage and dedicated abilities. Internal Workspace object types are excluded from generic content/Gutenberg operations.

Current-state concurrency does not depend on WordPress revision retention. Document/task mutations use Bridge-owned version/hash identity and atomic metadata compare-and-swap semantics.

## Direct OAuth

The direct endpoint publishes protected-resource and authorization-server metadata, validates the ChatGPT OAuth client metadata contract, uses Authorization Code + PKCE S256, binds the MCP resource, issues short-lived access tokens, rotates refresh tokens, and supports revocation.

The OAuth identity resolves back to a WordPress user so WordPress capabilities remain authoritative.

## Deployment model

Production runtime has no dependency on Composer, Node.js, Docker, a tunnel process, or an external identity-provider plugin. Development tooling and Docker integration tests are repository-only dependencies.

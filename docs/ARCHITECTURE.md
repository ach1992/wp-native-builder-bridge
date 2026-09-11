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

## Reuse before fallback

The Bridge prefers an already-registered stable Ability when the provider owns the capability. A Bridge-specific fallback is added only where a bounded supported WordPress/provider API exists and duplicating a provider-native security decision is avoided.

## Ability layer

Bridge abilities use closed schemas and explicit permission callbacks. Major surfaces are separated by concern: content, blocks, media, taxonomy, navigation, site configuration, extensions, users, integrations, and Workspace.

## Persistent Workspace

Workspace documents and tasks use private WordPress-native object storage and dedicated abilities. Internal Workspace object types are excluded from generic content/Gutenberg operations.

Current-state concurrency does not depend on WordPress revision retention. Document/task mutations use Bridge-owned version/hash identity and atomic metadata compare-and-swap semantics.

## Direct OAuth

The direct endpoint publishes protected-resource and authorization-server metadata, validates the ChatGPT OAuth client metadata contract, uses Authorization Code + PKCE S256, binds the MCP resource, issues short-lived access tokens, rotates refresh tokens, and supports revocation.

The OAuth identity resolves back to a WordPress user so WordPress capabilities remain authoritative.

## Deployment model

Production runtime has no dependency on Composer, Node.js, Docker, a tunnel process, or an external identity-provider plugin. Development tooling and Docker integration tests are repository-only dependencies.

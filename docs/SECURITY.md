# Security model

WP Native Builder Bridge is designed as a bounded WordPress capability layer, not a general remote shell.

## Layered authorization

A successful operation must satisfy every applicable layer:

1. valid OAuth-authenticated WordPress identity for direct ChatGPT connections;
2. the relevant Bridge access group;
3. the required WordPress capability or object-level meta capability;
4. any provider-native permission check used by an integration;
5. operation-specific live-state, destructive, and stale-state rules.

OAuth never enables a Bridge access group and never grants a WordPress capability.

## Access groups

- **Site Read** — read-only inspection surfaces.
- **Builder Write** — bounded content/site-building mutations.
- **Live Content** — publishing and other live-state transitions.
- **Site Configuration** — bounded global configuration.
- **Code & Extensions** — managed snippets and extension lifecycle.
- **Users & Destructive** — user administration and destructive operations.

Only Site Read is enabled by default.

## Stale-write protection

Overwrite-sensitive content and Workspace operations return change identities. A later update must present the expected current identity. If the object changed after inspection, the Bridge rejects the write and requires the caller to refresh.

Workspace documents/tasks use monotonic `version` plus deterministic `state_hash` with an atomic compare-and-swap against the previous state payload.

## Content and Gutenberg isolation

Generic content/Gutenberg operations are limited to eligible editor-capable WordPress content types. Internal Workspace storage is explicitly excluded from those generic abilities and is reachable only through the dedicated Workspace contract.

## Upload boundary

Media upload accepts bytes and a filename, writes only to a WordPress-generated temporary path, and hands the result to WordPress media/sideload handling. The caller cannot specify a server filesystem path.

The payload cap is the smaller of the WordPress upload limit and 20 MiB.

## Extension boundary

Plugin/theme installation accepts WordPress.org slugs resolved through WordPress Core APIs. Arbitrary package URLs, uploaded plugin ZIPs, PHP files, and caller-selected server paths are not accepted.

Deletion requires destructive access in addition to the action-specific WordPress capability. Active extensions are protected where deletion would be unsafe.

## Managed code snippets

Code Snippets integration is an intentional bounded provider integration. Submitted code is passed through the installed Code Snippets lifecycle API and its capability checks. The Bridge does not evaluate the code directly or provide a generic PHP execution endpoint.

## Explicitly absent generic surfaces

The Bridge does not expose:

- arbitrary SQL;
- shell/process execution;
- WP-CLI execution;
- unrestricted filesystem access;
- arbitrary `wp_options` access;
- credential, session, OAuth-secret, or Application Password retrieval;
- arbitrary plugin ZIP/PHP upload;
- direct provider-table administration.

## OAuth storage

Authorization codes, access tokens, and refresh tokens are opaque. Secret-bearing values are not intentionally stored in plaintext. Refresh tokens rotate, revocation is supported, and the direct MCP resource is bound to the OAuth flow.

## Activity logging

The mutation log is bounded and metadata-oriented. It should not be treated as a content archive and must not be used to log credentials or full submitted payloads.

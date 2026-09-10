# Installation and direct ChatGPT App connection

Verified for the v0.1 release-candidate path on 2026-09-10.

## Supported baseline

The intentionally small baseline is:

- WordPress 6.9+;
- PHP 8.4 as the current development/test baseline;
- official `WordPress/mcp-adapter` (v0.6.1 is pinned in automated interoperability tests);
- WP Native Builder Bridge;
- a WordPress site whose REST API is reachable from the Internet over valid HTTPS;
- a ChatGPT workspace where Developer Mode and custom Apps are enabled.

Astra, Gravity Forms, Code Snippets, WooCommerce, ACF, page builders, SEO/cache plugins, and other extensions remain ordinary optional site-stack components. Do not install extra MCP servers or ability-pack plugins merely to enlarge the AI tool list.

The Bridge does not currently claim a lower PHP minimum than the tested development baseline. A lower minimum may be documented later only after an explicit compatibility matrix proves it.

## Connection model

The normal v0.1 ChatGPT path is direct remote MCP:

```text
ChatGPT Workspace App
  -> HTTPS /wp-json/wp-native-builder/v1/mcp
  -> Bridge OAuth 2.1 boundary
  -> authenticated WordPress user
  -> official MCP Adapter HttpTransport
  -> WordPress Abilities API
  -> Bridge / verified provider Abilities
```

No Secure MCP Tunnel, separate proxy daemon, custom API-key scheme, SaaS identity provider, or additional MCP server is required for this path.

The Bridge creates a dedicated MCP server through the official MCP Adapter instead of modifying the Adapter's default server. Other MCP clients can continue using the Adapter's normal interfaces independently.

## Install

### WordPress admin

1. Install and activate the official WordPress MCP Adapter using its supported release package.
2. Upload `wp-native-builder-bridge.zip` at **Plugins -> Add Plugin -> Upload Plugin**.
3. Activate **WP Native Builder Bridge**.
4. Open **Settings -> WP Native Builder**.
5. Confirm **MCP Adapter** is available and **Public HTTPS** reports ready.
6. Copy the **App MCP endpoint** shown on that page.
7. Review the Bridge access groups before enabling write-sensitive groups.

Only **Site Read** is enabled by default. `Builder Write`, `Live Content`, `Site Configuration`, `Code & Extensions`, and `Users & Destructive` are default-off.

### WP-CLI

From the WordPress installation directory:

```bash
wp plugin install https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip --activate
wp plugin install /path/to/wp-native-builder-bridge.zip --activate
wp mcp-adapter list
```

The Bridge release ZIP contains no development dependencies or test fixtures.

## Direct App URL

The URL entered in ChatGPT is:

```text
https://YOUR-WORDPRESS-SITE.example/wp-json/wp-native-builder/v1/mcp
```

Use the exact URL displayed by **Settings -> WP Native Builder**. Do not substitute the MCP Adapter default endpoint.

The direct endpoint is deliberately Bearer-only. WordPress cookie login, Basic authentication, and WordPress Application Passwords do not bypass its OAuth boundary.

## OAuth discovery exposed by the Bridge

ChatGPT discovers the WordPress-backed authorization server through:

```text
https://YOUR-WORDPRESS-SITE.example/.well-known/oauth-protected-resource
https://YOUR-WORDPRESS-SITE.example/.well-known/oauth-authorization-server
```

The Bridge advertises and enforces:

- Authorization Code flow;
- PKCE `S256`;
- OAuth resource binding to the exact Bridge MCP endpoint;
- ChatGPT Client ID Metadata Document support;
- the stable ChatGPT production client identifier `https://chatgpt.com/oauth/client.json`;
- ChatGPT `private_key_jwt` client authentication verified against the fixed `https://chatgpt.com/oauth/jwks.json` JWKS endpoint with `RS256`, bounded assertion lifetime, audience binding, and one-time `jti`;
- the stable ChatGPT redirect URI used with authorization-response issuer identification;
- short-lived Bearer access tokens;
- least-privilege scope handling: an omitted `scope` defaults to `mcp:use` only;
- rotating refresh tokens only when `offline_access` is explicitly granted;
- token revocation;
- HTTP `401` plus `WWW-Authenticate` protected-resource discovery for missing or invalid credentials.

Access/code/refresh secrets are opaque high-entropy values. The Bridge persists only a keyed secret hash plus bounded claims in WordPress transients; the bearer secret itself is not stored in plaintext. Tokens are bound to the current plugin-installation identity, WordPress user, ChatGPT client, resource URL, scope, and expiry.

## Add the App in ChatGPT

In the ChatGPT workspace where Developer Mode is enabled:

1. Open the workspace **Apps** settings and create a custom App.
2. Enter a clear App name, for example `WP Native Builder`.
3. Enter the exact **App MCP endpoint** copied from WordPress.
4. Select **OAuth** as the authentication method when prompted.
5. Start **Scan Tools** / connection validation.
6. ChatGPT should open the WordPress authorization flow in the browser.
7. Sign in to the WordPress account whose capabilities should apply to this connection.
8. Review the WordPress consent page and choose **Authorize ChatGPT**.
9. Return to ChatGPT and scan/refresh the tools if the UI does not do so automatically.

Do not paste a WordPress password, Application Password, OAuth access token, refresh token, client secret, or signing key into the ChatGPT App form. The Bridge validates ChatGPT's signed `private_key_jwt` automatically from the fixed ChatGPT JWKS endpoint; there is no key or client-secret field for the site owner to configure. The browser OAuth flow is responsible for token issuance.

## What ChatGPT should discover

The direct MCP server intentionally exposes only the three MCP Adapter meta-tools:

- `mcp-adapter-discover-abilities`;
- `mcp-adapter-get-ability-info`;
- `mcp-adapter-execute-ability`.

A good first connected sequence is:

1. call `mcp-adapter-discover-abilities`;
2. inspect `wp-native-builder/bridge-info` with `mcp-adapter-get-ability-info`;
3. execute `wp-native-builder/bridge-info`;
4. inspect the site context and provider status;
5. perform a draft-only operation before enabling any live/destructive access group.

Do not invent an Ability name that discovery did not return.

## Authorization boundaries

The connection has independent layers:

1. ChatGPT must hold a valid Bridge OAuth access token for the exact MCP resource.
2. The token maps to the WordPress user who approved the connection.
3. Each Bridge Ability still checks its Bridge access group.
4. Each Ability still checks the relevant WordPress capability for that user.
5. ChatGPT workspace/app policy may independently require confirmation for writes or important actions.

OAuth authorization never promotes a WordPress user's role and never enables a disabled Bridge access group.

Use a dedicated WordPress account with the narrowest practical role/capabilities when the App does not need full administrator access.

## Public HTTPS requirements

ChatGPT must be able to reach the MCP endpoint and OAuth discovery endpoints from the Internet. Verify all of the following before creating the App:

- WordPress `home` / `siteurl` resolve to the public HTTPS origin expected by users;
- the certificate is publicly trusted and valid for the hostname;
- `/wp-json/` is reachable normally;
- the Bridge MCP URL is not intercepted by a maintenance page, CDN login, WAF challenge, or HTTP Basic Auth;
- `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server` reach WordPress;
- WordPress can make outbound HTTPS requests to `https://chatgpt.com/oauth/client.json` and `https://chatgpt.com/oauth/jwks.json` so the fixed ChatGPT client metadata and signed-client keys can be verified.

If a reverse proxy terminates TLS, WordPress must still generate `https://` URLs. Fix the standard WordPress/proxy HTTPS detection rather than hard-coding a different endpoint in the Bridge.

## Optional-provider behavior

- **Astra:** Astra 4.13.x has its own opt-in native `astra/*` Ability surface. When the site owner enables Astra Abilities, the Bridge reuses those registered Abilities rather than duplicating them.
- **Code Snippets:** when its supported programmatic lifecycle is present, the Bridge exposes managed snippet operations through that provider API and current provider capability. It never directly evaluates supplied code.
- **Gravity Forms:** if a native `gravityforms/*` Ability surface is registered, it wins. Otherwise a bounded `GFAPI` form-management fallback may register. Automated v0.1 transport tests use a test-only GFAPI contract fixture because the commercial Gravity Forms binary is not bundled in CI; this is not a claim of binary-level Gravity Forms validation.
- **WooCommerce:** only verified provider Abilities appropriate to ordinary builder work are surfaced for reuse; order/customer/payment-sensitive operations are not silently promoted into the generic builder surface.

## Default MCP Adapter endpoint

The official Adapter also exposes its own default HTTP endpoint:

```text
/wp-json/mcp/mcp-adapter-default-server
```

That endpoint remains untouched for other clients and retains the Adapter's own authentication behavior. It is **not** the endpoint to paste into the ChatGPT Workspace App for the direct Bridge OAuth flow.

## Update

Before an update:

1. take a normal site/database backup appropriate to the environment;
2. record the currently installed Bridge version and current access-group settings;
3. install the new ZIP through normal WordPress plugin update mechanisms;
4. re-run capability discovery; provider upgrades can legitimately change the live provider Ability surface;
5. verify a read-only call and a draft-only write before using live/destructive operations.

The Bridge does not own provider data. Updating it must not rewrite content merely because the plugin was updated.

## Deactivate and uninstall

Deactivation stops Bridge registration but does not delete site content, media, terms, users, provider objects, or provider data.

The v0.1 uninstall handler removes the Bridge-owned per-site settings/mutation metadata and OAuth installation identity, clears the cached ChatGPT client metadata/JWKS, removes short-lived client-assertion replay claims, and clears their cleanup events. Removing the OAuth installation identity also invalidates every previously issued Bridge OAuth artifact.

It does not delete WordPress/provider content. The post-v0.1 Persistent Workspace has a separate retention contract: future Workspace data must not be silently added to this uninstall deletion path.

Rollback is therefore normally: deactivate the current Bridge, reinstall the previously validated Bridge ZIP, activate it, verify access groups, and re-scan the custom App.

## Official references

- WordPress MCP Adapter: `https://github.com/WordPress/mcp-adapter`
- OpenAI MCP/Plugin authentication: `https://developers.openai.com/apps-sdk/build/auth`
- ChatGPT Developer Mode / MCP Apps: `https://help.openai.com/en/articles/12584461`
- ChatGPT Client ID Metadata Document: `https://chatgpt.com/oauth/client.json`

# Troubleshooting

Work from the lowest layer upward. Do not enable broader access groups or WordPress roles merely to make an error disappear.

## 1. Plugin or Adapter is missing

```bash
wp plugin list --fields=name,status,version
wp mcp-adapter list
```

Expected baseline: the official MCP Adapter and WP Native Builder Bridge are active, and the Adapter lists its default server with three tools.

If `wp mcp-adapter` does not exist, verify the official Adapter was installed and activated from a supported release. MCP Adapter v0.6.1 is the pinned v0.1 test baseline.

## 2. ChatGPT cannot create or scan the App

Open **Settings -> WP Native Builder** in WordPress and verify:

- MCP Adapter is available;
- Public HTTPS reports ready;
- the displayed **App MCP endpoint** is the URL entered in ChatGPT;
- the URL uses `https://`, not an internal hostname or localhost;
- the site is reachable from the public Internet without VPN, HTTP Basic Auth, maintenance interstitial, or WAF/browser challenge.

The ChatGPT App URL must be:

```text
https://YOUR-SITE.example/wp-json/wp-native-builder/v1/mcp
```

Do not use `/wp-json/mcp/mcp-adapter-default-server` for the Bridge direct OAuth App.

## 3. OAuth does not start after Scan Tools

An unauthenticated request to the direct MCP endpoint must fail with HTTP 401 and advertise protected-resource metadata through `WWW-Authenticate`.

Verify these public URLs in a browser or HTTP client:

```text
https://YOUR-SITE.example/.well-known/oauth-protected-resource
https://YOUR-SITE.example/.well-known/oauth-authorization-server
```

Both must return JSON from WordPress rather than a theme page, CDN error, login wall, redirect loop, or 404.

The protected-resource document must name the exact direct MCP endpoint as its `resource`. The authorization-server document must advertise Authorization Code, PKCE `S256`, refresh tokens, and Client ID Metadata Document support.

## 4. OAuth opens but WordPress login/consent fails

The Bridge uses the normal WordPress user session for the browser authorization step. Sign in to the WordPress account whose capabilities should govern ChatGPT.

Do not enter the WordPress password or an Application Password into the ChatGPT App settings. ChatGPT should receive tokens only through the browser OAuth flow.

If the consent page reports that ChatGPT client metadata could not be verified, confirm WordPress can make outbound HTTPS requests to:

```text
https://chatgpt.com/oauth/client.json
```

The Bridge intentionally validates the fixed current ChatGPT Client ID Metadata Document rather than accepting arbitrary client metadata URLs.

## 5. OAuth returns `invalid_target` / `invalid_grant`

The direct flow is fail-closed:

- `resource` must exactly match the displayed Bridge MCP endpoint;
- the authorization code is one-time and expires quickly;
- PKCE uses `S256`;
- the authorization code is bound to the ChatGPT client, redirect URI, resource, WordPress user, and scope;
- refresh tokens rotate and an old refresh token cannot be replayed;
- revoked/expired/unknown access tokens cannot authenticate MCP requests.

Do not weaken these checks to work around stale ChatGPT App configuration. Delete/recreate or reconnect the App with the exact current endpoint instead.

Changing the site's canonical hostname or scheme changes the OAuth resource identity. Reconnect the App after a deliberate site-origin change.

## 6. Tool scan shows only three top-level tools

That is expected. The MCP Adapter uses a layered architecture: the three top-level tools dynamically discover and execute WordPress Abilities.

Call `mcp-adapter-discover-abilities`; do not expect every Bridge/provider Ability to become a separate top-level MCP tool.

Expected top-level tools:

- `mcp-adapter-discover-abilities`;
- `mcp-adapter-get-ability-info`;
- `mcp-adapter-execute-ability`.

## 7. An Ability is missing

Check live discovery before changing code:

- Bridge-owned fallback may be conditional on a provider API being installed;
- a provider-native Ability can intentionally suppress a Bridge fallback;
- a provider can register a native Ability but choose not to expose it publicly to MCP;
- Astra's native Ability surface is opt-in in current Astra versions.

For Astra, verify the site owner intentionally enabled Astra Abilities. The Bridge does not toggle that provider setting on the owner's behalf.

For Gravity Forms, a registered native `gravityforms/*` surface suppresses the Bridge GFAPI fallback, including when the native surface is not MCP-public. This prevents the Bridge from bypassing a provider visibility choice.

## 8. `Permission denied`

There are independent authorization layers:

1. valid Bridge OAuth authorization for the exact MCP resource;
2. the WordPress user represented by that OAuth connection;
3. WordPress user capability;
4. Bridge access group;
5. object/provider-specific permission and live/destructive boundary;
6. ChatGPT workspace/app permission and confirmation policy.

Inspect **Settings -> WP Native Builder** and the connected WordPress user's actual role/capabilities. Do not promote to Administrator unless the task really needs administrator authority.

Typical examples:

- draft/content mutation: **Builder Write** + WordPress edit/create capability;
- publish/live update: **Builder Write + Live Content** + publish/edit capability;
- global settings: **Site Configuration** + `manage_options`;
- plugin/theme lifecycle or managed snippets: **Code & Extensions** + provider/Core capability;
- deletes/user administration: **Users & Destructive** + the applicable WordPress capability.

## 9. Stale write conflict

A full content update or revision restore uses `expected_modified_gmt` plus `expected_state_hash`. A targeted Gutenberg mutation uses the narrower current content hash it owns.

On a conflict:

1. re-read the current object;
2. compare the newer state with the intended change;
3. recompute the requested change against that current state;
4. retry with the newly observed identity.

Do not reuse the old hash or disable the conflict check.

## 10. MCP endpoint or well-known URLs return a normal page or 404

Verify the site's standard WordPress REST/rewrite configuration. The direct App depends on normal public WordPress routing for:

```text
/wp-json/wp-native-builder/v1/mcp
/.well-known/oauth-protected-resource
/.well-known/oauth-authorization-server
```

If `/wp-json/` itself is broken, fix normal WordPress REST routing first. Do not add a custom proxy just for the Bridge.

For Apache, confirm the normal WordPress rewrite rules are active. For Nginx or another front end, confirm unmatched WordPress routes reach `index.php` according to the site's standard configuration.

## 11. MCP session errors

The Bridge direct endpoint uses the official MCP Adapter HTTP transport, so its session behavior follows the pinned Adapter baseline.

For MCP Adapter v0.6.1 as verified by this project:

- missing `Mcp-Session-Id` after initialization: HTTP 400, MCP error `-32600`;
- unknown/terminated `Mcp-Session-Id`: HTTP 404, MCP error `-32005`;
- GET: HTTP 405;
- DELETE: terminates the session.

A newly initialized session ID must be used for subsequent session-bound requests. Reconnect rather than reusing an expired session ID.

## 12. Reverse proxy reports HTTP instead of HTTPS

The WordPress settings page derives the App resource from WordPress REST URL generation. If a TLS-terminating proxy is configured incorrectly, WordPress may generate an `http://` resource even though visitors browse over HTTPS.

Fix the standard WordPress/reverse-proxy HTTPS detection and canonical `home`/`siteurl` configuration. Do not hard-code a different OAuth resource URI, because authorization codes and tokens are deliberately bound to that exact resource.

## 13. Extension install/update fails

The Bridge uses WordPress Core administration APIs. Its install surface accepts a WordPress.org slug; it does not accept an arbitrary remote package URL or caller-selected server path.

If WordPress reports that filesystem access/credentials are required, configure WordPress filesystem access out-of-band and retry. The Bridge does not collect FTP/SSH credentials.

## 14. Code Snippets operation fails

The Bridge asks current Code Snippets for its effective capability instead of hardcoding removed legacy capability names. The provider's supported scope list is authoritative, locked snippets are rejected, and permanent deletion requires the snippet to be trashed first plus **Users & Destructive**.

The Bridge never evaluates the snippet directly; execution/activation remains provider-owned.

## 15. Gravity Forms behavior differs from CI

The automated transport-level v0.1 test uses a test-only GFAPI contract fixture because a commercial Gravity Forms binary is not included in CI. Treat a real provider-version difference as a provider-integration compatibility issue and validate against that installed version's current public `GFAPI`/Ability contract. Do not couple the Bridge to Gravity Forms private tables.

## 16. Run the project verification locally

Quality gate:

```bash
composer install
composer check
```

Disposable integration lanes:

```bash
bash bin/run-integration.sh 6.9-php8.4-apache
bash bin/run-integration.sh php8.4-apache
RUN_OPTIONAL_PROVIDERS=1 bash bin/run-integration.sh php8.4-apache
```

These tests create disposable WordPress/MariaDB volumes. The runner installs the Bridge from the generated release ZIP before copying integration-only tests beside it. Direct OAuth regressions cover PKCE, code replay, resource binding, non-plaintext token storage, Bearer authentication, MCP initialize, refresh rotation, revocation, and uninstall invalidation.

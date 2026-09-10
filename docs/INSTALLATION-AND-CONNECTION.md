# Installation and ChatGPT connection

Verified for the v0.1 release candidate on 2026-09-10.

## Supported baseline

The intentionally small baseline is:

- current WordPress 6.9+;
- PHP 8.4 as the current development/test baseline;
- official `WordPress/mcp-adapter` (v0.6.1 is pinned in automated interoperability tests);
- WP Native Builder Bridge.

Astra, Gravity Forms, Code Snippets, WooCommerce, ACF, builders, SEO/cache plugins, and other extensions remain ordinary optional site-stack components. Do not install extra MCP servers or ability-pack plugins just to enlarge the AI tool list.

The Bridge does not currently claim a lower PHP minimum than the tested development baseline. A lower minimum may be documented later only after an explicit compatibility matrix proves it.

## Install

### WordPress admin

1. Install and activate the official WordPress MCP Adapter using its supported release package.
2. Upload `wp-native-builder-bridge.zip` at **Plugins -> Add Plugin -> Upload Plugin**.
3. Activate **WP Native Builder Bridge**.
4. Open **Settings -> WP Native Builder Bridge** and review access groups before enabling write-sensitive groups.

Only **Site Read** is enabled by default. `Builder Write`, `Live Content`, `Site Configuration`, `Code & Extensions`, and `Users & Destructive` are default-off.

### WP-CLI

From the WordPress installation directory:

```bash
wp plugin install https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip --activate
wp plugin install /path/to/wp-native-builder-bridge.zip --activate
wp mcp-adapter list
```

The default MCP Adapter server should report three layered tools. The Bridge abilities are discovered dynamically through that server rather than becoming dozens of top-level MCP tools.

## Recommended ChatGPT architecture: Secure MCP Tunnel + STDIO

For a private, local, or on-premises WordPress site, prefer OpenAI Secure MCP Tunnel instead of exposing the WordPress MCP endpoint to the public internet.

The trust path is:

```text
ChatGPT
  -> OpenAI-hosted Secure MCP Tunnel endpoint
  -> tunnel-client (outbound HTTPS only)
  -> local STDIO command
  -> wp mcp-adapter serve
  -> WordPress Abilities API
  -> WP Native Builder Bridge / verified provider Abilities
```

The WordPress host does not need a new inbound firewall rule for this path.

### 1. Prepare a dedicated WordPress user

Use a dedicated WordPress user whose role/capabilities match the work ChatGPT should be allowed to perform. Do not use a broader administrator account merely to avoid configuring permissions.

Bridge access groups and WordPress capabilities are independent layers: both must allow an operation. ChatGPT app/action confirmation is a third independent layer and may still ask for confirmation or block an especially risky action.

Find the numeric WordPress user ID without exposing credentials:

```bash
wp user list --fields=ID,user_login,roles
```

### 2. Create or select an OpenAI tunnel

Use Platform tunnel settings:

`https://platform.openai.com/settings/organization/tunnels`

Associate the tunnel with the ChatGPT workspace that will use it. The runtime principal needs **Tunnels Read + Use**. Creating/editing tunnels requires **Tunnels Read + Manage**.

Create a separate runtime API key at:

`https://platform.openai.com/settings/organization/api-keys`

Do not use an admin API key for the long-running runtime. Never commit, paste into repository files, or include the runtime key in support logs or chat transcripts.

### 3. Install and verify tunnel-client

Use the supported download exposed by Platform tunnel settings or the current official OpenAI `tunnel-client` release. The v0.1 Bridge validation reviewed `tunnel-client` v0.0.14; operational runbooks should still check the current supported release before installation.

Verify the binary:

```bash
tunnel-client --version
tunnel-client help quickstart
```

### 4. Create a local STDIO profile

Export the runtime key only in the runtime environment and replace the example tunnel ID, WordPress path, and user ID:

```bash
export CONTROL_PLANE_API_KEY='<runtime-key>'
export CONTROL_PLANE_TUNNEL_ID='tunnel_0123456789abcdef0123456789abcdef'

wp_path='/absolute/path/to/wordpress'
wp_user_id='123'

tunnel-client init \
  --sample sample_mcp_stdio_local \
  --profile wp-native-builder \
  --tunnel-id "$CONTROL_PLANE_TUNNEL_ID" \
  --mcp-command "wp --path=$wp_path mcp-adapter serve --server=mcp-adapter-default-server --user=$wp_user_id"

tunnel-client doctor --profile wp-native-builder --explain
tunnel-client run --profile wp-native-builder
```

Keep `tunnel-client run` healthy while ChatGPT discovers or calls tools. The health/admin UI is loopback-only by default; do not expose it remotely unless an operator network explicitly requires that access.

### 5. Connect in ChatGPT

Current full MCP support, including write/modify actions, is available in beta on ChatGPT Business and Enterprise/Edu on the web. Developer-mode policy is controlled by the ChatGPT workspace separately from Platform tunnel permissions.

In ChatGPT web:

1. Enable Developer Mode according to the workspace policy.
2. Go to the workspace/app settings and create a developer-mode app.
3. Choose **Tunnel** under **Connection**.
4. Select the associated tunnel, or enter its valid `tunnel_id` when the UI offers that path.
5. Scan/refresh tools while `tunnel-client run --profile wp-native-builder` is healthy.
6. Verify the app sees the three MCP Adapter tools, then use `mcp-adapter-discover-abilities` to inspect the live WordPress capability surface.

For write operations, ChatGPT may independently ask for confirmation based on app permissions and action risk. Do not weaken Bridge or WordPress authorization to suppress those confirmations.

## What ChatGPT should discover first

The default Adapter surface should expose exactly:

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

## Optional-provider behavior

- **Astra:** Astra 4.13.x has its own opt-in native `astra/*` Ability surface. When the site owner enables Astra Abilities, the Bridge reuses those registered Abilities rather than duplicating them.
- **Code Snippets:** when its supported programmatic lifecycle is present, the Bridge exposes managed snippet operations through that provider API and current provider capability. It never directly evaluates supplied code.
- **Gravity Forms:** if a native `gravityforms/*` Ability surface is registered, it wins. Otherwise a bounded `GFAPI` form-management fallback may register. The automated v0.1 transport test uses a test-only GFAPI contract fixture because the commercial Gravity Forms binary is not bundled in CI; this is not a claim of binary-level Gravity Forms validation.
- **WooCommerce:** only verified provider Abilities appropriate to ordinary builder work are surfaced for reuse; order/customer/payment-sensitive operations are not silently promoted into the generic builder surface.

## Direct HTTP alternative

The MCP Adapter also exposes a Streamable HTTP route:

```text
/wp-json/mcp/mcp-adapter-default-server
```

The Adapter documentation demonstrates WordPress Application Password authentication for remote HTTP clients. For ChatGPT private/local deployments, Secure MCP Tunnel + STDIO is preferred because it avoids adding a public WordPress MCP ingress path.

The v0.1 integration suite verifies the Adapter's live HTTP transport/session behavior through WordPress REST dispatch:

- unauthenticated REST access is rejected with HTTP 401 before MCP execution;
- successful `initialize` creates a session and negotiates MCP protocol `2025-11-25`;
- subsequent session-bound POST requests work;
- a missing session header returns HTTP 400 / MCP `-32600`;
- an invalid or terminated session in MCP Adapter v0.6.1 returns HTTP 404 / MCP `-32005`;
- GET returns HTTP 405 because SSE-over-GET is not supported by this default server;
- DELETE terminates the session.

If using the pretty `/wp-json/...` path through a web server, ensure normal WordPress REST rewrites/permalinks are functioning. Do not add a custom auth proxy unless the deployment genuinely requires one and its security model is separately reviewed.

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

The v0.1 uninstall handler removes only the two Bridge-owned per-site options:

- `wp_native_builder_bridge_settings`;
- `wp_native_builder_bridge_recent_actions`.

It does not delete WordPress/provider content. The post-v0.1 Persistent Workspace has a separate retention contract: future Workspace data must not be silently added to this uninstall deletion path.

Rollback is therefore normally: deactivate the current Bridge, reinstall the previously validated Bridge ZIP, activate it, verify access groups, and re-scan MCP abilities.

## Official references

- WordPress MCP Adapter: `https://github.com/WordPress/mcp-adapter`
- OpenAI Secure MCP Tunnel: `https://developers.openai.com/api/docs/guides/secure-mcp-tunnels`
- OpenAI tunnel client: `https://github.com/openai/tunnel-client`
- ChatGPT Developer Mode / full MCP: `https://help.openai.com/en/articles/12584461`

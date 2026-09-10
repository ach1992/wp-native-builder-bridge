# Troubleshooting

Work from the lowest layer upward. Do not enable broader access groups or WordPress roles merely to make an error disappear.

## 1. Plugin or Adapter is missing

```bash
wp plugin list --fields=name,status,version
wp mcp-adapter list
```

Expected baseline: the official MCP Adapter and WP Native Builder Bridge are active, and the Adapter lists `mcp-adapter-default-server` with three tools.

If `wp mcp-adapter` does not exist, verify the official Adapter was installed and activated from a supported release. MCP Adapter v0.6.1 is the pinned v0.1 test baseline.

## 2. ChatGPT cannot see the tunnel

Check these separately:

- the tunnel is associated with the intended ChatGPT workspace;
- the app creator/runtime principal has Tunnels **Read + Use**;
- Developer Mode is available/enabled under the workspace's ChatGPT policy;
- `tunnel-client run --profile wp-native-builder` is still running and healthy.

Then run:

```bash
tunnel-client doctor --profile wp-native-builder --explain
```

If the tunnel is healthy but absent from ChatGPT, re-check workspace association rather than opening an inbound firewall port.

## 3. Tool scan shows only three tools

That is expected. The default MCP Adapter uses a layered architecture: the three top-level tools dynamically discover and execute WordPress Abilities.

Call `mcp-adapter-discover-abilities`; do not expect every Bridge/provider Ability to become a separate top-level MCP tool.

## 4. An Ability is missing

Check live discovery before changing code:

- Bridge-owned fallback may be conditional on a provider API being installed;
- a provider-native Ability can intentionally suppress a Bridge fallback;
- a provider can register a native Ability but choose not to expose it publicly to MCP;
- Astra's native Ability surface is opt-in in current Astra versions.

For Astra, verify the site owner intentionally enabled Astra Abilities. The Bridge does not toggle that provider setting on the owner's behalf.

For Gravity Forms, a registered native `gravityforms/*` surface suppresses the Bridge GFAPI fallback, including when the native surface is not MCP-public. This prevents the Bridge from bypassing a provider visibility choice.

## 5. `Permission denied`

There are multiple independent authorization layers:

1. ChatGPT app/action permission and confirmation;
2. OpenAI tunnel access, if a tunnel is used;
3. WordPress user capability;
4. Bridge access group;
5. object/provider-specific permission and live/destructive boundary.

Inspect **Settings -> WP Native Builder Bridge** and the dedicated WordPress user's actual role/capabilities. Do not promote to Administrator unless the task really needs administrator authority.

Typical examples:

- draft/content mutation: **Builder Write** + WordPress edit/create capability;
- publish/live update: **Builder Write + Live Content** + publish/edit capability;
- global settings: **Site Configuration** + `manage_options`;
- plugin/theme lifecycle or managed snippets: **Code & Extensions** + provider/Core capability;
- deletes/user administration: **Users & Destructive** + the applicable WordPress capability.

## 6. Stale write conflict

A full content update or revision restore uses `expected_modified_gmt` plus `expected_state_hash`. A targeted Gutenberg mutation uses the narrower current content hash it owns.

On a conflict:

1. re-read the current object;
2. compare the newer state with the intended change;
3. recompute the requested change against that current state;
4. retry with the newly observed identity.

Do not reuse the old hash or disable the conflict check.

## 7. HTTP endpoint returns a normal page or 404

The pretty endpoint is:

```text
/wp-json/mcp/mcp-adapter-default-server
```

Verify the site's WordPress REST rewrites/permalinks first. In minimal Apache/Docker fixtures, pretty REST URLs will not behave correctly until rewrite/permalink configuration is active.

For private ChatGPT deployments, prefer Secure MCP Tunnel + STDIO and avoid depending on public WordPress REST ingress.

## 8. HTTP session errors

For the pinned MCP Adapter v0.6.1 behavior verified by this project:

- missing `Mcp-Session-Id`: HTTP 400, MCP error `-32600`;
- unknown/terminated `Mcp-Session-Id`: HTTP 404, MCP error `-32005`;
- GET: HTTP 405;
- DELETE: terminates the session.

A newly initialized session ID must be used for subsequent session-bound requests. Do not cache/reuse an expired session ID across a reconnect.

## 9. Secure MCP Tunnel is unhealthy

Keep the MCP server private. Verify outbound connectivity from the tunnel host to OpenAI over HTTPS and local reachability to the STDIO command.

```bash
tunnel-client doctor --profile wp-native-builder --explain
```

The tunnel client exposes loopback health/admin surfaces such as `/healthz`, `/readyz`, `/metrics`, and `/ui`. Keep them loopback-only unless deliberate operator access is required.

Never print the runtime API key while troubleshooting.

## 10. Extension install/update fails

The Bridge uses WordPress Core administration APIs. Its install surface accepts a WordPress.org slug; it does not accept an arbitrary remote package URL or caller-selected server path.

If WordPress reports that filesystem access/credentials are required, configure WordPress filesystem access out-of-band and retry. The Bridge does not collect FTP/SSH credentials.

## 11. Code Snippets operation fails

The Bridge asks current Code Snippets for its effective capability instead of hardcoding removed legacy capability names. The provider's supported scope list is authoritative, locked snippets are rejected, and permanent deletion requires the snippet to be trashed first plus **Users & Destructive**.

The Bridge never evaluates the snippet directly; execution/activation remains provider-owned.

## 12. Gravity Forms behavior differs from CI

The automated transport-level v0.1 test uses a test-only GFAPI contract fixture because a commercial Gravity Forms binary is not included in CI. Treat a real provider-version difference as a provider-integration compatibility issue and validate against that installed version's current public `GFAPI`/Ability contract. Do not couple the Bridge to Gravity Forms private tables.

## 13. Run the project verification locally

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

These tests create disposable WordPress/MariaDB volumes. The runner installs the Bridge from the generated release ZIP before copying integration-only tests beside it.

# Troubleshooting

## The custom App cannot scan tools

Check **WP Native Builder → Settings** first.

Confirm:

- WordPress 6.9+ is running;
- MCP Adapter is active;
- the reported MCP URL uses HTTPS;
- the WordPress REST API is reachable from the Internet;
- a security/CDN plugin is not blocking `/.well-known/` or the Bridge REST route.

Opening the MCP endpoint without authentication should return an authentication challenge, not a normal HTML page.

## OAuth opens WordPress but authorization does not finish

Start a fresh connection attempt rather than reusing an old consent page. OAuth consent requests are intentionally short-lived and one-time.

Also check browser/network security layers for blocked redirects to ChatGPT and ensure the site/canonical WordPress URLs are correctly configured for HTTPS.

## The App connects but a write is denied

A successful OAuth connection is not write authorization. Check both:

1. the relevant Bridge access group under **WP Native Builder → Settings**;
2. the connected WordPress user's capability for the target object/action.

For example, publishing normally needs **Builder Write + Live Content** plus the applicable WordPress publish capability.

## `stale` or conflict errors

The object changed after it was inspected. Read it again, use the new `modified_gmt`/`state_hash` or Workspace `version`/`state_hash`, then decide whether the intended update is still correct.

Do not retry a stale update with the old identity.

## Astra is active but Astra tools are missing

Enable Astra's **Abilities** option. The Bridge reuses Astra's native Ability surface; a separate Astra MCP server is not required for this setup.

Reconnect/rescan the ChatGPT App if its tool catalogue was built before Astra Abilities were enabled.

## Code Snippets is active but integration is unavailable

The Bridge requires a compatible Code Snippets programmatic lifecycle in the current request. Current release testing covers both 3.9.x and 3.10.x provider model generations.

If status remains unavailable, verify the installed version, plugin activation, and whether another plugin is altering Code Snippets loading/capabilities. Rescan the App after changing provider state.

## Media upload fails

Check:

- **Builder Write** is enabled;
- the connected WordPress user has `upload_files`;
- WordPress accepts the MIME type;
- file size does not exceed either the WordPress upload limit or the Bridge 20 MiB cap.

The Bridge does not accept server filesystem paths.

## Plugin/theme installation fails

Installation accepts WordPress.org slugs only. The connected user needs the matching Core install/update/activate capability and **Code & Extensions** must be enabled.

If WordPress requires interactive filesystem credentials, the Bridge reports that manual filesystem setup is required rather than collecting those credentials.

## Workspace data is not visible through content tools

That is intentional. Workspace documents/tasks are private internal objects and are accessible only through `workspace-resume`, `workspace-document`, `workspace-task`, and the WP Native Builder admin screens.

## Reconnecting after an update

Replacing the plugin ZIP normally preserves settings and OAuth state. If the App tool list does not reflect new abilities after an update, use ChatGPT's App rescan/reconnect flow.

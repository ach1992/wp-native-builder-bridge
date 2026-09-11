# Optional integrations and advanced administration

This document defines the v0.1 Bridge boundary for optional site-stack providers and advanced WordPress administration.

## Resolution order

The normal installation remains **official WordPress MCP Adapter + WP Native Builder Bridge**. Optional themes/plugins are site components, not AI dependencies.

For an optional capability, the Bridge uses this order:

1. reuse a suitable stable registered provider Ability when that provider contract is verified;
2. otherwise use a bounded Bridge fallback only through a current supported public provider/WordPress API;
3. otherwise report the capability as unavailable and leave the provider untouched.

Generic third-party public Abilities remain discoverable through the existing external Ability catalogue. The Bridge does not invent provider namespaces or Ability IDs to manufacture compatibility.

## Astra

Astra 4.13.x provides an opt-in native `astra/*` Ability surface. The Bridge does not register Astra-specific duplicate tools. When the site owner enables Astra Abilities, the Bridge reports the observed public `astra/*` names for direct reuse.

The Bridge does not enable Astra's Abilities or edit-Abilities settings on behalf of the site owner. If Astra Abilities are disabled, Astra remains a valid site theme but no Astra-specific AI capability is implied.

## Gravity Forms

Current Gravity Forms versions can provide native `gravityforms/*` Abilities. If any registered native Gravity Forms Ability is present, the Bridge suppresses its GFAPI form fallback even when the provider has chosen not to expose that native Ability over MCP. This prevents the fallback from bypassing a provider-level exposure decision.

When Gravity Forms exposes `GFAPI` but has no native `gravityforms/*` Ability surface, the Bridge fallback is limited to form administration through documented `GFAPI` calls:

- list/get forms;
- create/update form objects;
- activate/deactivate a form;
- permanent form deletion under **Users & Destructive**.

The fallback does not access Gravity Forms tables directly. Entry/submission data is outside this fallback surface.

## Code Snippets

When the installed Code Snippets plugin exposes its supported namespaced programmatic lifecycle, the Bridge can manage site-local snippets through those functions. The Bridge never evaluates submitted code itself and never writes the plugin database tables directly.

The managed lifecycle covers create/update, read, activate/deactivate, trash/restore, and permanent deletion of an already-trashed snippet. Scope must be one currently accepted by the provider; the provider derives the snippet type from that scope. Locked snippets are not changed. Permanent deletion additionally requires **Users & Destructive**.

Code Snippets' own current capability contract remains authoritative; the Bridge asks the provider for that capability rather than hardcoding removed legacy snippet capabilities.

## WordPress site settings

The Bridge never exposes arbitrary `wp_options` access. The v0.1 site-configuration surface is restricted to:

- site title and tagline;
- front-page mode, front page, and posts page;
- posts per page;
- permalink structure.

Front/posts page IDs are validated as editable pages, the two page roles cannot point to the same page, and permalink changes flush rewrite rules through WordPress. Mutation requires **Site Configuration** plus the applicable WordPress capability.

## Plugin and theme lifecycle

The Bridge uses WordPress Core administration APIs rather than editing extension source files.

- installed plugin/theme state can be inspected under **Site Read**;
- install/update/activate/deactivate operations require **Code & Extensions** and the matching Core capability;
- install accepts only a WordPress.org slug resolved through Core APIs, not an arbitrary package URL or server path;
- deletion requires both **Code & Extensions** and **Users & Destructive**;
- an active plugin must be deactivated before deletion;
- the active theme or its active parent cannot be deleted;
- if WordPress cannot obtain non-interactive filesystem access, the Bridge reports that manual filesystem access is required and does not collect credentials.

## Users and roles

User/role inspection does not expose password hashes, generated passwords, application passwords, sessions, tokens, or other credential material.

User creation generates a random secret internally and asks WordPress to send the normal user notification; the generated secret is never returned or logged. Role assignment is restricted to roles WordPress considers editable by the acting user.

User removal is destructive and requires an explicit, different, valid reassignment user. The acting user cannot remove itself. On multisite, the Bridge removes the user from the current site rather than deleting the network account.

## Sensitive WooCommerce operations

WooCommerce may be present as a site-stack component, but v0.1 does not infer or expose broad commerce mutation merely because WooCommerce is active. Generic verified third-party Abilities can still appear in discovery. A WooCommerce-specific write fallback requires its own bounded contract and is not silently created here.

## Validation expectations

Provider-absent operation must remain clean. Optional provider tests must use disposable environments and restore their baseline state. Core administration tests must cover denied groups/capabilities as well as successful reversible operations. Provider reuse tests must observe current registered contracts rather than test-only invented Ability names.

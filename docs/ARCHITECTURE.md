# Architecture

## Normative source and implementation boundary

[`MASTER-SPEC.md`](../MASTER-SPEC.md) defines the product goal: full legitimate WordPress administration, discoverable and delegable through simple administrator-controlled settings. This document refines that specification and describes the actual component boundaries and outstanding coverage. Neither the current tool inventory nor a task's exclusions are a permanent product ceiling.

The architecture is deliberately small. Reuse WordPress identity/capabilities, registered Abilities, the official MCP Adapter, the existing Bridge settings and permission service, and thin public-API fallbacks. Do not create a second registry, policy language, per-provider permission engine, generic execution framework, or helper-plugin bundle.

## Runtime ownership

```text
AI / MCP client
  -> authenticated Bridge HTTPS endpoint
  -> official MCP Adapter transport and discovery/execution tools
  -> WordPress Abilities registry
       -> Core/provider-owned public contracts
       -> Bridge-owned typed fallback contracts
  -> the operation's actual WordPress/provider API and authorization
```

| Responsibility | Existing owner | Boundary |
| --- | --- | --- |
| Connection identity and revocation | `src/Auth/class-oauth-server.php`, `class-oauth-store.php` | WordPress-backed OAuth, resource/client binding, current WordPress principal; no separate AI superuser. |
| Native registry and invocation | WordPress Abilities API and official MCP Adapter | Native schemas, permission callbacks and lifecycle remain authoritative. |
| Exposure compatibility and reuse | `src/Abilities/class-ability-resolver.php` | Explicit MCP opt-out takes precedence over general public metadata. |
| Paginated public contract inspection | `src/Abilities/class-ability-catalog-abilities.php` | Read-only list/detail; no operation or target permission callback is invoked. |
| Bridge delegation settings | `src/Support/class-settings.php`, `class-permissions.php` | Small default-off groups plus actual WordPress authority, checked at execution. |
| Typed administration | Existing providers under `src/Abilities/` | Object-specific inputs, capabilities, lifecycle, error and integrity behavior. |
| Exact metadata persistence | `src/Support/class-post-meta-store.php`, `class-term-meta-store.php` | Fixed-purpose, fixed-schema row identity/CAS; not a generic database API. |
| Persistent Workspace | `src/Workspace/class-store.php`, Workspace abilities and admin screens | Private native storage, version/hash concurrency, dedicated administration. |
| Activity | `src/Support/class-mutation-log.php` | Bounded identity/outcome metadata, never request bodies or secrets. |

Production requires the official Adapter and this Bridge, not Composer, Docker, Node.js, a daemon, another database, or an external identity platform. Build and integration tooling remain development-only.

## Discovery and reuse

Use the native registry as the one operation inventory. Prefer a suitable Core/provider Ability with its real public contract. Otherwise use a supported public WordPress/provider API, including an appropriate registered REST contract, through the smallest typed fallback needed for the actual gap. Only a proven public contract justifies a provider-specific fallback. Keep such fallbacks removable when upstream publishes a suitable native Ability.

Do not infer execution compatibility or authority from names, descriptions, category names, or read-only annotations. A provider explicitly hiding its native Ability is not permission to expose an equivalent lower-level fallback. Unknown/private business behavior needs a verified implementation path, not guessed storage mutation.

`bridge-info` reports dependencies and enabled Bridge groups. `site-context` preserves its compact installation context and first-50 external reuse hints. `abilities-read` supplements it with sorted, filtered, paginated public Core/Bridge/provider contracts and exact named schema reads. It excludes non-public and explicitly MCP-hidden contracts and does not return arbitrary provider metadata. The shared resolver matches the pinned Adapter: malformed MCP metadata is denied, explicit non-null MCP public flags take precedence, and an inherited general public flag must be exactly boolean true.

Contract inspection always reports `execution_permission: not_evaluated`. A schema is not permission, and a target-specific provider callback cannot safely be evaluated without its real valid input. Native permission checks still run when the operation is executed. Bounded errors replace oversized/unrepresentable inspection output; schemas are never silently truncated.

The broader specification also requires actionable delegation/effect/availability diagnostics. This public-contract increment does not yet infer arbitrary provider capabilities, classify every effect, or provide complete per-target permission diagnostics. Those remain explicit implementation gaps, not fabricated discovery fields.

## Delegation: current behavior and required evolution

**Current implementation:** Bridge-owned operations enforce their documented groups and native capabilities. The Bridge's direct server exposes the Adapter's native discover/get-info/execute tools. Reused provider Abilities retain their own WordPress permission callbacks, but the existing Bridge groups do not uniformly gate every provider-native operation. Disabling a Bridge write group must not be advertised as revoking all provider-native writes.

**Required evolution:** extend the existing policy/execution boundary so delegated access is understandable and enforceable for every operation reached through the Bridge, including native provider operations and any future registered-REST fallback. Preserve provider callbacks and the authenticated principal's actual authority. Do not infer trusted effect classes from arbitrary provider prose/annotations, introduce provider allowlists, or silently expand consent on upgrade. Unclassified operations need an honest administrator decision/explicit trust boundary rather than an invented safe category.

Inspection and execution must consume the same effective policy when that extension is implemented; copied registry snapshots or old discovery results must not authorize execution after revocation. Ordinary data/provider routes must not self-enable their own Bridge access. Executable code remains an explicit elevated trust grant, not a sandbox that can guarantee containment of intentionally authorized PHP.

Use existing WordPress roles/capabilities for identity and object authority; use Bridge settings for delegation. Adding an authenticated connection must not implicitly grant administrator or network authority. Capability changes, disconnection and policy revocation must be checked against current state.

## Administrative coverage and remaining gaps

This table is a code-backed capability inventory, not a roadmap schedule or a live task ledger. `Implemented` means a typed contract exists, not that its access is enabled or that the current user may execute it. The list is non-exhaustive and does not redefine the root specification. Update the relevant row when a tested implementation reaches the target branch; active work and ordering belong in GitHub Issues/PRs.

| Family | Implemented entry points / owner | Remaining coverage against the specification |
| --- | --- | --- |
| Context and discovery | `bridge-info`, `site-context`, `integration-status`, `abilities-read`; native Adapter discovery | Uniform provider delegation, effect/delegation diagnostics and input-dependent availability explanations. |
| Content and revisions | `class-content-abilities.php`, `class-content-eligibility.php` | Administration of objects with different private/internal lifecycles must use appropriate contracts rather than widening ordinary authoring blindly. |
| Blocks and appearance | `class-block-abilities.php`, `class-navigation-abilities.php`; compatible theme/provider Abilities | Generic widget/template/style administration and authoritative editor-serialization or staged-theme workflows where upstream supports them. PHP parse/serialize is not editor validation. |
| Media | `class-media-abilities.php`: inspection, Base64 upload, metadata update and deletion | Explicit URL import and additional validated file workflows; do not confuse a missing URL operation with a disabled permission. |
| Taxonomies and metadata | `class-taxonomy-abilities.php`; generic `post-meta-*` and exact-taxonomy `term-meta-*` with protected-key opt-in and physical-state integrity | User/comment metadata with distinct authorization. No provider/key allowlists. |
| Configuration | `class-site-config-abilities.php`: bounded site-setting fields; compatible provider Abilities | Broader registered site/network/theme/provider settings and explicit semantics for unregistered settings. The current field list is not a permanent product policy. |
| Extensions and source | `class-extension-abilities.php`: installed inventory and WordPress.org lifecycle; optional managed snippets | Separately consented uploaded/URL package sources and installed plugin/theme source read/preview/apply/recovery. |
| Users and access | `class-user-abilities.php`: bounded users/roles, account upsert/removal | Wider role/capability, membership, session and authentication lifecycle with real delegable authority; no generic secret dumping. |
| Comments | No Bridge-owned moderation contract yet; compatible provider contracts may exist | Native comment inspection, moderation/replies/status/deletion and comment metadata. |
| Tools and maintenance | Environment inspection and any compatible installed-provider Ability | Supported import/export, scheduled tasks, maintenance/cache and backup/restore workflows, without a raw shell or database console. |
| Provider business administration | Native public Abilities; verified Gravity Forms and Code Snippets fallbacks | Additional installed-provider workflows through their real public lifecycle; generic post metadata is not a replacement for commerce/order or private provider storage. |
| Multisite | Existing operations remain subject to native WordPress authority | Explicit site/network administration and delegation, with real Super Admin/site boundaries and dedicated tests. |
| Persistent Workspace | `workspace-resume`, `workspace-document`, `workspace-task` and admin lifecycle | Preserve version/hash guarantees and dedicated privacy boundaries as coverage grows. |

## Integrity and lifecycle

Use the operation's owning API, not a generic storage write that bypasses business validation. Registered metadata authorization and additional mapped capabilities remain authoritative. The protected-unregistered metadata opt-in is deliberately narrow: exact target authority, enabled Advanced Metadata, no explicit provider denial, no credential-like key, and lossless single-row state.

Existing post and term metadata updates/deletes use exact physical-row identity and byte-exact conditional persistence. Term authority additionally binds the exact taxonomy and canonical term identity; compensation retains the original term-taxonomy identity. Compensate only the current invocation's own unchanged row; never overwrite newer state to manufacture success. Share policy code where semantics are identical, but keep object-specific authority and lifecycle separate when extending metadata to users or comments.

For content/blocks/Workspace and future settings/files, use the current-state identity appropriate to overwrite risk. Preserve revisions where native, verify persistence, and describe partial failure/recovery accurately. Workspace internals remain inaccessible through unrelated content/meta operations but manageable through dedicated Workspace contracts.

## Network, package and source workflows

These are required coverage, not yet a claim that every workflow is implemented. Use explicit default-off consent for materially new outbound or executable authority on fresh install and upgrade. Media import uses safe bounded streaming and normal MIME/attachment handling; it cannot install executable packages. Package installation uses its own WordPress installer/lifecycle and provenance/target checks, without a permanent WordPress.org-only policy.

Source editing must resolve an installed extension and a WordPress-editable relative file, enforce exact file-edit capabilities and deployment restrictions, reject traversal/symlink escapes, preview exact previous/candidate bytes, persist with stale-state protection, and provide a private preimage/recovery path. Verify the actual Core editor API's loopback authentication, active/inactive/network behavior and rollback ownership before reuse. A nonce is not substitute authentication, and restoring a file does not undo PHP side effects.

Hooks, custom plugins and child themes remain preferable for routine customization; they are guidance rather than a blanket ban on an explicitly authorized vendor-file change. Editing the Bridge/Adapter itself requires an exact connection-loss and independently reachable recovery plan, not a hidden provider blacklist.

## Validation and evolution

For each increment, retain existing quality/static checks, test negative permissions and revocation as well as success, verify real WordPress behavior on both supported integration lanes, and exercise the actual Adapter contract. Add fixture providers/custom targets rather than assuming a named vendor defines coverage. High-risk execution surfaces need independent exact-candidate review and the applicable integration/production gates.

Update the root specification only for accepted product-level changes. Refine this architecture and public operation documentation when implementation changes. Keep task scope, current candidates, CI results, ownership and blockers in GitHub, not in parallel manager-memory documents. Missing capabilities remain tracked conformance gaps; completing one increment is not full administrator parity.

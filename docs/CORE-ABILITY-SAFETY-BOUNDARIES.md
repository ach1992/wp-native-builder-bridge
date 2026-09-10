# WP Native Builder Bridge — Core Ability Safety Boundaries

Status: Canonical v0.1 implementation constraints for Issue #3
Repository: `ach1992/wp-native-builder-bridge`
Primary owner: Issue #3

## Purpose

This document consolidates the durable core-ability safety rules established while implementing and independently probing Issue #3. It exists so future implementation/review does not need to reconstruct required behavior from historical Issue comments.

`MASTER-SPEC.md` remains the project-level architecture. Issue #3 owns the live implementation contract and acceptance. This document records the detailed safety boundary that Issue #3 must satisfy.

## 1. Generic content eligibility

Generic WordPress coverage should remain broad without treating every registered post type as ordinary Builder content.

- `post` and `page` are explicitly supported ordinary content types.
- For third-party/custom post types, `show_ui` alone is not sufficient evidence that the type is safe generic Builder content.
- Generic eligibility should use one small shared content-facing predicate rather than provider-specific allow/deny lists.
- Administrative, transactional, internal, or provider-control records must stay out of generic content operations unless a dedicated verified Ability/API integration intentionally owns that workflow.
- A representative non-public `show_ui=true` administrative CPT without editor support must be rejected.
- A representative genuinely content-facing/editor-capable CPT must remain supported.

This boundary is provider-neutral. WooCommerce order-like records and future Workspace-internal objects are examples of why broad `show_ui` eligibility is unsafe, not names to hardcode into a blacklist.

## 2. Gutenberg/block eligibility

Block operations have a narrower ownership boundary than generic post metadata/content CRUD.

- The target post type must satisfy the generic content eligibility rule.
- The target must also support the WordPress editor (`post_type_supports( $type, 'editor' )` or equivalent tested contract).
- Block mutation must preserve unrelated blocks/content.
- A block operation must not become a path to mutate an administrative/internal post type rejected by generic content eligibility.

## 3. Verified Ability reuse only

Reuse-first means reuse a real, verified provider contract; it does not mean guessing likely future Ability names.

- A reuse candidate must be observed in the current WordPress Abilities registry or grounded in verified stable provider documentation/contract evidence.
- Do not probe, advertise, or special-case speculative Ability IDs as supported resolution paths.
- If no suitable verified Ability exists, use the Bridge-owned supported-API fallback when one exists.
- If neither a verified Ability nor supported API exists, report a capability gap rather than binding to private storage/internals.

The previously probed speculative `core/read-content` name is not a supported Core contract unless a current verified WordPress version actually exposes that exact Ability.

## 4. Mutation-relevant optimistic concurrency

Overwrite protection must cover the state an operation can actually replace.

### Full content updates

A full `content-upsert`-style update must expose and require a deterministic current `state_hash` (name may vary) derived from the mutation-relevant state it can overwrite, including at minimum:

- title;
- content;
- excerpt;
- status;
- slug;
- parent;
- menu order;
- template;
- featured media.

The expected state identity must be verified immediately before mutation. `post_modified_gmt` plus a body-only content hash is not sufficient for this operation because non-body fields can change independently.

`revision-restore` requires equivalent stale-state protection for the fields/state it can overwrite. WordPress revisions remain useful rollback/history for normal content where available; revision identity is not a substitute for mutation-relevant current-state conflict detection.

### Targeted block updates

A targeted Gutenberg operation that owns only `post_content` may use a narrower deterministic content fingerprint, provided it is checked against current content immediately before mutation and stale content is rejected.

Do not introduce a lock manager or transaction framework merely to satisfy these rules; optimistic conflict detection is the intended model.

## 5. Destructive status boundary

Ordinary content upsert must not become a hidden delete/trash path.

- Reject destructive/Core-internal statuses such as `trash`, `auto-draft`, or `inherit` when they are not legitimate authoring targets.
- Trash/permanent deletion belongs to the dedicated destructive content operation guarded by `Users & Destructive` and normal WordPress capabilities.
- Legitimate registered publication/custom statuses may remain supported under their applicable permission contract.

## 6. Navigation permission boundary

Dedicated navigation operations and generic content operations have different contracts and must not leak into each other.

- Dedicated navigation create/update/reorder belongs to `Builder Write` plus the applicable WordPress capability.
- A blanket `Live Content` gate is not required merely because navigation is site-wide.
- Permanent menu/navigation item removal is destructive and must require `Users & Destructive`; Ability metadata must represent that destructive path accurately, or deletion should be isolated if that is cleaner.
- Generic content/block mutation of a published `wp_navigation` object retains the normal generic live-content boundary.
- Dedicated navigation permissions must not create a special generic-content bypass for published `wp_navigation`.

## 7. Workspace relationship

Post-v0.1 Workspace Issue #8 depends on this generic-content boundary being correct before private Workspace objects are introduced.

Workspace-internal objects must additionally be explicitly excluded from generic content/block abilities even if future registration details could otherwise satisfy a generic predicate. Workspace access is only through the dedicated typed Workspace contract.

## 8. Required regression evidence

Before Issue #3 is considered complete, validation must discriminate at least:

1. non-public/admin-only non-editor CPT rejected;
2. representative content-facing editor-capable CPT accepted;
3. speculative/nonexistent Ability ID not treated as a real reuse path;
4. non-body concurrent content change with unchanged body/timestamp causes full-update conflict;
5. current-state full content update succeeds with the correct state identity;
6. targeted block update rejects stale content but remains scoped to `post_content`;
7. destructive status cannot bypass the destructive group through ordinary upsert;
8. permanent navigation-item removal cannot bypass `Users & Destructive`;
9. generic published `wp_navigation` mutation cannot bypass the normal live-content boundary;
10. existing core/media/taxonomy/navigation happy paths remain functional.

These rules should be implemented with the smallest shared helpers needed. Do not introduce a provider blacklist, policy engine, lock manager, or speculative compatibility layer solely to satisfy the safety boundary.

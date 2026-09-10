# WP Native Builder Bridge — Persistent Workspace Architecture

Status: Accepted post-v0.1 architecture; not yet implemented
Repository: `ach1992/wp-native-builder-bridge`
Companion: `ach1992/wp-native-builder`

## 1. Purpose and scope

The Persistent Workspace gives WP Native Builder enough durable WordPress-hosted project state to continue the same site project across fresh ChatGPT conversations without requiring old chat history.

This is intentionally a small persistence and typed-capability feature inside the Bridge. It is not a chat archive, project-management platform, vector-memory system, hosted service, or replacement for live WordPress state.

The first model is one Workspace per WordPress site. Multi-workspace/project switching is out of scope until a demonstrated need justifies it.

This feature is post-v0.1. It must not expand or block the current v0.1 delivery path tracked by Issues #1–#6.

The companion Skill owns reasoning, retention decisions, task selection, visual-review workflow, and conversational approval. The Bridge owns WordPress-native Workspace storage, typed abilities, isolation, permissions, admin inspection/export/clear behavior, versioning, and deterministic errors.

## 2. Source-of-truth model

Workspace and live WordPress own different kinds of truth:

- Workspace owns durable project intent, accepted decisions, lightweight task/progress state, and concise continuation context.
- Live WordPress owns what actually exists now: posts, pages, media, menus, settings, forms, products, theme state, plugin state, and other live objects.
- A Workspace target reference accelerates discovery; it never authorizes blind overwrite of the referenced live object.
- If a human or another chat changed a live object after a Workspace checkpoint, the current live object must be re-read and reconciled before an overwrite-sensitive mutation.

Workspace must never be treated as a duplicate copy of the live site.

## 3. Retention boundary

Persist only durable information that materially helps a future conversation continue correctly.

Good candidates include, when useful:

- project brief and site profile;
- sitemap/page-template plan;
- accepted design system or visual direction;
- content/data model;
- lasting architecture/design/content decisions;
- unresolved QA/launch findings;
- active tasks, blockers, dependencies, review state, and delivery state;
- WordPress target references;
- concise notes needed to resume non-obvious unfinished work.

Do not persist by default:

- full chat transcripts;
- model chain-of-thought or hidden reasoning;
- routine worklogs or session summaries;
- endless checkpoint/handoff documents;
- every micro-edit;
- duplicate copies of live page/post/media content;
- disposable experiments;
- credentials, auth material, salts, private keys, or tokens;
- arbitrary database/plugin dumps;
- unnecessary customer/order/payment/financial data.

The preferred shape is a few current useful documents/tasks plus bounded history where it materially helps recovery.

## 4. WordPress-native storage and isolation

Prefer small WordPress-native persistence. The initial implementation may use dedicated private/internal post types such as logical `wpnb_doc` and `wpnb_task`, or an equally simple WordPress-native representation if implementation evidence supports something better.

Workspace objects must be structurally isolated from ordinary site content. If private post types are used, they must be configured so they have no unintended:

- public query route or front-end permalink;
- site-search exposure;
- feed exposure;
- navigation/menu exposure;
- ordinary Posts/Pages editor exposure;
- generic Builder content or Gutenberg/block access.

The Bridge may provide its own administration screens for these records without making their underlying storage publicly queryable or exposing the ordinary post editor.

### Critical generic-content boundary

Workspace internal object types must be explicitly excluded from generic content and Gutenberg/block operations even though WordPress may represent them as post types.

Issue #3 already owns the generic content/CPT safety boundary. Workspace implementation must depend on a safe generic-content eligibility predicate and must add an explicit internal-Workspace exclusion where appropriate. Do not solve this with a broad provider blacklist or policy engine.

Workspace records are reachable only through the dedicated Workspace contract.

## 5. Revision durability, version identity, and history

Workspace correctness must **not depend on WordPress revisions existing**.

WordPress revisions are useful optional history, but their retention can be limited by WordPress configuration/filters and they may also be removed by maintenance/cleanup plugins. Therefore:

1. the current authoritative Workspace document/task state must live in the primary Workspace object, not only in a revision row;
2. every overwrite-sensitive Workspace object must expose a Bridge-owned version identity independent of WordPress revision IDs, such as a monotonically increasing `version` plus a deterministic `state_hash` over the mutation-relevant state;
3. update operations must require the expected current version/state identity and reject stale writes deterministically;
4. `workspace-resume` and normal continuation must never require an old WordPress revision to exist;
5. WordPress revisions may still be enabled as convenient secondary history when available, but their absence or pruning must not break current Workspace state or concurrency protection;
6. if the product requires rollback/history that must survive ordinary revision cleaners, retain only the necessary bounded history as Bridge-managed private Workspace snapshot/version records that are **not** WordPress `revision` posts and are not subject to revision-retention semantics;
7. bounded Workspace history needs an explicit retention policy so durable continuity does not become an unlimited archive;
8. export must include the current durable Workspace state and any Bridge-managed history that the implementation defines as part of the Workspace recovery contract.

The Bridge cannot guarantee survival against a plugin, administrator, database operation, host restore, or other actor that deliberately deletes arbitrary WordPress data. Site/database backups remain the disaster-recovery boundary for catastrophic data loss. The Workspace design only ensures that normal WordPress revision pruning/cleanup is not a required dependency for project continuity.

## 6. Optimistic concurrency

Multiple ChatGPT conversations and humans may work on the same site. Workspace mutations therefore use optimistic concurrency rather than locks.

Expected flow:

1. read the current document/task and its version identity;
2. submit the expected version/state identity with an overwrite-sensitive update;
3. if the identity is stale, return a deterministic conflict and do not overwrite;
4. re-read the current object;
5. reconcile the intended change with newer valid state;
6. retry only after reconciliation.

The public contract must distinguish at least:

- success;
- stale/conflict;
- not found;
- permission denial;
- invalid input;
- other deterministic operational errors.

Do not blindly retry ambiguous writes.

## 7. Logical Workspace ability surface

Keep the public logical surface small. Initial capabilities are:

### `workspace-resume`

Return a compact orientation packet containing only enough information for a fresh chat to decide what to read next, such as:

- project/site identity;
- current focus/stage when useful;
- active/in-progress tasks;
- blocked tasks;
- work awaiting user review;
- relevant document index;
- important recent durable decision/context when useful;
- object/version identity needed for subsequent reads.

It must not dump the entire Workspace or completed history into context.

### `workspace-document`

Typed actions for Markdown-oriented durable documents:

- list;
- get;
- create;
- update;
- archive.

Updates use optimistic concurrency/version identity.

### `workspace-task`

Typed actions for lightweight tasks:

- list;
- get;
- create;
- update;
- transition;
- archive where appropriate.

Do not create dozens of field-specific abilities when one typed action contract is clearer.

## 8. Document model

Workspace documents are Markdown-oriented and named by purpose, not session. Common examples include:

- `project-brief`;
- `site-profile`;
- `sitemap`;
- `design-system`;
- `content-model`;
- `decisions`;
- `qa`.

These are examples, not mandatory templates. Do not automatically create every document on every site. Prefer a few current documents over fragmented notes and repeated checkpoints.

## 9. Task model

A task only stores enough information to resume and finish meaningful site work.

Progress:

`todo | in_progress | blocked | done`

Review:

`not_required | pending | changes_requested | approved`

Delivery:

`not_applicable | draft_preview | live`

Useful optional fields may include:

- title/goal;
- concise acceptance criteria;
- dependencies/blocker;
- WordPress target references;
- concise durable notes.

Progress, review, and delivery remain separate facts. For example, a task may be implementation-complete but still await user visual review or live publication.

Do not turn every small WordPress edit into a persistent task.

## 10. Permissions and conversational approval

Bridge permission and conversational approval remain separate.

For the initial design:

- Workspace resume/read operations should require `Site Read` plus an appropriate WordPress editing capability;
- Workspace document/task create/update/archive operations should require `Builder Write` plus an appropriate WordPress editing capability;
- the administrator-only Workspace UI, export, retention/uninstall settings, and whole-Workspace clear controls require `manage_options`;
- whole-Workspace clear/delete is destructive and must also use the Bridge's destructive-access boundary plus an explicit admin-browser confirmation/nonce path;
- exact capability mapping may be refined during implementation when real WordPress behavior is tested, but it must not create a new complex per-record authorization system.

Routine reversible Workspace document/task writes do not require conversational approval merely because they mutate project memory. Live publishing and other consequential site actions keep the companion Skill's existing approval rules.

## 11. Admin UX

When Workspace is implemented, migrate the Bridge administration into a recognizable top-level WordPress area:

```text
WP Native Builder
├── Dashboard
├── Documents
├── Tasks
├── Activity
└── Settings
```

This is a post-v0.1 UX evolution. The current v0.1 `Settings -> WP Native Builder` screen remains valid until the Workspace phase is implemented.

### Dashboard

Show only useful orientation:

- Workspace/project identity;
- current focus;
- active/blocked/review-needed task counts;
- connection/dependency status;
- last meaningful Workspace update.

Do not build a large project-management dashboard.

### Documents

Allow administrators to inspect Workspace documents.

### Tasks

Allow inspection/filtering of active, blocked, review-needed, and completed tasks.

### Activity

Keep the existing bounded Bridge action/mutation log separate from Workspace memory. Activity/audit history is not project memory.

### Settings

Include connection/dependency status, access groups, Workspace settings, export/clear controls, and applicable retention/uninstall options.

Initial human Workspace management should prioritize View, Export, and Clear. A full manual Markdown/task editor is not required for the first implementation unless implementation evidence shows it is worthwhile.

## 12. Data lifecycle

- Plugin deactivation must not delete Workspace project data.
- Uninstall must not silently erase durable Workspace data.
- Delete-on-uninstall, if supported, must be explicit opt-in and clearly described.
- Provide an explicit administrator export path.
- Provide an explicit administrator clear/delete path with capability, nonce, confirmation, and destructive-access safeguards.
- Current useful Workspace state should remain compact; completed historical tasks must not dominate resume output.

## 13. Security and privacy

Workspace is not a generic secret store.

Never persist:

- WordPress passwords;
- application passwords;
- API tokens or auth headers;
- salts or private keys;
- session/auth material;
- model hidden reasoning/chain-of-thought;
- arbitrary database/plugin dumps;
- unnecessary personal/customer/order/payment/financial records.

Use specific live capabilities for sensitive operational data rather than copying that information into general Workspace memory.

## 14. Context efficiency and continuation

A fresh connected chat should normally follow:

```text
workspace-resume
  -> identify current focus/tasks/document index
  -> fetch only the relevant task/document
  -> inspect current live WordPress state when it matters
  -> reconcile newer valid state
  -> continue the next useful action
```

Completed tasks/history should not dominate the normal resume response.

Workspace must remain useful for complete-site projects and later maintenance. Durable approved design/project context and unresolved work can persist after launch; explicit redesign instructions may supersede older conventions.

## 15. Validation requirements

Bridge implementation must provide observable validation for at least:

1. private/non-public Workspace storage with no unintended front-end/search/feed/navigation exposure;
2. generic content/block abilities reject Workspace internal object types;
3. permission denial when required Bridge groups or WordPress capabilities are absent;
4. document/task create/read/update/archive behavior;
5. compact `workspace-resume` behavior that does not dump the Workspace;
6. optimistic concurrency and deterministic stale-write rejection;
7. current Workspace state and concurrency remain functional when WordPress revisions are disabled/pruned;
8. any required durable rollback/history remains available through Bridge-managed Workspace history rather than depending solely on revision rows;
9. no credentials/secrets/hidden reasoning in Workspace outputs or storage paths;
10. plugin deactivation preserves Workspace data;
11. uninstall deletion is explicit/opt-in rather than surprising;
12. export and clear administration paths;
13. top-level admin navigation when the Workspace UX is implemented;
14. no regression in existing Bridge abilities;
15. connected fresh-chat recovery once both the companion Skill runtime and Bridge Workspace capability are available for joint testing.

The final connected fresh-chat E2E is cross-repository work and should coordinate with the companion Skill's post-v0.1 validation/release program rather than duplicating a second orchestration framework in this repository.

## 16. Dependencies and sequencing

- Current v0.1 delivery remains Issues #1–#6 and is not blocked by Workspace.
- Workspace implementation begins post-v0.1.
- The generic content/CPT safety boundary in Issue #3 must be integrated before Workspace relies on private internal post types.
- Bridge-local Workspace implementation/validation is tracked in the dedicated Workspace Issue created from this architecture reconciliation.
- Companion Skill runtime behavior is tracked in `ach1992/wp-native-builder#14`.
- Connected fresh-chat E2E/package validation is coordinated with `ach1992/wp-native-builder#16` after both sides provide the required runtime capability.

Do not implement Workspace on the active Issue #3 branch. Keep this architecture/backlog work independent from current concurrent core-ability implementation.

## 17. Non-goals

- Multi-workspace switching in the initial release.
- Chat transcript storage.
- Hidden reasoning/chain-of-thought storage.
- Reimplementing GitHub Issues/Projects or a project-management engine in WordPress.
- Vector databases, embeddings, external persistence services, or a second database.
- Arbitrary key/value or arbitrary database storage abilities.
- Treating Workspace objects as ordinary Posts/Pages/Gutenberg content.
- Depending on WordPress revisions as the sole source of current state, concurrency identity, or required recovery history.
- Building a fixed launch checklist/workflow engine in the Bridge.

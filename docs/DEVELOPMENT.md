# Development and testing

## Project continuity

Before changing durable architecture or resuming development after a long gap, read:

- [`../MASTER-SPEC.md`](../MASTER-SPEC.md) for canonical project intent and durable constraints;
- [`maintainer/README.md`](./maintainer/README.md) for the recovery/source-of-truth map and preserved design references.

Do not reconstruct active work from old chats or historical reference files. Current source/tests, GitHub Issues/PRs, CI, and Releases own mutable implementation/project state.

## Local quality gate

Install development dependencies and run:

```bash
composer install
composer check
```

The quality gate includes:

- strict Composer validation;
- PHP syntax checks;
- dependency-free functional tests;
- WordPress Coding Standards;
- PHPCompatibilityWP;
- Persian translation catalog validation;
- static safety-surface checks;
- release ZIP build and validation.

The generated package is:

```text
build/wp-native-builder-bridge.zip
```

## Integration tests

Docker-based integration lanes build the release ZIP, install that ZIP into WordPress, install the pinned official MCP Adapter, and exercise the plugin in isolated volumes.

```bash
bash bin/run-integration.sh 6.9-php8.4-apache
bash bin/run-integration.sh php8.4-apache
RUN_OPTIONAL_PROVIDERS=1 bash bin/run-integration.sh php8.4-apache
```

Coverage includes WordPress 6.9/current, direct OAuth/MCP transport, raw MCP discovery/execution, content/block safety, Persian runtime localization, Workspace concurrency/lifecycle, Astra native Ability reuse, and Code Snippets provider generations.

Gravity Forms automated coverage uses a test-only GFAPI contract fixture; it is not evidence that a commercial Gravity Forms binary was executed in CI.

## Repository layout

```text
src/Abilities/   typed WordPress/provider abilities
src/Admin/       WP Native Builder admin screens
src/Auth/        direct OAuth and MCP authorization
src/Support/     settings, permissions, environment, mutation log
src/Workspace/   private durable Workspace storage
languages/       bundled WordPress translation catalog
tests/           fast and integration tests
bin/             development/build/test helpers
docs/maintainer/ recovery map and preserved design references
```

## Release process

1. update the plugin version and changelog when a new plugin build is being released;
2. run `composer check`;
3. run both WordPress integration lanes, including optional providers;
4. merge only after exact-head CI is green;
5. build the ZIP from the release commit;
6. create an immutable Git tag and GitHub Release for that commit;
7. attach the validated ZIP and verify its checksum/metadata.

Do not force-move a published version tag. Use a patch release for post-publication plugin-package changes.

Repository-only documentation/maintainer updates do not require a plugin version bump unless they change the shipped plugin package or published product contract.

## Generic term metadata validation

`composer test` includes `tests/issue36-term-meta.php` for bounded schemas/physical reads, target and group gates, shared secret policy, JSON/opaque-value refusal and stale-state failures. The actual WordPress capability/filter and persistence behavior is exercised by `tests/integration/issue36-term-meta-security-smoke.php` in **both** existing integration lanes. The fixture uses Core categories/tags and a private custom taxonomy with a dedicated edit capability, without an external provider plugin.

Keep the Issue #34 regression/integration tests unchanged. The new tests cover explicit/provider/mapped authorization, shared term identity, physical defaults/virtual reads, exact-byte CAS, duplicate contention, original-invocation creation ownership, compensation lifecycle/cache state, SQL NULL/scalar/slashing behavior, sanitizer pass count, authority/target races (including taxonomy or term-taxonomy identity changes before and during all three compensation pre-hooks) and log redaction. `bin/static-safety-check.sh` confines the term store separately; adding another database surface requires explicit architectural review, not an exclusion from the check.

# Development and testing

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
```

## Release process

1. update the plugin version and changelog;
2. run `composer check`;
3. run both WordPress integration lanes, including optional providers;
4. merge only after exact-head CI is green;
5. build the ZIP from the release commit;
6. create an immutable Git tag and GitHub Release for that commit;
7. attach the validated ZIP and verify its checksum/metadata.

Do not force-move a published version tag. Use a patch release for post-publication changes.

# v0.1 release checklist

This is the release gate for Issue #6. Do not publish/tag merely because implementation tests are green.

## Automated technical gates

- [ ] `composer check` passes.
- [ ] `composer validate --strict` passes after the owner-selected license is added to repository/package metadata.
- [ ] WordPress 6.9.x / PHP 8.4 integration lane passes.
- [ ] current stable WordPress / PHP 8.4 integration lane passes.
- [ ] official MCP Adapter v0.6.1 installs and the Bridge installs from the generated ZIP.
- [ ] HTTP transport/session smoke passes.
- [ ] raw STDIO MCP `tools/list -> discover -> get-info -> execute` passes.
- [ ] representative raw MCP draft, Gutenberg mutation, publish gate, media, site configuration, and administration-read workflow passes.
- [ ] real Code Snippets 3.10.2 provider lane passes.
- [ ] real Astra 4.13.11 native Ability reuse lane passes.
- [ ] Gravity Forms GFAPI transport contract fixture passes and is explicitly reported as a contract fixture, not commercial-binary validation.
- [ ] release uninstall removes only v0.1 Bridge-owned options/files and does not delete site/provider content.
- [ ] CI is green on the exact release candidate SHA.
- [ ] release ZIP artifact can be downloaded and its digest recorded.

## Human/product gates

- [ ] A real ChatGPT Business/Enterprise/Edu developer-mode app is connected through a Secure MCP Tunnel (or another explicitly approved deployment architecture).
- [ ] `tunnel-client doctor` reports the runtime healthy/ready before ChatGPT testing.
- [ ] ChatGPT tool scan sees the MCP Adapter layered tools.
- [ ] ChatGPT can discover and execute `wp-native-builder/bridge-info`.
- [ ] ChatGPT can create/read a draft and perform a targeted Gutenberg mutation on a disposable test object.
- [ ] ChatGPT correctly receives denial when a required Bridge access group is disabled.
- [ ] If live-content testing is approved, a disposable live-content transition is performed and rolled back/removed.
- [ ] Owner chooses the Bridge license. Do not infer it from the companion Skill or another repository.
- [ ] Owner explicitly authorizes the public v0.1.0 tag/release after reviewing the license and final evidence.

## Secret-handling gate

- [ ] No OpenAI runtime/admin key, WordPress Application Password, password, token, private URL, or other secret is committed, logged, pasted into release notes, or stored in Bridge mutation logs.
- [ ] Tunnel runtime uses a dedicated runtime key with least privilege (Tunnels Read + Use).
- [ ] An OpenAI admin key is not used by the long-running tunnel runtime.
- [ ] The WordPress user used by MCP has only the capabilities needed for intended operations.

## Release metadata gate

Before final v0.1.0 packaging:

- change plugin header/version constant from `0.1.0-dev` to `0.1.0`;
- add owner-selected license file/header/package metadata;
- run strict Composer validation;
- rebuild the ZIP from the exact release candidate;
- run exact-candidate CI again;
- verify the ZIP contains runtime files only plus required README/license/uninstall metadata;
- record the final ZIP digest;
- tag/release only after explicit owner authorization.

## Post-release

Issue #8 Persistent Workspace remains a separate post-v0.1 project. It must not retroactively expand this release gate or cause current v0.1 uninstall logic to silently delete future Workspace data.

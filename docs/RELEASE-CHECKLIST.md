# v0.1 release checklist

This is the release gate for Issue #6. Do not publish/tag merely because implementation tests are green.

## Automated technical gates

- [ ] `composer check` passes.
- [ ] `composer validate --strict` passes after the owner-selected license is added to repository/package metadata.
- [ ] WordPress 6.9.x / PHP 8.4 integration lane passes.
- [ ] current stable WordPress / PHP 8.4 integration lane passes.
- [ ] official MCP Adapter v0.6.1 installs and the Bridge installs from the generated ZIP.
- [ ] MCP Adapter default HTTP transport/session regression passes without changing that server's behavior.
- [ ] Bridge direct MCP endpoint is registered through the official MCP Adapter `HttpTransport`.
- [ ] protected-resource metadata advertises the exact direct MCP resource and WordPress authorization server.
- [ ] authorization-server metadata advertises Authorization Code, PKCE `S256`, refresh tokens, CIMD, `private_key_jwt`/`RS256`, issuer identification, and the supported scopes.
- [ ] ChatGPT CIMD is fetched only from the fixed client identifier and pins the expected `private_key_jwt` method plus fixed `https://chatgpt.com/oauth/jwks.json` JWKS URI.
- [ ] signed client assertions validate `RS256`, `iss`/`sub`, authorization-server audience, bounded lifetime, and one-time `jti`; missing/tampered/replayed assertions fail closed.
- [ ] missing/invalid direct-MCP credentials fail at HTTP authorization with a 401 OAuth discovery challenge.
- [ ] authorization code + PKCE exchange passes and code replay fails.
- [ ] access-token storage regression proves the bearer secret is not persisted in plaintext.
- [ ] access tokens are bound to user/client/resource/scope/expiry and rejected when invalid/revoked.
- [ ] omitted `scope` defaults to `mcp:use` only; `offline_access` is never granted implicitly.
- [ ] refresh tokens are issued only when `offline_access` is explicitly granted; rotation passes and old refresh-token replay fails.
- [ ] OAuth-authenticated direct MCP `initialize` passes.
- [ ] raw STDIO MCP `tools/list -> discover -> get-info -> execute` remains working for non-ChatGPT/local clients.
- [ ] representative raw MCP draft, Gutenberg mutation, publish gate, media, site configuration, and administration-read workflow passes.
- [ ] real Code Snippets 3.10.2 provider lane passes.
- [ ] real Astra 4.13.11 native Ability reuse lane passes.
- [ ] Gravity Forms GFAPI transport contract fixture passes and is explicitly reported as a contract fixture, not commercial-binary validation.
- [ ] release uninstall removes Bridge v0.1 settings/mutation metadata, OAuth installation identity, CIMD/JWKS caches, client-assertion replay claims/cleanup events, and does not delete site/provider content.
- [ ] CI is green on the exact release candidate SHA.
- [ ] release ZIP artifact can be downloaded and its digest recorded.

## Direct ChatGPT App product gates

- [ ] A real ChatGPT Business/Enterprise/Edu workspace with Developer Mode creates the custom App using the public HTTPS Bridge MCP endpoint.
- [ ] No Secure MCP Tunnel, separate proxy daemon, or custom API-key authentication is required for the tested path.
- [ ] ChatGPT follows the OAuth discovery challenge and opens the WordPress authorization flow.
- [ ] The WordPress account can review and approve the consent screen.
- [ ] ChatGPT receives/refreshes OAuth credentials without the operator copying tokens or passwords into ChatGPT settings.
- [ ] ChatGPT tool scan sees exactly the MCP Adapter layered tools.
- [ ] ChatGPT can discover and execute `wp-native-builder/bridge-info`.
- [ ] ChatGPT can create/read a draft and perform a targeted Gutenberg mutation on a disposable test object.
- [ ] ChatGPT correctly receives denial when a required Bridge access group is disabled.
- [ ] If live-content testing is approved, a disposable live-content transition is performed and rolled back/removed.
- [ ] Owner chooses the Bridge license. Do not infer it from the companion Skill or another repository.
- [ ] Owner explicitly authorizes the public v0.1.0 tag/release after reviewing the license and final evidence.

## OAuth/security gate

- [ ] The direct MCP URL, protected-resource metadata URL, authorization-server issuer, authorization endpoint, token endpoint, and redirect behavior are all HTTPS in the real public test deployment.
- [ ] ChatGPT CIMD validation uses the fixed `https://chatgpt.com/oauth/client.json` identifier and fixed `https://chatgpt.com/oauth/jwks.json` signing-key URL; neither URL is caller-controlled and redirects are not followed.
- [ ] Token and revocation endpoints require a valid ChatGPT `private_key_jwt` client assertion and reject unknown keys, invalid signatures, wrong issuer/subject/audience, expired/future assertions, and replayed `jti` values.
- [ ] OAuth authorization responses return `iss` consistently when issuer-identification support is advertised.
- [ ] Authorization redirect is restricted to the verified ChatGPT redirect URI; no caller-controlled open redirect exists.
- [ ] PKCE accepts only `S256` and authorization codes are one-time/short-lived.
- [ ] RFC resource binding is validated at authorization, token exchange, refresh, and MCP Bearer use.
- [ ] Access/refresh/code secrets are high entropy and are not persisted in plaintext.
- [ ] Revoked, expired, unknown, wrong-resource, wrong-client, and replayed artifacts fail closed.
- [ ] The OAuth token/revocation endpoints use no-store responses.
- [ ] The WordPress user used by the App has only the capabilities needed for intended operations.
- [ ] Bridge access groups and WordPress capability checks remain independent from OAuth transport authentication.
- [ ] No OpenAI key, WordPress password/Application Password, OAuth bearer token, refresh token, authorization code, private URL, or other secret is committed, included in release notes, or written to Bridge mutation logs.

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

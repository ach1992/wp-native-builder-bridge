#!/usr/bin/env bash
set -euo pipefail

base_url="${WPNB_HTTP_BASE_URL:-http://127.0.0.1:18080}"
public_origin="${WPNB_PUBLIC_ORIGIN:-http://localhost}"
host_header="${WPNB_HTTP_HOST:-localhost}"
resource="${public_origin}/wp-json/wp-native-builder/v1/mcp"
protected_metadata="${public_origin}/.well-known/oauth-protected-resource"
authorization_metadata="${public_origin}/.well-known/oauth-authorization-server"

command -v curl >/dev/null 2>&1 || { echo 'ERROR: curl is required.' >&2; exit 1; }
command -v jq >/dev/null 2>&1 || { echo 'ERROR: jq is required.' >&2; exit 1; }

protected_file="$(mktemp /tmp/wpnb-protected-resource.XXXXXX)"
authorization_file="$(mktemp /tmp/wpnb-authorization-server.XXXXXX)"
headers_file="$(mktemp /tmp/wpnb-mcp-headers.XXXXXX)"
body_file="$(mktemp /tmp/wpnb-mcp-body.XXXXXX)"
cleanup() {
    rm -f "$protected_file" "$authorization_file" "$headers_file" "$body_file"
}
trap cleanup EXIT

curl -fsS -H "Host: ${host_header}" "${base_url}/.well-known/oauth-protected-resource" > "$protected_file"
jq -e \
    --arg resource "$resource" \
    --arg issuer "$public_origin" \
    '.resource == $resource
     and (.authorization_servers == [$issuer])
     and (.scopes_supported | index("mcp:use") != null)
     and (.scopes_supported | index("offline_access") != null)
     and (.bearer_methods_supported == ["header"])' \
    "$protected_file" >/dev/null

curl -fsS -H "Host: ${host_header}" "${base_url}/.well-known/oauth-authorization-server" > "$authorization_file"
jq -e \
    --arg issuer "$public_origin" \
    --arg authorization_endpoint "${public_origin}/wp-native-builder/oauth/authorize" \
    --arg token_endpoint "${public_origin}/wp-json/wp-native-builder/v1/oauth/token" \
    '.issuer == $issuer
     and .authorization_endpoint == $authorization_endpoint
     and .token_endpoint == $token_endpoint
     and .client_id_metadata_document_supported == true
     and .authorization_response_iss_parameter_supported == true
     and (.grant_types_supported | index("authorization_code") != null)
     and (.grant_types_supported | index("refresh_token") != null)
     and (.code_challenge_methods_supported == ["S256"])
     and (.token_endpoint_auth_methods_supported | index("none") != null)' \
    "$authorization_file" >/dev/null

status="$(curl -sS \
    -H "Host: ${host_header}" \
    -H 'Accept: application/json, text/event-stream' \
    -H 'Content-Type: application/json' \
    -D "$headers_file" \
    -o "$body_file" \
    -w '%{http_code}' \
    -X POST \
    --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"wpnb-direct-http-smoke","version":"1.0.0"}}}' \
    "${base_url}/wp-json/wp-native-builder/v1/mcp")"

if [[ "$status" != "401" ]]; then
    echo "ERROR: unauthenticated direct MCP request returned HTTP ${status}, expected 401." >&2
    cat "$body_file" >&2
    exit 1
fi

tr -d '\r' < "$headers_file" | grep -Fqi "WWW-Authenticate: Bearer resource_metadata=\"${protected_metadata}\""
tr -d '\r' < "$headers_file" | grep -Fqi 'Cache-Control: no-store'

echo 'PASS: direct OAuth well-known discovery and unauthenticated MCP challenge over real HTTP.'

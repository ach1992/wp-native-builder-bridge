#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
compose_file="$root/tests/integration/compose.yml"
compose=(docker compose -f "$compose_file")

command -v jq >/dev/null 2>&1 || {
    echo "ERROR: jq is required for the MCP STDIO integration smoke." >&2
    exit 1
}

wp=("${compose[@]}" run --rm cli)
output_file="$(mktemp /tmp/wpnb-mcp-output.XXXXXX)"
request_file="$(mktemp /tmp/wpnb-mcp-request.XXXXXX)"
post_id=""
media_id=""
original_tagline="$("${wp[@]}" option get blogdescription --allow-root | tail -n 1)"

cleanup() {
    if [[ -n "$post_id" ]]; then
        "${wp[@]}" post delete "$post_id" --force --allow-root >/dev/null 2>&1 || true
    fi
    if [[ -n "$media_id" ]]; then
        "${wp[@]}" post delete "$media_id" --force --allow-root >/dev/null 2>&1 || true
    fi
    "${wp[@]}" option update blogdescription "$original_tagline" --allow-root >/dev/null 2>&1 || true
    "${wp[@]}" eval 'use WP_Native_Builder_Bridge\Support\Settings; $s=new Settings(); update_option(Settings::OPTION_NAME,$s->defaults(),false);' --user=1 --allow-root >/dev/null 2>&1 || true
    rm -f "$output_file" "$request_file"
}
trap cleanup EXIT

run_mcp() {
    local payload="$1"
    printf '%s\n' "$payload" > "$request_file"
    "${compose[@]}" run -T --rm cli mcp-adapter serve --server=mcp-adapter-default-server --user=1 --allow-root < "$request_file" > "$output_file" 2>/dev/null
}

"${wp[@]}" eval 'use WP_Native_Builder_Bridge\Support\Settings; $s=new Settings(); $a=$s->defaults(); $a[Settings::GROUP_SITE_READ]=1; $a[Settings::GROUP_BUILDER_WRITE]=1; $a[Settings::GROUP_SITE_CONFIG]=1; update_option(Settings::OPTION_NAME,$a,false);' --user=1 --allow-root >/dev/null

run_mcp '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
jq -e '.result.tools | map(.name) | sort == ["mcp-adapter-discover-abilities","mcp-adapter-execute-ability","mcp-adapter-get-ability-info"]' "$output_file" >/dev/null

echo "MCP tools/list: PASS"

run_mcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"mcp-adapter-discover-abilities","arguments":{}}}'
grep -Fq 'wp-native-builder/bridge-info' "$output_file"
grep -Fq 'wp-native-builder/content-upsert' "$output_file"
grep -Fq 'wp-native-builder/workspace-resume' "$output_file"
grep -Fq 'wp-native-builder/workspace-document' "$output_file"
grep -Fq 'wp-native-builder/workspace-task' "$output_file"

run_mcp '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"mcp-adapter-get-ability-info","arguments":{"ability_name":"wp-native-builder/bridge-info"}}}'
jq -e '.result.structuredContent.name == "wp-native-builder/bridge-info"' "$output_file" >/dev/null

run_mcp '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-native-builder/bridge-info","parameters":{}}}}'
jq -e '.result.structuredContent.success == true' "$output_file" >/dev/null

run_mcp '{"jsonrpc":"2.0","id":41,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-native-builder/workspace-resume","parameters":{}}}}'
jq -e '.result.structuredContent.success == true and (.result.structuredContent.data.counts | type == "object")' "$output_file" >/dev/null

echo "MCP discover/get-info/read + Workspace resume: PASS"

run_mcp '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-native-builder/content-upsert","parameters":{"action":"create","post_type":"post","title":"WPNB MCP CI draft","content":"<!-- wp:paragraph --><p>Initial MCP CI content</p><!-- /wp:paragraph -->","status":"draft"}}}}'
post_id="$(jq -r '.result.structuredContent.data.id' "$output_file")"
[[ "$post_id" =~ ^[1-9][0-9]*$ ]]

run_mcp "{\"jsonrpc\":\"2.0\",\"id\":6,\"method\":\"tools/call\",\"params\":{\"name\":\"mcp-adapter-execute-ability\",\"arguments\":{\"ability_name\":\"wp-native-builder/blocks-read\",\"parameters\":{\"post_id\":$post_id}}}}"
modified="$(jq -r '.result.structuredContent.data.modified_gmt' "$output_file")"
content_hash="$(jq -r '.result.structuredContent.data.content_hash' "$output_file")"
[[ ${#content_hash} -eq 64 ]]

run_mcp "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"tools/call\",\"params\":{\"name\":\"mcp-adapter-execute-ability\",\"arguments\":{\"ability_name\":\"wp-native-builder/blocks-mutate\",\"parameters\":{\"post_id\":$post_id,\"action\":\"append\",\"block_markup\":\"<!-- wp:paragraph --><p>Appended through MCP CI</p><!-- /wp:paragraph -->\",\"expected_modified_gmt\":\"$modified\",\"expected_content_hash\":\"$content_hash\"}}}}"
jq -e '.result.structuredContent.success == true' "$output_file" >/dev/null
"${wp[@]}" post get "$post_id" --field=content --allow-root | grep -Fq 'Appended through MCP CI'

echo "MCP draft + targeted Gutenberg mutation: PASS"

run_mcp "{\"jsonrpc\":\"2.0\",\"id\":8,\"method\":\"tools/call\",\"params\":{\"name\":\"mcp-adapter-execute-ability\",\"arguments\":{\"ability_name\":\"wp-native-builder/content-read\",\"parameters\":{\"action\":\"get\",\"id\":$post_id}}}}"
modified="$(jq -r '.result.structuredContent.data.items[0].modified_gmt' "$output_file")"
state_hash="$(jq -r '.result.structuredContent.data.items[0].state_hash' "$output_file")"
[[ ${#state_hash} -eq 64 ]]

run_mcp "{\"jsonrpc\":\"2.0\",\"id\":9,\"method\":\"tools/call\",\"params\":{\"name\":\"mcp-adapter-execute-ability\",\"arguments\":{\"ability_name\":\"wp-native-builder/content-upsert\",\"parameters\":{\"action\":\"update\",\"id\":$post_id,\"status\":\"publish\",\"expected_modified_gmt\":\"$modified\",\"expected_state_hash\":\"$state_hash\"}}}}"
jq -e '.result.isError == true' "$output_file" >/dev/null

"${wp[@]}" eval 'use WP_Native_Builder_Bridge\Support\Settings; $a=get_option(Settings::OPTION_NAME,array()); $a[Settings::GROUP_LIVE_CONTENT]=1; update_option(Settings::OPTION_NAME,$a,false);' --user=1 --allow-root >/dev/null
run_mcp "{\"jsonrpc\":\"2.0\",\"id\":10,\"method\":\"tools/call\",\"params\":{\"name\":\"mcp-adapter-execute-ability\",\"arguments\":{\"ability_name\":\"wp-native-builder/content-upsert\",\"parameters\":{\"action\":\"update\",\"id\":$post_id,\"status\":\"publish\",\"expected_modified_gmt\":\"$modified\",\"expected_state_hash\":\"$state_hash\"}}}}"
jq -e '.result.structuredContent.success == true' "$output_file" >/dev/null
[[ "$("${wp[@]}" post get "$post_id" --field=status --allow-root | tail -n 1)" == "publish" ]]

echo "MCP live publish gate: PASS"

run_mcp '{"jsonrpc":"2.0","id":11,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-native-builder/media-upload","parameters":{"filename":"wpnb-mcp-ci.png","content_base64":"iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=","title":"MCP CI media"}}}}'
media_id="$(jq -r '.result.structuredContent.data.id' "$output_file")"
[[ "$media_id" =~ ^[1-9][0-9]*$ ]]
[[ "$("${wp[@]}" post get "$media_id" --field=post_type --allow-root | tail -n 1)" == "attachment" ]]

run_mcp '{"jsonrpc":"2.0","id":12,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-native-builder/site-settings-update","parameters":{"tagline":"Updated through MCP CI"}}}}'
jq -e '.result.structuredContent.success == true' "$output_file" >/dev/null
[[ "$("${wp[@]}" option get blogdescription --allow-root | tail -n 1)" == "Updated through MCP CI" ]]

run_mcp '{"jsonrpc":"2.0","id":13,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"wp-native-builder/extensions-read","parameters":{"kind":"all"}}}}'
jq -e '.result.structuredContent.success == true and (.result.structuredContent.data.plugins | length > 0)' "$output_file" >/dev/null

echo "MCP media + site configuration + advanced administration read: PASS"
echo "PASS: Issue #6 raw MCP STDIO workflow."

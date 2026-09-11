#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
compose_file="$root/tests/integration/compose.yml"
wordpress_tag="${1:-6.9-php8.4-apache}"
safe_tag="$(printf '%s' "$wordpress_tag" | tr -c 'A-Za-z0-9' '-')"
export WORDPRESS_TAG="$wordpress_tag"
export COMPOSE_PROJECT_NAME="wpnb-integration-${safe_tag}-$$"

compose=(docker compose -f "$compose_file")
bash "$root/bin/build-zip.sh"
cleanup() {
    "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

"${compose[@]}" up -d db wordpress

for attempt in $(seq 1 30); do
    if "${compose[@]}" exec -T wordpress test -f /var/www/html/wp-load.php; then
        break
    fi
    if [[ "$attempt" == "30" ]]; then
        echo "ERROR: WordPress files were not initialized." >&2
        exit 1
    fi
    sleep 2
done

"${compose[@]}" cp "$root/build/wp-native-builder-bridge.zip" wordpress:/var/www/html/wp-native-builder-bridge.zip

wp=("${compose[@]}" run --rm cli)
"${wp[@]}" core install \
    --url=https://localhost \
    --title='WP Native Builder Bridge Integration' \
    --admin_user=admin \
    --admin_password='integration-only-password' \
    --admin_email=admin@example.invalid \
    --skip-email \
    --allow-root
mcp_adapter_url="${MCP_ADAPTER_URL:-https://github.com/WordPress/mcp-adapter/releases/download/v0.6.1/mcp-adapter.zip}"
"${wp[@]}" plugin install "$mcp_adapter_url" --activate --allow-root
"${wp[@]}" plugin install /var/www/html/wp-native-builder-bridge.zip --activate --allow-root
"${compose[@]}" exec -T wordpress rm -f /var/www/html/wp-native-builder-bridge.zip

# Integration-only tests/fixtures are copied beside the installed release package after activation.
# They are deliberately absent from the distributable ZIP itself.
tar --mode='u+rwX,go+rX' -C "$root" -cf - tests \
    | "${compose[@]}" exec -T wordpress tar -xf - -C /var/www/html/wp-content/plugins/wp-native-builder-bridge

actual_wp="$("${wp[@]}" core version --allow-root | tail -n 1)"
actual_php="$("${wp[@]}" eval 'echo PHP_VERSION;' --allow-root | tail -n 1)"
echo "Integration baseline: WordPress ${actual_wp}; PHP ${actual_php}; image ${wordpress_tag}"

for test in \
    foundation-smoke.php \
    issue3-content-block-smoke.php \
    issue3-safety-regressions.php \
    issue3-provider-smoke.php \
    issue4-core-admin-smoke.php \
    issue5-hardening-smoke.php \
    issue6-http-transport-smoke.php \
    issue6-direct-oauth-smoke.php \
    issue6-direct-oauth-negative-smoke.php \
    issue6-direct-mcp-tools-smoke.php \
    issue6-i18n-smoke.php \
    issue8-workspace-smoke.php
do
    echo "== ${test} =="
    "${wp[@]}" eval-file "wp-content/plugins/wp-native-builder-bridge/tests/integration/${test}" --user=1 --allow-root
done

# Pretty routing is needed only for the public .well-known OAuth discovery smoke.
# Configure it after the site-settings regression has completed so the HTTP fixture
# cannot change the baseline that Issue #4 intentionally verifies/restores.
"${wp[@]}" rewrite structure '/%postname%/' --hard --allow-root >/dev/null
http_binding="$("${compose[@]}" port wordpress 80 | tail -n 1)"
http_port="${http_binding##*:}"
if [[ ! "$http_port" =~ ^[1-9][0-9]*$ ]]; then
    echo "ERROR: Could not resolve the isolated WordPress HTTP port." >&2
    exit 1
fi
WPNB_HTTP_BASE_URL="http://127.0.0.1:${http_port}" \
WPNB_PUBLIC_ORIGIN='https://localhost' \
bash "$root/bin/run-direct-http-smoke.sh"

"${wp[@]}" eval-file wp-content/plugins/wp-native-builder-bridge/tests/integration/issue4-code-snippets-smoke.php --user=1 --allow-root
"${wp[@]}" eval-file wp-content/plugins/wp-native-builder-bridge/tests/integration/issue4-astra-reuse-smoke.php --user=1 --allow-root

bash "$root/bin/run-mcp-stdio-smoke.sh"

if [[ "${RUN_OPTIONAL_PROVIDERS:-0}" == "1" ]]; then
    echo "== Code Snippets 3.9.6 provider integration =="
    "${wp[@]}" plugin install code-snippets --version=3.9.6 --activate --allow-root
    "${wp[@]}" eval-file wp-content/plugins/wp-native-builder-bridge/tests/integration/issue4-code-snippets-smoke.php --user=1 --allow-root
    "${wp[@]}" plugin deactivate code-snippets --allow-root >/dev/null
    "${wp[@]}" plugin delete code-snippets --allow-root >/dev/null

    echo "== Code Snippets 3.10.2 provider integration =="
    "${wp[@]}" plugin install code-snippets --version=3.10.2 --activate --allow-root
    "${wp[@]}" eval-file wp-content/plugins/wp-native-builder-bridge/tests/integration/issue4-code-snippets-smoke.php --user=1 --allow-root

    echo "== Astra 4.13.11 native Ability integration =="
    "${wp[@]}" theme install astra --version=4.13.11 --activate --allow-root
    "${wp[@]}" eval 'Astra_API_Init::update_admin_settings_option("enable_abilities", true);' --allow-root
    "${wp[@]}" eval-file wp-content/plugins/wp-native-builder-bridge/tests/integration/issue4-astra-reuse-smoke.php --user=1 --allow-root

    echo "== GFAPI transport contract fixture =="
    "${compose[@]}" exec -T wordpress mkdir -p /var/www/html/wp-content/mu-plugins
    "${compose[@]}" exec -T wordpress cp /var/www/html/wp-content/plugins/wp-native-builder-bridge/tests/fixtures/gravity-forms-contract.php /var/www/html/wp-content/mu-plugins/wpnb-gravity-forms-contract.php
    for capability in \
        gravityforms_view_forms \
        gravityforms_create_form \
        gravityforms_edit_forms \
        gravityforms_delete_forms
    do
        "${wp[@]}" user add-cap 1 "$capability" --allow-root >/dev/null
    done
    bash "$root/bin/run-mcp-provider-smoke.sh"
fi

echo "== Workspace deactivation/uninstall preservation =="
"${wp[@]}" eval '
$store = new WP_Native_Builder_Bridge\Workspace\Store();
$doc = $store->create_document(array("key" => "lifecycle-fixture", "title" => "Lifecycle fixture", "content" => "Preserve across deactivate/uninstall."));
$task = $store->create_task(array("title" => "Lifecycle fixture task", "progress" => "in_progress", "review" => "not_required", "delivery" => "not_applicable"));
if (is_wp_error($doc) || is_wp_error($task)) { exit(1); }
update_option("wpnb_integration_workspace_preserve_ids", array("document_id" => (int) $doc["id"], "document_hash" => $doc["state_hash"], "task_id" => (int) $task["id"], "task_hash" => $task["state_hash"]), false);
' --user=1 --allow-root >/dev/null
"${wp[@]}" plugin deactivate wp-native-builder-bridge --allow-root >/dev/null
if ! "${wp[@]}" eval '
$fixture = get_option("wpnb_integration_workspace_preserve_ids", array());
foreach (array("document_id", "task_id") as $key) {
    $id = isset($fixture[$key]) ? (int) $fixture[$key] : 0;
    if ($id < 1 || ! get_post($id) || "" === (string) get_post_meta($id, "_wpnb_workspace_state", true)) { exit(1); }
}
' --allow-root >/dev/null; then
    echo "ERROR: Workspace data did not survive plugin deactivation." >&2
    exit 1
fi
"${wp[@]}" plugin activate wp-native-builder-bridge --allow-root >/dev/null
if ! "${wp[@]}" eval '
$fixture = get_option("wpnb_integration_workspace_preserve_ids", array());
$store = new WP_Native_Builder_Bridge\Workspace\Store();
$doc = $store->get_document((int) ($fixture["document_id"] ?? 0));
$task = $store->get_task((int) ($fixture["task_id"] ?? 0));
if (is_wp_error($doc) || is_wp_error($task) || ! hash_equals((string) $fixture["document_hash"], (string) $doc["state_hash"]) || ! hash_equals((string) $fixture["task_hash"], (string) $task["state_hash"])) { exit(1); }
' --user=1 --allow-root >/dev/null; then
    echo "ERROR: Workspace state identity changed across plugin deactivation/reactivation." >&2
    exit 1
fi
echo "Workspace deactivation preservation: PASS"

echo "== release uninstall cleanup =="
"${wp[@]}" option update wp_native_builder_bridge_settings '{"site_read":1}' --format=json --allow-root >/dev/null
"${wp[@]}" option update wp_native_builder_bridge_recent_actions '[{"ability":"fixture"}]' --format=json --allow-root >/dev/null
"${wp[@]}" option update wp_native_builder_bridge_oauth_instance '0123456789abcdef0123456789abcdef' --allow-root >/dev/null
"${wp[@]}" transient set wpnb_oauth_chatgpt_cimd_ok 1 900 --allow-root >/dev/null
"${wp[@]}" transient set wpnb_oauth_chatgpt_jwks '[{"kid":"fixture"}]' 900 --allow-root >/dev/null
"${wp[@]}" transient set wpnb_oauth_chatgpt_jwks_refresh 1 60 --allow-root >/dev/null
"${wp[@]}" option update wpnb_oauth_assertion_0123456789abcdef0123456789abcdef01234567 "$(date +%s)" --allow-root >/dev/null
"${wp[@]}" cron event schedule wpnb_oauth_cleanup_client_assertion '+10 minutes' --allow-root >/dev/null
"${wp[@]}" plugin uninstall wp-native-builder-bridge --deactivate --allow-root >/dev/null
if "${wp[@]}" option get wp_native_builder_bridge_settings --allow-root >/dev/null 2>&1; then
    echo "ERROR: settings option survived plugin uninstall." >&2
    exit 1
fi
if "${wp[@]}" option get wp_native_builder_bridge_recent_actions --allow-root >/dev/null 2>&1; then
    echo "ERROR: mutation-log option survived plugin uninstall." >&2
    exit 1
fi
if "${wp[@]}" option get wp_native_builder_bridge_oauth_instance --allow-root >/dev/null 2>&1; then
    echo "ERROR: OAuth installation identity survived plugin uninstall." >&2
    exit 1
fi
if ! "${wp[@]}" eval 'if ( false !== get_transient("wpnb_oauth_chatgpt_cimd_ok") ) { exit(1); }' --allow-root >/dev/null; then
    echo "ERROR: OAuth client-metadata cache survived plugin uninstall." >&2
    exit 1
fi
if ! "${wp[@]}" eval 'if ( false !== get_transient("wpnb_oauth_chatgpt_jwks") ) { exit(1); }' --allow-root >/dev/null; then
    echo "ERROR: OAuth JWKS cache survived plugin uninstall." >&2
    exit 1
fi
if ! "${wp[@]}" eval 'if ( false !== get_transient("wpnb_oauth_chatgpt_jwks_refresh") ) { exit(1); }' --allow-root >/dev/null; then
    echo "ERROR: OAuth JWKS refresh cooldown survived plugin uninstall." >&2
    exit 1
fi
if "${wp[@]}" option get wpnb_oauth_assertion_0123456789abcdef0123456789abcdef01234567 --allow-root >/dev/null 2>&1; then
    echo "ERROR: OAuth client-assertion replay claim survived plugin uninstall." >&2
    exit 1
fi
if "${wp[@]}" cron event list --hook=wpnb_oauth_cleanup_client_assertion --field=hook --allow-root 2>/dev/null | grep -Fxq wpnb_oauth_cleanup_client_assertion; then
    echo "ERROR: OAuth client-assertion cleanup event survived plugin uninstall." >&2
    exit 1
fi
if "${wp[@]}" plugin is-installed wp-native-builder-bridge --allow-root >/dev/null 2>&1; then
    echo "ERROR: plugin files survived WP-CLI uninstall." >&2
    exit 1
fi
if ! "${wp[@]}" eval '
$fixture = get_option("wpnb_integration_workspace_preserve_ids", array());
$pairs = array("document_id" => "document_hash", "task_id" => "task_hash");
foreach ($pairs as $id_key => $hash_key) {
    $id = isset($fixture[$id_key]) ? (int) $fixture[$id_key] : 0;
    $json = $id > 0 ? (string) get_post_meta($id, "_wpnb_workspace_state", true) : "";
    if ($id < 1 || ! get_post($id) || "" === $json || ! hash_equals((string) ($fixture[$hash_key] ?? ""), hash("sha256", $json))) { exit(1); }
}
' --allow-root >/dev/null; then
    echo "ERROR: Workspace data did not survive plugin uninstall." >&2
    exit 1
fi
"${wp[@]}" eval '
$fixture = get_option("wpnb_integration_workspace_preserve_ids", array());
foreach (array("document_id", "task_id") as $key) {
    $id = isset($fixture[$key]) ? (int) $fixture[$key] : 0;
    if ($id > 0) { wp_delete_post($id, true); }
}
delete_option("wpnb_integration_workspace_preserve_ids");
' --allow-root >/dev/null
echo "Workspace uninstall preservation: PASS"
echo "Release uninstall cleanup: PASS"

echo "PASS: Docker integration suite for ${wordpress_tag}."

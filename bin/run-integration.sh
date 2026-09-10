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
    --url=http://localhost \
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
    issue6-direct-oauth-smoke.php
do
    echo "== ${test} =="
    "${wp[@]}" eval-file "wp-content/plugins/wp-native-builder-bridge/tests/integration/${test}" --user=1 --allow-root
done

"${wp[@]}" eval-file wp-content/plugins/wp-native-builder-bridge/tests/integration/issue4-code-snippets-smoke.php --user=1 --allow-root
"${wp[@]}" eval-file wp-content/plugins/wp-native-builder-bridge/tests/integration/issue4-astra-reuse-smoke.php --user=1 --allow-root

bash "$root/bin/run-mcp-stdio-smoke.sh"

if [[ "${RUN_OPTIONAL_PROVIDERS:-0}" == "1" ]]; then
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

echo "== release uninstall cleanup =="
"${wp[@]}" option update wp_native_builder_bridge_settings '{"site_read":1}' --format=json --allow-root >/dev/null
"${wp[@]}" option update wp_native_builder_bridge_recent_actions '[{"ability":"fixture"}]' --format=json --allow-root >/dev/null
"${wp[@]}" option update wp_native_builder_bridge_oauth_instance '0123456789abcdef0123456789abcdef' --allow-root >/dev/null
"${wp[@]}" transient set wpnb_oauth_chatgpt_cimd_ok 1 900 --allow-root >/dev/null
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
if "${wp[@]}" transient get wpnb_oauth_chatgpt_cimd_ok --allow-root >/dev/null 2>&1; then
    echo "ERROR: OAuth client-metadata cache survived plugin uninstall." >&2
    exit 1
fi
if "${wp[@]}" plugin is-installed wp-native-builder-bridge --allow-root >/dev/null 2>&1; then
    echo "ERROR: plugin files survived WP-CLI uninstall." >&2
    exit 1
fi
echo "Release uninstall cleanup: PASS"

echo "PASS: Docker integration suite for ${wordpress_tag}."

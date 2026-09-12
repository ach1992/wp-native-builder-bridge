#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

forbidden='(^|[^[:alnum:]_])(shell_exec|exec|system|passthru|proc_open|popen|eval|file_put_contents|fopen|fwrite|unlink|rename|copy|mkdir|rmdir)[[:space:]]*\('

if grep -R -nE "$forbidden" src --include='*.php' --exclude='class-media-abilities.php'; then
    echo "ERROR: forbidden direct execution/filesystem primitive found in production source." >&2
    exit 1
fi

media_write_count="$(grep -cF 'file_put_contents( $tmp_name, $bytes )' src/Abilities/class-media-abilities.php || true)"
media_temp_count="$(grep -cF 'wp_tempnam( $filename )' src/Abilities/class-media-abilities.php || true)"
if [[ "$media_write_count" != "1" || "$media_temp_count" != "1" ]]; then
    echo "ERROR: media upload filesystem exception no longer matches the single bounded WordPress temp-file write." >&2
    exit 1
fi

# Issue #34 needs exact-row compare-and-swap against wp_postmeta. Direct database use remains
# forbidden everywhere except the one fixed-column internal store below. That store accepts no
# SQL text, table name, or column name from Ability/client input.
metadata_store='src/Support/class-post-meta-store.php'
if [[ ! -f "$metadata_store" ]]; then
    echo "ERROR: bounded post-meta store is missing." >&2
    exit 1
fi
unexpected_db_files="$(grep -R -lF '$wpdb' src --include='*.php' | grep -vFx "$metadata_store" || true)"
if [[ -n "$unexpected_db_files" ]]; then
    printf '%s\n' "$unexpected_db_files"
    echo "ERROR: direct database access found outside the bounded post-meta store." >&2
    exit 1
fi
if [[ -f "$metadata_store" ]]; then
    if grep -nE '\$wpdb->(query|get_|prepare|replace)' "$metadata_store"; then
        echo "ERROR: post-meta store must not grow generic/raw-query database primitives." >&2
        exit 1
    fi
    if grep -nE '\$wpdb->[A-Za-z_][A-Za-z0-9_]*' "$metadata_store" | grep -vE '\$wpdb->(postmeta|update|delete|insert)([^A-Za-z0-9_]|$)'; then
        echo "ERROR: post-meta store uses a database member outside its fixed postmeta CAS surface." >&2
        exit 1
    fi
fi

# These names are forbidden in AI-exposed Ability schemas. OAuth protocol responses
# legitimately use access_token, but no OAuth bearer material may become an Ability input.
if grep -R -nE "['\"](server_path|file_path|package_url|shell_command|sql_query|application_password|session_token|access_token|refresh_token|authorization_code|api_secret)['\"][[:space:]]*=>" src/Abilities --include='*.php'; then
    echo "ERROR: forbidden generic path/command/secret schema field found in an exposed Ability." >&2
    exit 1
fi

# Issue #34 deliberately adds generic post_meta access, not a generic WordPress data-store
# backdoor. Keep options/user-meta APIs out of that provider so future edits cannot silently
# expand its authority without an explicit architectural change.
metadata_provider='src/Abilities/class-post-meta-abilities.php'
if [[ -f "$metadata_provider" ]]; then
    if grep -nE '(^|[^[:alnum:]_])(get|add|update|delete)_option[[:space:]]*\(' "$metadata_provider"; then
        echo "ERROR: Advanced Metadata provider must not expose generic WordPress options." >&2
        exit 1
    fi
    if grep -nE '(^|[^[:alnum:]_])(get|add|update|delete)_user_meta[[:space:]]*\(' "$metadata_provider"; then
        echo "ERROR: Advanced Metadata provider must not expose generic user metadata." >&2
        exit 1
    fi
fi

# The consent form posts to the same WordPress origin, then redirects to ChatGPT's
# fixed OAuth callback. Chromium applies form-action across that redirect chain,
# so the callback origin must remain explicitly allowed without broadening the CSP.
oauth_consent_csp_count="$(grep -cF "form-action 'self' https://chatgpt.com" src/Auth/class-oauth-server.php || true)"
if [[ "$oauth_consent_csp_count" != "1" ]]; then
    echo "ERROR: OAuth consent CSP must explicitly allow the fixed ChatGPT callback origin exactly once." >&2
    exit 1
fi

if grep -Fq "form-action 'self';" src/Auth/class-oauth-server.php; then
    echo "ERROR: OAuth consent CSP regressed to same-origin-only form navigation and can block the ChatGPT callback redirect." >&2
    exit 1
fi

echo "PASS: static safety surface audit."

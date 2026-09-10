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

if grep -R -nF '$wpdb' src --include='*.php'; then
    echo "ERROR: direct database access found in production source." >&2
    exit 1
fi

# These names are forbidden in AI-exposed Ability schemas. OAuth protocol responses
# legitimately use access_token, but no OAuth bearer material may become an Ability input.
if grep -R -nE "['\"](server_path|file_path|package_url|shell_command|sql_query|application_password|session_token|access_token|refresh_token|authorization_code|api_secret)['\"][[:space:]]*=>" src/Abilities --include='*.php'; then
    echo "ERROR: forbidden generic path/command/secret schema field found in an exposed Ability." >&2
    exit 1
fi

echo "PASS: static safety surface audit."

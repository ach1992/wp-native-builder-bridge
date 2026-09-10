#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null
done < <(find wp-native-builder-bridge.php src tests -type f -name '*.php' -print0)

echo "PASS: PHP syntax lint."

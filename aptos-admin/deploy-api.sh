#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
API_TARGET=/var/www/usesendra.com/public_html/aptos/api
mkdir -p "$API_TARGET"
cat > "$API_TARGET/index.php" <<EOF
<?php

require '$ROOT/aptos-admin-api/index.php';
EOF
echo "API wired at https://usesendra.com/aptos/api/"
echo "Ensure nginx has location ^~ /aptos/api (see aptos-admin/README.md)"

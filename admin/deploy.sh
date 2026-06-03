#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
npm run build
TARGET=/var/www/usesendra.com/public_html/logistics/admin
rm -rf "$TARGET"
mkdir -p "$TARGET"
cp -r dist/* "$TARGET/"
echo "Deployed to https://usesendra.com/logistics/admin/"

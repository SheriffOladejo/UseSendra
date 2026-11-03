#!/bin/bash
# Credit Stellar pool G+memo deposits every 15s (4 runs/minute). Add to crontab once per minute.
cd /var/www/sendra/app
LOG="${SENDRA_STELLAR_POOL_INDEX_LOG:-/var/log/sendra_stellar_pool_index.log}"
for i in 0 15 30 45; do
  ( sleep "$i"; /usr/bin/php src/scripts/cron_stellar_pool_index.php >> "$LOG" 2>&1 ) &
done
wait

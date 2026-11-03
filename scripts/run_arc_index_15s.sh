#!/bin/bash
# Credit Arc USDC deposits every 15s (4 runs/minute). Add to crontab once per minute.
cd /var/www/sendra/app
LOG="${SENDRA_ARC_INDEX_LOG:-/var/log/sendra_arc_index.log}"
for i in 0 15 30 45; do
  ( sleep "$i"; /usr/bin/php src/scripts/cron_arc_index.php >> "$LOG" 2>&1 ) &
done
wait

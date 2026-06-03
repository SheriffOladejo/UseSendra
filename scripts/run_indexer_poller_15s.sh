#!/bin/bash
# Poll Sendra deposits every 15s (4 runs/minute). Invoked once per minute from cron.
cd /var/www/html/coinectra
LOG=/var/www/html/coinectra/aptos-indexer-webhook.log
for i in 0 15 30 45; do
  ( sleep "$i"; /usr/bin/php aptos_indexer_webhook.php >> "$LOG" 2>&1 ) &
done
wait

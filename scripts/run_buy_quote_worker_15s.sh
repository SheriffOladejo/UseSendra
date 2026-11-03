#!/bin/bash
# Process async buy quote jobs every 15s (4 runs/minute). Add to crontab once per minute.
cd /var/www/sendra/app
LOG="${SENDRA_BUY_QUOTE_LOG:-/var/log/sendra_buy_quote_poll.log}"
for i in 0 15 30 45; do
  ( sleep "$i"; /usr/bin/php src/scripts/cron_buy_quote_poll.php >> "$LOG" 2>&1 ) &
done
wait

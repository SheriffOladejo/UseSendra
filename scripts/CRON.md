## Cron: Daily DB dump + retention

### Why date-stamped dumps (recommended)
- **Safer**: you can roll back to yesterday even if today’s dump is bad/corrupt.
- **Auditable**: you can see growth and changes.
- **Retention is easy**: delete files older than N days.

### Install
1. Ensure `.env` has DB creds:
   - `APTOS_DB_HOST`, `APTOS_DB_NAME`, `APTOS_DB_USER`, `APTOS_DB_PASS`
   - `LOGISTICS_DB_HOST`, `LOGISTICS_DB_NAME`, `LOGISTICS_DB_USER`, `LOGISTICS_DB_PASS`
2. Make script executable:
   - `chmod +x /var/www/html/coinectra/scripts/db_dump_daily.sh`
3. Create backup directory (optional; script will create it):
   - Default: `/var/backups/sendra/mysql`

### Crontab example
Edit crontab:

```bash
crontab -e
```

Run every day at 02:10 (UTC server time):

```cron
10 2 * * * /var/www/html/coinectra/scripts/db_dump_daily.sh >> /var/log/sendra-db-dump.log 2>&1
```

### Optional overrides
Set these in the environment (or in `.env`):
- `SENDRA_DB_BACKUP_DIR=/var/backups/sendra/mysql`
- `SENDRA_DB_BACKUP_RETENTION_DAYS=183`

## Cron: Stellar pool G+memo indexer

Credits classic USDC paid to Sendra’s shared Stellar G (with the user’s memo) into their C wallet.

```cron
* * * * * /var/www/html/coinectra/scripts/run_stellar_pool_index_15s.sh >> /var/log/sendra_stellar_pool_index.log 2>&1
```

Disable with `SENDRA_STELLAR_POOL_INDEX=0`. Apply `migrations/20260916_stellar_pool_deposits.sql` first.`


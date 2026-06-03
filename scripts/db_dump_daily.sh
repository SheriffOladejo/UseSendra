#!/usr/bin/env bash
set -euo pipefail

# Daily MySQL dumps for Sendra (Aptos + Logistics).
# - Creates date-stamped gzip files (safer than overwriting a single dump)
# - Keeps credentials in environment (.env) rather than hardcoding
#
# Usage (cron):
#   /var/www/html/coinectra/scripts/db_dump_daily.sh

ROOT_DIR="/var/www/html/coinectra"
ENV_FILE="${ROOT_DIR}/.env"

if [[ -f "${ENV_FILE}" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "${ENV_FILE}"
  set +a
fi

APTOS_DB_HOST="${APTOS_DB_HOST:-localhost}"
APTOS_DB_NAME="${APTOS_DB_NAME:-sendra_db}"
APTOS_DB_USER="${APTOS_DB_USER:-developer}"
APTOS_DB_PASS="${APTOS_DB_PASS:-}"

LOGISTICS_DB_HOST="${LOGISTICS_DB_HOST:-localhost}"
LOGISTICS_DB_NAME="${LOGISTICS_DB_NAME:-sendra_logistics_db}"
LOGISTICS_DB_USER="${LOGISTICS_DB_USER:-developer}"
LOGISTICS_DB_PASS="${LOGISTICS_DB_PASS:-}"

BACKUP_DIR="${SENDRA_DB_BACKUP_DIR:-/var/backups/sendra/mysql}"
RETENTION_DAYS="${SENDRA_DB_BACKUP_RETENTION_DAYS:-14}"

umask 077
mkdir -p "${BACKUP_DIR}"

ts="$(date -u +'%Y-%m-%d_%H%M%S_UTC')"

dump_one () {
  local host="$1"
  local name="$2"
  local user="$3"
  local pass="$4"
  local out="${BACKUP_DIR}/${name}_${ts}.sql.gz"

  # Use --single-transaction for InnoDB consistency without locking tables.
  # Use MYSQL_PWD to avoid putting password in process args.
  MYSQL_PWD="${pass}" mysqldump \
    --host="${host}" \
    --user="${user}" \
    --single-transaction \
    --routines \
    --events \
    --triggers \
    --set-gtid-purged=OFF \
    "${name}" | gzip -9 > "${out}"

  echo "Wrote ${out}"
}

dump_one "${APTOS_DB_HOST}" "${APTOS_DB_NAME}" "${APTOS_DB_USER}" "${APTOS_DB_PASS}"
dump_one "${LOGISTICS_DB_HOST}" "${LOGISTICS_DB_NAME}" "${LOGISTICS_DB_USER}" "${LOGISTICS_DB_PASS}"

# Prune old dumps (keep last N days)
find "${BACKUP_DIR}" -type f -name '*.sql.gz' -mtime +"${RETENTION_DAYS}" -delete


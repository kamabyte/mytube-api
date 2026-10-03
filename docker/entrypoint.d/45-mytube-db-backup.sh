#!/bin/sh
# Копия базы перед миграциями: миграции
# запускает автоматизация образа (50-laravel-automations) при каждом старте
# контейнера api. Только там, где AUTORUN_ENABLED=true, то есть не в
# планировщике. sqlite3 .backup, а не cp: консистентный снимок живой базы
# в режиме WAL. Хранятся последние MYTUBE_DB_BACKUPS_KEEP копий.
set -eu

[ "${AUTORUN_ENABLED:-false}" = "true" ] || exit 0
db="${DB_DATABASE:-}"
dir="${MYTUBE_DB_BACKUP_DIR:-/backups}"
keep="${MYTUBE_DB_BACKUPS_KEEP:-7}"

if [ -z "${db}" ] || [ ! -f "${db}" ]; then
  echo "mytube-db-backup: базы ${db:-<DB_DATABASE не задан>} нет — копия не нужна"
  exit 0
fi
if [ ! -d "${dir}" ] || [ ! -w "${dir}" ]; then
  echo "mytube-db-backup: ${dir} недоступен для записи — старт без копии базы" >&2
  exit 0
fi

snapshot="${dir}/database-$(date +%Y%m%d-%H%M%S).sqlite"
sqlite3 "${db}" ".backup '${snapshot}'"
echo "mytube-db-backup: ${snapshot}"
ls -1t "${dir}"/database-*.sqlite 2>/dev/null | tail -n +"$((keep + 1))" | xargs -r rm -f

#!/usr/bin/env bash
# Funções comuns dos scripts de operação. Carregado com `source`, nunca executado sozinho.
# As variáveis (DOCKER_COMPOSE_FILE, DB_*, BACKUP_*, DEPLOY_*) vêm do Makefile, que lê o `.env`.

set -euo pipefail

: "${DOCKER_COMPOSE_FILE:?defina DOCKER_COMPOSE_FILE no .env}"
: "${DB_USERNAME:?defina DB_USERNAME no .env}"
: "${DB_DATABASE:?defina DB_DATABASE no .env}"

BACKUP_DIR="${BACKUP_DIR:-backups}"

compose() { docker compose -f "$DOCKER_COMPOSE_FILE" "$@"; }

# Comando no container do PHP (sem TTY: funciona em cron e em pipe).
php_exec() { compose exec -T php-fpm "$@"; }

artisan() { php_exec php artisan "$@"; }

# psql/pg_dump/pg_restore no container do Postgres.
pg_exec() { compose exec -T pgsql "$@"; }

# Dump mais recente de backups/ (vazio se não houver).
# shellcheck disable=SC2012 # nomes gerados pelo backup.sh (AAAAMMDD-HHMMSS.dump): ls -t é seguro
latest_backup() { ls -1t "$BACKUP_DIR"/*.dump 2>/dev/null | head -1 || true; }

fail() { echo "$*" >&2; exit 1; }

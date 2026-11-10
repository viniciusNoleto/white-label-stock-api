#!/usr/bin/env bash
# Restaura um backup por cima do banco atual. Uso: restore.sh backups/AAAAMMDD-HHMMSS.dump
# confirmação (nome do banco) → manutenção → dump do estado atual → pg_restore → no ar.
source "$(dirname "$0")/lib.sh"

file="${1:-}"
[ -n "$file" ] || fail "Informe o arquivo: make restore file=backups/AAAAMMDD-HHMMSS.dump"
[ -f "$file" ] || fail "Arquivo não encontrado: $file"

read -r -p "Restaurar $file por cima de $DB_DATABASE? Digite o nome do banco para confirmar: " answer
[ "$answer" = "$DB_DATABASE" ] || fail "Confirmação não confere: nada foi feito."

artisan down --retry=30

# Rede de segurança: o estado atual vira um dump antes de ser apagado (restaurou o arquivo errado?
# `make restore file=<o dump de segurança>` volta ao ponto de partida). Sem poda: a retenção
# poderia apagar justamente o arquivo que vai ser restaurado.
BACKUP_PRUNE=0 "$(dirname "$0")/backup.sh" \
    || fail "Backup de segurança falhou: nada foi restaurado e a API segue em manutenção (make up-app para voltar)."
safety="$(latest_backup)"
echo "Estado anterior salvo em $safety"

pg_exec pg_restore -U "$DB_USERNAME" -d "$DB_DATABASE" --clean --if-exists --no-owner < "$file" \
    || fail "Restore falhou: a API segue em manutenção. Para voltar ao estado anterior: make restore file=$safety"

artisan up
echo "Restaurado: $file"

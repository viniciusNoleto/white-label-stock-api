#!/usr/bin/env bash
# Dump do banco em backups/AAAAMMDD-HHMMSS.dump (formato custom do pg_dump), retenção e cópia off-site.
#   BACKUP_KEEP     quantos dumps manter (padrão 14); os mais antigos são apagados
#   BACKUP_PRUNE=0  não apaga nenhum (o restore usa: o arquivo a restaurar pode ser o mais antigo)
#   BACKUP_OFFSITE  destino do rsync (outro disco ou usuario@host:/caminho/); vazio → só avisa
source "$(dirname "$0")/lib.sh"

keep="${BACKUP_KEEP:-14}"
prune="${BACKUP_PRUNE:-1}"
offsite="${BACKUP_OFFSITE:-}"

mkdir -p "$BACKUP_DIR"
file="$BACKUP_DIR/$(date +%Y%m%d-%H%M%S).dump"

# Grava num `.partial` e só renomeia no fim: um dump interrompido nunca parece um backup válido.
trap 'rm -f "$file.partial"' EXIT
pg_exec pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" -Fc > "$file.partial"
mv "$file.partial" "$file"
echo "Backup: $file"

if [ "$prune" = "1" ]; then
    # shellcheck disable=SC2012 # nomes gerados aqui (AAAAMMDD-HHMMSS.dump): ls -t é seguro e ordena por data
    ls -1t "$BACKUP_DIR"/*.dump | tail -n +$((keep + 1)) | xargs -r rm -f --
fi

if [ -n "$offsite" ]; then
    rsync -a --delete "$BACKUP_DIR"/ "$offsite" && echo "Copiado para $offsite"
else
    echo "Aviso: BACKUP_OFFSITE vazio, o backup existe só neste disco."
fi

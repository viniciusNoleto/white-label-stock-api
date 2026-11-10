#!/usr/bin/env bash
# Atualiza um servidor já instalado. Uso: deploy.sh [REF]  (sem REF: segue DEPLOY_BRANCH)
#   manutenção → backup → código → build → dependências → migrations → caches → restart do PHP → no ar → tag
# Não mude a ordem: o backup precisa sair com a API parada e o restart é o que troca o bytecode.
source "$(dirname "$0")/lib.sh"

ref="${1:-}"
branch="${DEPLOY_BRANCH:-main}"
remote="${DEPLOY_REMOTE:-origin}"

echo "Deploy com $DOCKER_COMPOSE_FILE"
[ -z "$(git status --porcelain --untracked-files=no)" ] \
    || fail "Há mudanças locais não commitadas: o deploy só roda código versionado."

# 1. Manutenção primeiro (o código antigo responde 503): o backup sai sem escrita concorrente.
artisan down --retry=30
"$(dirname "$0")/backup.sh" || fail "Backup falhou: a API segue em manutenção (make up-app para voltar sem deploy)."

# 2. Código: a REF pedida (rollback) ou a branch atualizada.
git fetch --tags --quiet
if [ -n "$ref" ]; then
    git checkout --quiet --detach "$ref"
else
    git checkout --quiet "$branch" && git pull --ff-only --quiet
fi
echo "Código: $(git describe --tags --always)"

# 3. Containers alterados (o banco segue no ar), dependências, migrations e caches.
compose up -d --build --remove-orphans
php_exec composer install --no-interaction --no-dev --optimize-autoloader
artisan optimize:clear
artisan migrate --force \
    || fail "Migration falhou: a API segue em manutenção. Rollback: make deploy REF=<tag deploy-… anterior> (README › Rollback)."
artisan config:cache
artisan route:cache

# 4. Produção roda com opcache.validate_timestamps=0: sem reiniciar, o php-fpm serviria o bytecode antigo.
compose restart php-fpm scheduler
artisan up

# 5. Toda versão que entrou no ar ganha uma tag (alvo do rollback), enviada ao remoto para não morrer
#    com o servidor. A API já está no ar: se o push falhar, o deploy não é desfeito, só avisa.
tag="deploy-$(date +%Y%m%d-%H%M%S)"
git tag -a "$tag" -m "Deploy $(git rev-parse --short HEAD)"
echo "Tag: $tag"
if git push --quiet "$remote" "$tag"; then
    echo "Tag enviada para $remote."
else
    echo "AVISO: a tag $tag ficou só neste servidor (push falhou). Envie com: git push $remote $tag"
fi

echo "DEPLOY FINALIZADO! Versões já publicadas: git tag -l 'deploy-*'"

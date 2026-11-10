include .env

MAKEFLAGS += --no-print-directory

COMPOSE := docker compose -f ${DOCKER_COMPOSE_FILE}
PHP := $(COMPOSE) exec php-fpm

define-environment:
	@echo "Using ${DOCKER_COMPOSE_FILE} file"

build: ## Executa o build de todos os containers listados no docker-compose
	@make define-environment
	@$(COMPOSE) build --no-cache

build-cache: ## Build aproveitando o cache do Docker
	@$(COMPOSE) build

up: ## Sobe todos os containers
	@$(COMPOSE) up -d

down: ## Encerra todos os containers
	@make define-environment
	@$(COMPOSE) down

logs: ## Acompanha os logs (arquivo local; em produção, com LOG_STACK=stderr, use `make logs-docker`)
	@$(PHP) tail -f storage/logs/laravel.log

logs-docker: ## Acompanha os logs dos containers (stdout/stderr: php-fpm, nginx, scheduler)
	@$(COMPOSE) logs -f --tail=200 php-fpm nginx scheduler

key-generate: ## Gera a APP_KEY
	@$(PHP) php artisan key:generate

refresh:
	@make composer-dump
	@make clear

lib ?=
composer-require: ## Instala uma nova lib utilizando o composer. Ex.: make composer-require lib=vendor/pacote
ifdef lib
	@$(PHP) composer require $(lib)
else
	@echo "Informe o nome da lib a ser instalada"
endif

composer-install: ## Executa o composer install
	@$(PHP) composer install --no-interaction

composer-install-production: ## composer install sem dependências de desenvolvimento
	@$(PHP) composer install --no-interaction --no-dev --optimize-autoloader

composer-update: ## Executa o composer update
	@$(PHP) composer update --no-interaction

composer-dump: ## Executa o composer dump
	@$(PHP) composer dump --no-interaction

delete-vendor: ## Remove a pasta vendor
	@$(PHP) rm -Rf vendor

migrate: ## Executa as migrations
	@$(PHP) php artisan migrate --force

migrate-fresh: ## Recria o banco do zero (apaga todos os dados)
	@$(PHP) php artisan migrate:fresh

clear: ## Limpa todos os caches do Laravel
	@$(PHP) php artisan optimize:clear

db-seed: ## Executa os seeders (cria o admin a partir de SEED_ADMIN_*)
	@$(PHP) php artisan db:seed --force

recreate-testing-database: ## Recria o banco usado pelos testes
	@$(COMPOSE) exec pgsql psql -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "DROP DATABASE IF EXISTS ${DB_DATABASE}_testing;"
	@$(COMPOSE) exec pgsql psql -U "${DB_USERNAME}" -d "${DB_DATABASE}" -c "CREATE DATABASE ${DB_DATABASE}_testing;"

in: ## Lista todos os containers levantados para o usuário escolher um e entrar
	@bash .docker/scripts/in.sh

shell: ## Abre um shell no container do PHP
	@$(COMPOSE) exec -it php-fpm bash

tinker: ## Abre o Tinker (REPL do Laravel) no container do PHP
	@$(COMPOSE) exec -it php-fpm php artisan tinker

TEST ?=
test: ## Roda os testes (Pest: Unit, Feature e Concurrency). Ex.: make test TEST="--filter=units"
	@$(PHP) php artisan config:clear --ansi
	@$(PHP) ./vendor/bin/pest $(TEST)

lint: ## Confere o estilo (Pint, regras em pint.json)
	@$(PHP) ./vendor/bin/pint --test

lint-fix: ## Corrige o estilo (Pint)
	@$(PHP) ./vendor/bin/pint

analyse: ## Análise estática (Larastan, phpstan.neon)
	@$(PHP) ./vendor/bin/phpstan analyse --memory-limit=1G

audit: ## Vulnerabilidades conhecidas nas dependências
	@$(PHP) composer audit --locked

ci: ## Tudo o que o CI roda: lint, análise estática, auditoria e testes
	@make lint
	@make analyse
	@make audit
	@make test

cache: ## Realiza a criação de cache
	@$(PHP) php artisan config:cache
	@$(PHP) php artisan route:cache

restart: ## Restarta todos os containers em execução
	@make down
	@make up
	@make refresh

install: ## Instala a aplicação para desenvolvimento (inclui dependências de dev)
	@echo "${APP_NAME}"
	@make build
	@make up
	@make composer-install
	@make key-generate
	@make migrate
	@make db-seed
	@echo "Instalação concluída! API em http://localhost:${NGINX_PORT}"

install-production: ## Primeiro deploy num servidor (sem dependências de dev; não sobrescreve APP_KEY)
	@echo "${APP_NAME} — primeira instalação"
	@make define-environment
	@make build
	@make up
	@make composer-install-production
	@grep -q '^APP_KEY=base64:' .env || $(PHP) php artisan key:generate --force
	@make migrate
	@make db-seed
	@make cache
	@echo "Instalação concluída."

BACKUP_DIR := backups
# Quantos dumps manter em backups/ (os mais antigos saem depois de cada backup) e para onde copiar
# (destino do rsync: outro disco montado ou usuario@host:/caminho). Vêm do .env; estes são os padrões.
BACKUP_KEEP ?= 14
BACKUP_OFFSITE ?=

backup: ## Dump do banco em backups/AAAAMMDD-HHMMSS.dump, retenção BACKUP_KEEP e cópia para BACKUP_OFFSITE
	@mkdir -p $(BACKUP_DIR)
	@$(COMPOSE) exec -T pgsql pg_dump -U "${DB_USERNAME}" -d "${DB_DATABASE}" -Fc > $(BACKUP_DIR)/$$(date +%Y%m%d-%H%M%S).dump
	@ls -1t $(BACKUP_DIR)/*.dump | head -1 | xargs -I{} echo "Backup: {}"
	@ls -1t $(BACKUP_DIR)/*.dump | tail -n +$$(( $(BACKUP_KEEP) + 1 )) | xargs -r rm -f --
ifneq ($(strip $(BACKUP_OFFSITE)),)
	@rsync -a --delete $(BACKUP_DIR)/ "$(BACKUP_OFFSITE)" && echo "Copiado para $(BACKUP_OFFSITE)"
else
	@echo "Aviso: BACKUP_OFFSITE vazio, o backup existe só neste disco."
endif

file ?=
backup-verify: ## Restaura um dump (o mais recente ou file=…) num banco descartável e conta as linhas: prova que o backup volta
	@f="$(file)"; [ -n "$$f" ] || f=$$(ls -1t $(BACKUP_DIR)/*.dump 2>/dev/null | head -1); \
	[ -f "$$f" ] || { echo "Nenhum backup encontrado."; exit 1; }; \
	check="${DB_DATABASE}_restore_check"; \
	$(COMPOSE) exec -T pgsql psql -U "${DB_USERNAME}" -d "${DB_DATABASE}" -q -c "DROP DATABASE IF EXISTS $$check;" -c "CREATE DATABASE $$check;" \
	&& $(COMPOSE) exec -T pgsql pg_restore -U "${DB_USERNAME}" -d "$$check" --no-owner --exit-on-error < "$$f" \
	&& $(COMPOSE) exec -T pgsql psql -U "${DB_USERNAME}" -d "$$check" -tA \
		-c "SELECT 'migrations: ' || count(*) FROM migrations" \
		-c "SELECT 'products: ' || count(*) FROM products" \
		-c "SELECT 'items: ' || count(*) FROM items" \
		-c "SELECT 'stock_movements: ' || count(*) FROM stock_movements"; \
	status=$$?; \
	$(COMPOSE) exec -T pgsql psql -U "${DB_USERNAME}" -d "${DB_DATABASE}" -q -c "DROP DATABASE IF EXISTS $$check;"; \
	if [ $$status -eq 0 ]; then echo "Backup OK: $$f"; else echo "Backup NÃO restaurou: $$f"; exit 1; fi

restore: ## Restaura um backup (apaga o banco atual!). Ex.: make restore file=backups/20261110-120000.dump
ifndef file
	@echo "Informe o arquivo: make restore file=backups/AAAAMMDD-HHMMSS.dump" && exit 1
else
	@read -p "Restaurar $(file) por cima de ${DB_DATABASE}? Digite o nome do banco para confirmar: " answer && [ "$$answer" = "${DB_DATABASE}" ]
	@$(PHP) php artisan down --retry=30
	@$(COMPOSE) exec -T pgsql pg_restore -U "${DB_USERNAME}" -d "${DB_DATABASE}" --clean --if-exists --no-owner < $(file)
	@$(PHP) php artisan up
	@echo "Restaurado: $(file)"
endif

# Branch que o deploy acompanha; REF=<tag|commit> faz o deploy de uma versão específica (rollback).
DEPLOY_BRANCH ?= main
REF ?=

deploy: ## Atualiza um servidor já instalado: manutenção → backup → código → build → dependências → migrations → caches → reload do PHP → no ar → tag. Ex.: make deploy | make deploy REF=deploy-20261110-120000
	@echo "${APP_NAME} DEPLOY!"
	@make define-environment
	@test -z "$$(git status --porcelain --untracked-files=no)" || { echo "Há mudanças locais não commitadas: o deploy só roda código versionado."; exit 1; }
	@# 1. Manutenção primeiro (o código antigo responde 503): o backup sai sem escrita concorrente.
	@$(PHP) php artisan down --retry=30
	@make backup || { echo "Backup falhou: a API segue em manutenção (make up-app para voltar sem deploy)."; exit 1; }
	@# 2. Código: a branch atualizada ou a REF pedida.
	@git fetch --tags --quiet
	@if [ -n "$(REF)" ]; then git checkout --quiet --detach "$(REF)"; else git checkout --quiet $(DEPLOY_BRANCH) && git pull --ff-only --quiet; fi
	@echo "Código: $$(git describe --tags --always)"
	@$(COMPOSE) up -d --build --remove-orphans
	@make composer-install-production
	@make clear
	@make migrate || { echo "Migration falhou: a API segue em manutenção. Rollback: make deploy REF=<tag deploy-… anterior> (README › Rollback)."; exit 1; }
	@make cache
	@# 3. Produção roda com opcache.validate_timestamps=0: sem reiniciar, o php-fpm seguiria servindo o bytecode antigo.
	@$(COMPOSE) restart php-fpm scheduler
	@$(PHP) php artisan up
	@# 4. Toda versão que entrou no ar ganha uma tag: é o alvo do rollback.
	@git tag -a "deploy-$$(date +%Y%m%d-%H%M%S)" -m "Deploy $$(git rev-parse --short HEAD)"
	@echo "DEPLOY FINALIZADO! Versões já publicadas: git tag -l 'deploy-*'"

up-app: ## Tira a API do modo manutenção (sem deploy)
	@$(PHP) php artisan up

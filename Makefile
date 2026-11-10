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
	@bash scripts/in.sh

shell: ## Abre um shell no container do PHP
	@$(COMPOSE) exec -it php-fpm bash

tinker: ## Abre o Tinker (REPL do Laravel) no container do PHP
	@$(COMPOSE) exec -it php-fpm php artisan tinker

TEST ?=
test: ## Roda os testes (Pest: Unit, Feature e Concurrency). Ex.: make test TEST="--filter=units"
	@$(PHP) php artisan config:clear --ansi
	@$(PHP) ./vendor/bin/pest $(TEST)

test-coverage: ## Testes com cobertura de linhas (mínimo 95%, como no CI). Precisa da imagem local com pcov: make build-cache
	@$(PHP) composer test-coverage

lint: ## Confere o estilo (Pint, regras em pint.json)
	@$(PHP) ./vendor/bin/pint --test

lint-scripts: ## Confere os scripts de operação (shellcheck, numa imagem fixa)
	@docker run --rm -v "$(CURDIR)/scripts":/s -w /s koalaman/shellcheck:v0.10.0 -x -e SC1091 $$(cd scripts && ls *.sh)

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

# Operação (backup, restore, deploy): a lógica fica em scripts/*.sh; aqui só a interface.
# Os scripts recebem a configuração do .env por estas variáveis de ambiente.
# BACKUP_KEEP: quantos dumps manter. BACKUP_OFFSITE: destino do rsync (outro disco ou usuario@host:/caminho/).
# DEPLOY_BRANCH: branch que o deploy acompanha. DEPLOY_REMOTE: remoto que recebe as tags deploy-*.
BACKUP_KEEP ?= 14
BACKUP_OFFSITE ?=
DEPLOY_BRANCH ?= main
DEPLOY_REMOTE ?= origin
export DOCKER_COMPOSE_FILE DB_USERNAME DB_DATABASE BACKUP_KEEP BACKUP_OFFSITE DEPLOY_BRANCH DEPLOY_REMOTE

file ?=
REF ?=

backup: ## Dump do banco em backups/AAAAMMDD-HHMMSS.dump, retenção BACKUP_KEEP e cópia para BACKUP_OFFSITE
	@bash scripts/backup.sh

backup-verify: ## Restaura um dump (o mais recente ou file=…) num banco descartável e conta as linhas: prova que o backup volta
	@bash scripts/backup-verify.sh $(file)

restore: ## Restaura um backup (pede confirmação e faz um dump do estado atual antes). Ex.: make restore file=backups/20261110-120000.dump
	@bash scripts/restore.sh $(file)

deploy: ## Atualiza um servidor já instalado (manutenção → backup → código → migrations → restart → tag). Ex.: make deploy | make deploy REF=deploy-20261110-120000
	@bash scripts/deploy.sh $(REF)

up-app: ## Tira a API do modo manutenção (sem deploy)
	@$(PHP) php artisan up

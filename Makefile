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

logs: ## Acompanha os logs do Laravel
	@$(PHP) tail -f storage/logs/laravel.log

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
test: ## Roda os testes (Pest). Ex.: make test TEST="--filter=units"
	@$(PHP) php artisan config:clear --ansi
	@$(PHP) ./vendor/bin/pest $(TEST)

certbot-run: ## Solicita a primeira instalação do certificado digital utilizando certbot
	@$(COMPOSE) exec nginx certbot run --nginx --agree-tos --no-eff-email -m ${CERTBOT_EMAIL_ADDRESS} -d ${CERTBOT_DOMAIN}

certbot-renew: ## Solicita a renovação do certificado digital utilizando certbot
	@$(COMPOSE) exec nginx certbot renew

cache: ## Realiza a criação de cache
	@$(PHP) php artisan config:cache
	@$(PHP) php artisan route:cache

restart: ## Restarta todos os containers em execução
	@make down
	@make up
	@make refresh

install: ## Instala a aplicação executando todos os passos necessários
	@echo "${APP_NAME}"
	@make build
	@make up
	@make composer-install
	@make key-generate
	@make migrate
	@make db-seed
	@echo "Instalação concluída! API em http://localhost:${NGINX_PORT}"

deploy: ## Configuração de deploy
	@echo "${APP_NAME} DEPLOY!"
	@make define-environment
	@make down
	@make up
	@make composer-install-production
	@make clear
	@make migrate
	@make cache
	@echo "DEPLOY FINALIZADO!"

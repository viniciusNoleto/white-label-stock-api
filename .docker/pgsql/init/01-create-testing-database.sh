#!/bin/sh
# Cria o banco dos testes (Pest) ao lado do principal na primeira subida do volume.
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    CREATE DATABASE "${POSTGRES_DB}_testing";
SQL

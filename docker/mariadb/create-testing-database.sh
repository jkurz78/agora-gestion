#!/usr/bin/env bash
#
# Crée la base `testing` à l'initialisation du conteneur.
#
# Remplace vendor/laravel/sail/database/mysql/create-testing-database.sh, qui
# appelle le binaire `mysql`. MariaDB 11.4 ne le fournit plus : les alias
# mysql, mysqldump et mysqladmin ont tous disparu au profit des mariadb-*.
# Sans ce remplacement, le conteneur s'arrête en « mysql: command not found ».

mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS testing;
EOSQL

if [ -n "$MARIADB_USER" ]; then
mariadb --user=root --password="$MARIADB_ROOT_PASSWORD" <<-EOSQL
    GRANT ALL PRIVILEGES ON \`testing%\`.* TO '$MARIADB_USER'@'%';
EOSQL
fi

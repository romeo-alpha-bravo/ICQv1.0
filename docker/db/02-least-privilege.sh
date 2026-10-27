#!/bin/sh
# Runs once, after schema.sql, while the database is being initialized.
# The image gives the app user ALL privileges; the app only needs to read and write rows.
set -e

mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" <<SQL
REVOKE ALL PRIVILEGES ON \`$MARIADB_DATABASE\`.* FROM '$MARIADB_USER'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`$MARIADB_DATABASE\`.* TO '$MARIADB_USER'@'%';
FLUSH PRIVILEGES;
SQL

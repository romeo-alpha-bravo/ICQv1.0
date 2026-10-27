# ICQ1.0 – shortcuts for the Docker stack. Run `make` to see them.
.PHONY: help env up down restart logs ps admin shell db migrate reset

help:            ## Show this list
	@grep -E '^[a-z]+:.*##' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  make %-9s %s\n", $$1, $$2}'

env:             ## Create .env with random passwords and keys (only if missing)
	@test -f .env && echo ".env already exists, nothing changed." || { \
	  sed -e "s|^DB_PASS=.*|DB_PASS=$$(openssl rand -hex 16)|" \
	      -e "s|^DB_ROOT_PASS=.*|DB_ROOT_PASS=$$(openssl rand -hex 16)|" \
	      -e "s|^UIN_ENC_KEY=.*|UIN_ENC_KEY=$$(openssl rand -base64 32)|" \
	      -e "s|^UIN_HMAC_KEY=.*|UIN_HMAC_KEY=$$(openssl rand -base64 32)|" \
	      .env.example > .env && chmod 600 .env && echo "Created .env – back it up, it holds the encryption keys."; }

up:              ## Build and start everything in the background
	docker compose up -d --build

down:            ## Stop everything (data is kept)
	docker compose down

restart:         ## Restart the containers
	docker compose restart

logs:            ## Follow the logs of all containers
	docker compose logs -f

ps:              ## Show container status and health
	docker compose ps

admin:           ## Activate the default admin account (asks for e-mail, phone, password)
	docker compose exec php php install_admin.php

shell:           ## Open a shell in the PHP container
	docker compose exec php sh

db:              ## Open the MariaDB console as the app user
	docker compose exec db sh -c 'mariadb -u"$$MARIADB_USER" -p"$$MARIADB_PASSWORD" uin_mail'

migrate:         ## Apply the SQL migrations to an existing database (as root)
	for f in migrate_stage7.sql migrate_password_reset.sql; do \
	  docker compose exec -T db sh -c 'mariadb -uroot -p"$$MARIADB_ROOT_PASSWORD" uin_mail' < $$f; done

reset:           ## DELETE all data (database and photos) and start from scratch
	@read -p "This deletes all users, messages and photos. Type yes: " a && [ "$$a" = yes ]
	docker compose down -v
	docker compose up -d --build

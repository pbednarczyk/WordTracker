COMPOSE=docker compose

.PHONY: install start stop restart logs test shell php-test nlp-test test-db migrate schema-validate

install:
	$(COMPOSE) build
	$(COMPOSE) run --rm app composer install
	$(COMPOSE) run --rm nlp python -m pip install -r requirements.txt

start:
	$(COMPOSE) up -d

stop:
	$(COMPOSE) down

restart: stop start

logs:
	$(COMPOSE) logs -f

migrate:
	$(COMPOSE) exec app php bin/console doctrine:migrations:migrate --no-interaction

schema-validate:
	$(COMPOSE) exec app php bin/console doctrine:schema:validate

test: php-test nlp-test

test-db:
	$(COMPOSE) run --rm -e APP_ENV=test app php bin/console doctrine:database:create --if-not-exists --env=test
	$(COMPOSE) run --rm -e APP_ENV=test app php bin/console doctrine:migrations:migrate --no-interaction --env=test

php-test: test-db
	$(COMPOSE) run --rm -e APP_ENV=test app ./vendor/bin/phpunit

nlp-test:
	$(COMPOSE) run --rm nlp pytest

shell:
	$(COMPOSE) exec app sh

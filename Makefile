COMPOSE=docker compose

.PHONY: install start deploy stop restart logs test shell php-test nlp-test test-db migrate schema-validate

install:
	$(COMPOSE) build
	$(COMPOSE) run --rm app-dev composer install
	$(COMPOSE) run --rm nlp python -m pip install -r requirements.txt

start:
	$(COMPOSE) up -d

deploy:
	git pull
	$(COMPOSE) up -d
	$(COMPOSE) exec -T app-prod php bin/console doctrine:migrations:migrate --no-interaction --env=prod
	$(COMPOSE) exec -T app-prod php bin/console cache:clear --env=prod

stop:
	$(COMPOSE) down

restart: stop start

logs:
	$(COMPOSE) logs -f

migrate:
	$(COMPOSE) exec app-dev php bin/console doctrine:migrations:migrate --no-interaction

schema-validate:
	$(COMPOSE) exec app-dev php bin/console doctrine:schema:validate

test: php-test nlp-test

test-db:
	$(COMPOSE) run --rm -e APP_ENV=test app-dev php bin/console doctrine:database:create --if-not-exists --env=test
	$(COMPOSE) run --rm -e APP_ENV=test app-dev php bin/console doctrine:migrations:migrate --no-interaction --env=test

php-test: test-db
	$(COMPOSE) run --rm -e APP_ENV=test app-dev ./vendor/bin/phpunit

nlp-test:
	$(COMPOSE) run --rm nlp pytest

shell:
	$(COMPOSE) exec app-dev sh

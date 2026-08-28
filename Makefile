COMPOSE=docker compose

.PHONY: install start stop restart logs test shell php-test nlp-test

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

test: php-test nlp-test

php-test:
	$(COMPOSE) run --rm -e APP_ENV=test app ./vendor/bin/phpunit

nlp-test:
	$(COMPOSE) run --rm nlp pytest

shell:
	$(COMPOSE) exec app sh

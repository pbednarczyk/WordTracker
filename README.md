# WordTracker

Local bootstrap for WordTracker: a Symfony application backed by PostgreSQL and a separate FastAPI NLP service.

## Requirements

- Docker
- Docker Compose
- `make`

On Windows 11, use Docker Desktop with the WSL2 backend. If `make` is not available, run the equivalent `docker compose ...` commands shown in the `Makefile`.

## First Run

```bash
git clone <repository-url>
cd WordTracker
make install
make start
```

Open the application at:

```text
http://localhost:8080
```

## URLs

- Symfony application: `http://localhost:8080`
- NLP healthcheck from inside Docker: `http://nlp:8000/health`
- NLP healthcheck from host: `http://localhost:8000/health`

## Useful Commands

```bash
make install   # build images and install PHP/Python dependencies
make start     # start the local environment
make stop      # stop containers
make restart   # restart containers
make logs      # follow container logs
make migrate   # run Doctrine migrations
make schema-validate # validate Doctrine mapping and database schema
make test      # run PHP and Python tests
```

Direct alternatives without `make`:

```bash
docker compose build
docker compose up -d
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console doctrine:schema:validate
docker compose run --rm -e APP_ENV=test app ./vendor/bin/phpunit
docker compose run --rm nlp pytest
docker compose down
```

## Architecture

```text
Browser
  |
nginx
  |
Symfony
  |-- PostgreSQL
  `-- FastAPI NLP
```

The Symfony container reaches PostgreSQL as `db:5432` and the NLP service as `http://nlp:8000/health`.

## Data Model

Current scope is the MVP 1 persistence model only. There is no NLP analysis, upload flow, coverage calculation, SRS, or publication UI yet.

```text
Publication
   |
   |-- VocabularyOccurrence -- VocabularyItem
   |
   `-- PublicationVocabulary - VocabularyItem
```

- `Publication` stores a source material such as a book, article, comic, document, web page, or other text-bearing item.
- `VocabularyItem` stores the global vocabulary item. It is the source of truth and is not deleted when a publication is deleted.
- `VocabularyOccurrence` stores one concrete occurrence of a word in one publication.
- `PublicationVocabulary` stores the aggregate relation between one publication and one vocabulary item, including the occurrence count.

`VocabularyItem` identity is unique by:

```text
language + lemma + partOfSpeech
```

`partOfSpeech` is a non-null string and defaults to `UNKNOWN`, so the database can enforce uniqueness without PostgreSQL `NULL` edge cases.

Run migrations:

```bash
make migrate
```

Validate mapping and schema:

```bash
make schema-validate
```

## Troubleshooting

Check running services:

```bash
docker compose ps
```

View logs:

```bash
make logs
```

Check the database from Symfony:

```bash
docker compose exec app php bin/console doctrine:query:sql "SELECT 1"
```

Check the NLP service:

```bash
curl http://localhost:8000/health
```

Rebuild after dependency changes:

```bash
docker compose build --no-cache
make install
```

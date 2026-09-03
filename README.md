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

## Using WordTracker

1. Open:

```text
http://localhost:8080
```

2. Choose `Add publication`.
3. Enter title, optional author, type, language, and paste the text.
4. Click `Create`.
5. On the publication details page, click `Analyze publication`.
6. Review the vocabulary table with lemma, POS, occurrences, and status.
7. Mark individual vocabulary items as `KNOWN` or `UNKNOWN`, or select multiple
   visible rows and use the bulk actions.
8. Use the `All`, `Unknown`, and `Known` filters plus the lemma search box to
   narrow the vocabulary table.

Re-analyzing a publication uses the same details page action and replaces that
publication's previous analysis rows without duplicating vocabulary entries or
resetting global vocabulary statuses.

`VocabularyItem.status` is global. When a word is marked `KNOWN` in one
publication, every other publication using the same `language + lemma +
partOfSpeech` vocabulary item shows it as `KNOWN` immediately.

Publication details show two coverage metrics:

- Vocabulary Coverage: percent of unique publication vocabulary items marked
  `KNOWN`.
- Text Coverage: percent of actual vocabulary occurrences belonging to `KNOWN`
  vocabulary.

These metrics intentionally measure different things. A frequent word can move
Text Coverage much more than Vocabulary Coverage because Text Coverage is
weighted by occurrence counts. Coverage is shown as `N/A` when a publication has
no vocabulary rows.

## URLs

- Symfony application: `http://localhost:8080`
- NLP healthcheck from inside Docker: `http://nlp:8000/health`
- NLP healthcheck from host: `http://localhost:8000/health`
- NLP Swagger UI: `http://localhost:8000/docs`

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

## Publication Analysis Pipeline

The first complete analysis flow stores vocabulary data from a publication raw text:

```text
Publication.rawText
  |
Symfony AnalyzePublicationHandler
  |
POST http://nlp:8000/analyze
  |
tokens + lemmas + POS + sentence offsets
  |
VocabularyItem
VocabularyOccurrence
PublicationVocabulary
  |
PostgreSQL
```

`VocabularyItem` is global and unique by `language + lemma + partOfSpeech`. `VocabularyOccurrence` stores each vocabulary occurrence for one publication after named-entity filtering. `PublicationVocabulary` stores per-publication aggregate counts.

Tokens whose `entity_type` is a named-entity category such as `PERSON`, `GPE`, `ORG`, `LOC`, or `NORP` are ignored by the vocabulary pipeline. `PROPN` by itself does not cause a token to be ignored. Re-analyzing a publication deletes only that publication's previous `VocabularyOccurrence` and `PublicationVocabulary` rows, then writes the new analysis in one database transaction. Existing `VocabularyItem` rows and their statuses are kept.

Analyze an existing publication:

```bash
docker compose exec app php bin/console wordtracker:publication:analyze <publication-id>
```

Create and analyze the development fixture from `fixtures/sample.txt`:

```bash
docker compose exec app php bin/console wordtracker:fixture:analyze
```

Useful database checks:

```bash
docker compose exec app php bin/console doctrine:query:sql "SELECT id, title, analyzed_at FROM publication ORDER BY id DESC LIMIT 5"
docker compose exec app php bin/console doctrine:query:sql "SELECT lemma, part_of_speech, status FROM vocabulary_item ORDER BY lemma LIMIT 50"
docker compose exec app php bin/console doctrine:query:sql "SELECT vi.lemma, vi.part_of_speech, pv.occurrences FROM publication_vocabulary pv JOIN vocabulary_item vi ON vi.id = pv.vocabulary_item_id ORDER BY pv.occurrences DESC LIMIT 20"
```

## NLP Service

The NLP service is a separate FastAPI application in `nlp/`. It uses spaCy with the lightweight English model:

```text
en_core_web_sm
```

The Docker image installs both spaCy and the model automatically during build.

### Healthcheck

```http
GET /health
```

Response:

```json
{
  "status": "ok",
  "service": "wordtracker-nlp"
}
```

### Analyze Text

```http
POST /analyze
Content-Type: application/json
```

Request:

```json
{
  "text": "The children were running down the corridor."
}
```

Response shape:

```json
{
  "language": "en",
  "token_count": 8,
  "word_count": 7,
  "unique_lemma_count": 7,
  "tokens": [
    {
      "text": "children",
      "lemma": "child",
      "pos": "NOUN",
      "entity_type": null,
      "sentence": "The children were running down the corridor.",
      "position": 4,
      "is_proper_noun": false
    }
  ]
}
```

Only alphabetic word tokens are returned in `tokens`; punctuation, whitespace, symbols, URLs, and numbers are omitted. Stopwords are not filtered. `position` is the character offset of the token in the original input. `entity_type` is the spaCy named-entity label for the token or `null` when the token is not part of a named entity.

Input text must not be blank. Payloads over `1,000,000` UTF-8 bytes return `413 Payload Too Large`.

Swagger UI is available at:

```text
http://localhost:8000/docs
```

## Bruno API Collection

The API contract is also maintained as a Bruno collection in `bruno/`. Update it in the same change whenever a public HTTP endpoint is added, removed, renamed, or its request/response contract changes.

Open it locally:

1. Start the stack:

```bash
docker compose up -d
```

2. Open Bruno.
3. Choose `Open Collection`.
4. Select the repository `bruno/` directory.
5. Select the `Local` environment.

The `Local` environment defines:

```text
nlpBaseUrl = http://localhost:8000
appBaseUrl = http://localhost:8080
```

Current requests:

- `NLP / Health`: `GET {{nlpBaseUrl}}/health`
- `NLP / Analyze`: `POST {{nlpBaseUrl}}/analyze`

The requests include assertions for the current response contract.

## Data Model

Current scope includes the MVP 1 persistence model, backend NLP publication analysis, global vocabulary status management, coverage calculation, and the Twig UI for creating, analyzing, filtering, and updating publication vocabulary. There is no upload flow or SRS yet.

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

`PublicationVocabulary` does not store status. Status is read from
`VocabularyItem`, so status changes are cross-publication by design. Coverage is
computed from current `VocabularyItem.status` and `PublicationVocabulary`
occurrence counts; it is not cached on `Publication`.

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

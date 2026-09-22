# Phase 3A: single-item asynchronous enrichment

This is an opt-in real application workflow, not the Phase 2 diagnostic endpoint.
Symfony/PostgreSQL is the only durable workflow store. NLP is stateless: it builds
prompts, parses and validates results, and decides repair/fallback. The worker
receives the existing generic version-1 envelope only. No worker changes are needed.

## Configuration

Merge these into the root **untracked** `.env`, preserving your broker settings:

```dotenv
ASYNC_ENRICHMENT_ENABLED=true
ASYNC_ENRICHMENT_MODEL=qwen3:14b
ASYNC_ENRICHMENT_PROVIDER=ollama
RABBITMQ_ENABLED=true
ENRICHMENT_APP_BASE_URL=http://nginx-dev
# Set ENRICHMENT_INTERNAL_TOKEN to a strong local secret, shared by Symfony/NLP.
```

`ASYNC_ENRICHMENT_ENABLED` affects only the existing single-item form. Defaults to
false. Bulk remains synchronous, even when enabled. `ASYNC_ENRICHMENT_MODEL` is
independent of `OLLAMA_MODEL` and `OLLAMA_BASE_URL`. The provider value identifies
the inference backend, not RabbitMQ; new records default to `ollama`, because the
external worker currently uses Ollama. Historical metadata is not changed.

`ENRICHMENT_INTERNAL_TOKEN` must be supplied locally, never committed. The new
internal APIs fail closed when unset and require `X-Enrichment-Token`. The old
`LLM_JOBS_API_TOKEN` remains specific to diagnostics. Keep internal endpoints on a
trusted network/protected channel; a token is authentication, not encryption.

The result bridge targets the same Symfony application/database as submission.
For the prod stack set `ENRICHMENT_APP_BASE_URL=http://nginx-prod` and ensure that
service is running. `http://nginx-dev` is the default for the existing local stack.
The bridge never connects to PostgreSQL directly or stores workflow files.

## Schema and ownership

Migration `Version20260923090000` adds:

- `publication_vocabulary.enrichment_revision`: monotonic per-context request
  revision, also incremented by synchronous requests to prevent cross-path races.
- `publication_vocabulary_enrichment_job`: integer application ID, context FK,
  request revision, status (`QUEUED`, `PROCESSING`, `COMPLETED`, `FAILED`), stage
  (`GENERATE`, `REPAIR`), unique nullable active LLM UUID, selected model/provider,
  prompt version, request snapshot JSON, nullable candidate JSON, safe failure
  code, created/updated timestamps. Context deletion cascades to its jobs.

The snapshot contains the six semantic request inputs: lemma, POS, original form,
context sentence, source and target languages. Re-analysis can change occurrences;
resumption must use the submitted context, not select a different one. Candidate
JSON is retained for REPAIR because inference output cannot be reconstructed; it
is cleared on successful completion. Generated prompts/schemas and raw result
bodies are not another durable inbox. No second enrichment storage model exists.
Model/prompt metadata and active UUID are nullable only during initial preparation
(or its failure). Prompt-version mismatch after deployment fails explicitly rather
than interpreting an old job with silently changed semantics.

## Submission and result graphs

```text
Generate / Regenerate form
 → POST /publication-vocabulary/{id}/enrichment (existing CSRF protection)
 → PublicationController::enrichPublicationVocabulary
 → AsyncEnrichmentWorkflow::submit
 → EnrichmentRequestFactory (shared eligibility/representative context)
 → lock context, increment revision, persist QUEUED application job, COMMIT
 → HttpAsyncEnrichmentGateway → NLP POST /internal/enrichment/prepare
 → existing prompt + schema + temperature 0.2 + configured async model
 → LlmJob.create (new UUID); return job + provider + prompt version
 → persist active UUID/metadata, COMMIT
 → NLP POST /internal/enrichment/publish → RabbitMqLlmJobPublisher
 → llm.jobs → external dexter-worker → worker-owned Ollama/GPU
 → confirmed publication → PROCESSING (means published, not proof worker started)
 → HTTP 303; browser does not wait for inference

llm.results
 → python -m wordtracker_nlp.enrichment_results
 → Phase 2 parse_result (envelope + header/body correlation)
 → authenticated Symfony POST /internal/enrichment/results
 → AsyncEnrichmentWorkflow::accept (lock context, refresh job, check UUID/revision/deletion)
 → NLP POST /internal/enrichment/evaluate
 → Enrichment.from_model_response + validate_enrichment
 → EnrichmentPersister (shared with synchronous handler)
 → PublicationVocabularyEnrichment + COMPLETED in one transaction, COMMIT
 → HTTP 200 {disposition: applied}
 → result ACK; refresh page to see enrichment
```

Both the context lock and a fresh job read matter: concurrent duplicate requests
must see the committed state, rather than stale Doctrine identity-map objects.

### Exactly one semantic repair

```text
GENERATE raw result → parse → target missing from simple_example
 → existing build_simple_example_repair_prompt + repair schema + temperature 0.2
 → new generic LlmJob UUID (same selected model)
 → Symfony persists candidate + REPAIR + active UUID + QUEUED, COMMIT
 → existing publisher publishes repair
 → PROCESSING or explicit FAILED publication disposition, COMMIT
 → original GENERATE result ACK

REPAIR result → RepairedSimpleExample.from_model_response
 → merge only simple_example into persisted candidate → validate_enrichment
 → if semantically invalid: original context sentence → validate_enrichment again
 → persist completed enrichment, or FAILED if fallback invalid
 → COMMIT → ACK
```

Malformed generated/repair JSON fails. Worker/backend failure fails. These do not
trigger semantic repair or context fallback. A repair is not a transport retry.

## Internal HTTP contracts and Bruno

All new routes require `X-Enrichment-Token`. NLP routes return 401 for an invalid
token, 503 when no token is configured, and 422 for malformed inputs.

| Route | Input | Success |
| --- | --- | --- |
| NLP `POST /internal/enrichment/prepare` | Existing six-field `EnrichRequest` | 200 `{job: LlmJob, prompt_version, provider}`; no publication |
| NLP `POST /internal/enrichment/evaluate` | `{request, stage, model, provider, prompt_version, candidate, result: LlmResult}` | 200 `{outcome, enrichment, candidate, job, failure}`; unused fields null |
| NLP `POST /internal/enrichment/publish` | Exact prepared `LlmJob`, including its recorded UUID | 202 `{job_id, status: "queued"}` after confirmation; 503 if disabled/unconfirmed |
| Symfony `POST /internal/enrichment/results` | `LlmResult`, plus `X-LLM-Correlation-ID` matching its UUID | 200 `{disposition: "applied"}` or `"ignored"`; 400 malformed/uncorrelated; 401 unauthorized; 503 unavailable |

Evaluation outcomes: `COMPLETED` supplies final `EnrichResponse`; `REPAIR` supplies
candidate and new generic job; `FAILED` supplies a safe failure code. Only REPAIR
input includes a non-null candidate. A failed worker result may be resolved in
Symfony without calling NLP. There is no status polling API in this phase.

Bruno includes all four internal routes, a repair-fallback example, and the
existing single-item form action. Set `enrichmentInternalToken` as a local secret.
The form requires the matching browser session/CSRF token. Publish/result examples
are operator-only and must not be run against arbitrary production jobs. A prepared
UUID not recorded by Symfony will be treated as unknown by the application consumer.

## ACK, duplicates, failures and crash windows

- Completed/failed jobs cannot apply a result again. The current active UUID must
  match. GENERATE redelivery after REPAIR is ignored; it cannot start a second repair.
- Older request revisions and soft-deleted contexts become FAILED without changing
  stored enrichment. Unknown UUIDs return ignored and are ACKed, without creating
  fictitious application records. Keep diagnostic jobs off this production workflow.
- Result application and COMPLETED are committed atomically. If the response or ACK
  is lost, redelivery sees the committed terminal state and is harmless.
- Failed publication is recorded as `PUBLICATION_UNCONFIRMED`. Acceptance may have
  happened despite the error; a late result for that failed job is ignored. No
  exactly-once inference or delivery guarantee is claimed.
- Initial prepare failure is recorded as `PREPARATION_FAILED`. Existing enrichment
  remains visible throughout failure/regeneration.
- A crash after application/stage commit but before publication can leave QUEUED
  with no work in RabbitMQ. A crash after confirmed publication but before the
  PROCESSING update may leave QUEUED although a result can still complete it.
- A crash after REPAIR transition but before repair publication can strand that
  stage. Redelivered GENERATE is ignored; it is not republished automatically.
  These are explicit visible states, not silently missing application jobs.
- Recovery for stranded QUEUED/PROCESSING jobs is operator inspection followed by
  **Generate/Regenerate again**, which creates a new revision and supersedes the old
  pending job. Do not manually republish old UUIDs. There is no automatic retry,
  reconnect loop, deadline, outbox or recovery scheduler in 3A.
- If Symfony/NLP is unavailable or a database transaction fails during result
  processing, the callback returns 503. The bridge exits, closes its connection,
  and leaves delivery unacknowledged. Restart it explicitly after fixing the issue.
- Malformed/uncorrelated transport messages are rejected without requeue, as in
  Phase 2. There is no DLQ; such messages are discarded.
- NLP evaluation is a bounded internal HTTP request while the context transaction
  holds its row lock. It never waits for inference. Preparation and publication
  are outside that transaction. There is no guarantee that a failed UI HTTP
  request means the durable job was not submitted.

## Consumer exclusivity

**Never run `wordtracker_nlp.llm_results` (diagnostic FileResultStore) and
`wordtracker_nlp.enrichment_results` (production) concurrently on `llm.results`.**
They compete for messages. Each CLI prints a warning; this is an operational rule,
not a cross-host lock. Stop the diagnostic consumer before production testing.
The diagnostic endpoint and FileResultStore remain for isolated diagnostic use.

Run one production consumer in a terminal:

```bash
docker compose exec -T nlp python -m wordtracker_nlp.enrichment_results
```

It uses the NLP container's RabbitMQ configuration, shared internal token, and
`ENRICHMENT_APP_BASE_URL`. No automatic consumer starts with the web container.

## Manual end-to-end verification (not performed automatically)

1. On DEXTER verify the already running broker and `/wordtracker` queues. In its
   existing RabbitMQ runtime, run `rabbitmqctl list_queues -p /wordtracker name
   messages_ready messages_unacknowledged consumers`. Use its existing deployment
   tooling; WordTracker does not start or change that broker.
2. On Silver Monkey, from the standalone worker project's directory:

   ```bash
   docker compose ps
   docker compose logs --tail=50 dexter-worker
   nvidia-smi
   ```

   The existing worker and its Ollama must already be running with `qwen3:14b`.
   If that independent project's service name differs, use its actual service name.
   Do not pull models or change that project's configuration as part of this test.
3. Stop the diagnostic consumer with Ctrl-C in its terminal. Verify no diagnostic
   `python -m wordtracker_nlp.llm_results` process is running elsewhere on the queue.
4. Set the root `.env` values above, including the shared secret and existing broker
   credentials. Confirm the intended application database before applying migration:

   ```bash
   docker compose exec -T app-dev php bin/console doctrine:query:sql 'SELECT current_database()'
   docker compose exec -T app-dev php bin/console doctrine:migrations:migrate --no-interaction
   docker compose exec -T app-dev php bin/console doctrine:schema:validate
   docker compose up -d --no-deps app-dev nlp
   ```

   These are operator deployment commands, not test setup. Do not substitute the
   development database for PHPUnit. Existing Ollama remains available for bulk and
   rollback; this phase has not removed its Compose dependency.
5. Start the production consumer using the command above. Keep its terminal open.
6. Open `http://localhost:8080`, choose an analyzed English publication, open a
   vocabulary detail page, and click the contextual **Generate enrichment** button.
   The redirect should return without waiting for inference. It normally shows
   PROCESSING; a very fast result may already show COMPLETED. QUEUED is also visible
   while preparing/publishing or after an interrupted submission.
7. Inspect application state without exposing prompt/context text:

   ```bash
   docker compose exec -T app-dev php bin/console doctrine:query:sql 'SELECT id, publication_vocabulary_id, status, stage, active_llm_job_id, model, failure FROM publication_vocabulary_enrichment_job ORDER BY id DESC LIMIT 10'
   ```

8. Verify `llm.jobs` is consumed using the DEXTER queue inspection command. On Silver
   Monkey follow `docker compose logs -f dexter-worker` and observe `nvidia-smi`
   during inference. Confirm the job uses `qwen3:14b`; inference may finish too
   quickly for a single GPU snapshot.
9. Verify `llm.results` is consumed by the **production** bridge, not the diagnostic
   process. Repeat the application job query; expect COMPLETED or a safe failure
   code. Queue depth alone may miss short-lived messages.
10. Refresh the vocabulary page. Verify COMPLETED, contextual enrichment fields,
    model and prompt metadata. Repeat with Regenerate; existing enrichment must
    remain visible while the replacement is pending. Inspect export/study as needed.
11. Turn off the single-item flag for rollback and recreate only the app container:

    ```bash
    # Set ASYNC_ENRICHMENT_ENABLED=false in the root .env first.
    docker compose up -d --no-deps app-dev
    ```

    Existing pending jobs may still complete; a newer synchronous request advances
    the revision and prevents an older async result from overwriting it.

## Automated checks

No GPU/broker is needed: Python mocks publication and application HTTP, and PHP
uses a configurable gateway double. The database reset guard requires exactly
`wordtracker_test`. Run from the repository root:

```bash
docker compose exec -T nlp python -m compileall -q wordtracker_nlp
docker compose exec -T nlp pytest -q
docker compose exec -T -e APP_ENV=test app-dev php bin/console doctrine:migrations:migrate --env=test --no-interaction
docker compose exec -T -e APP_ENV=test app-dev ./vendor/bin/phpunit --do-not-cache-result
docker compose exec -T -e APP_ENV=test app-dev php bin/console doctrine:schema:validate --env=test
docker compose config --quiet
git diff --check
```

The explicit `APP_ENV=test` is required with the existing Docker/PHPUnit bootstrap.
Verify `SELECT current_database()` returns `wordtracker_test` before test schema
operations. The repository's test configuration fixes this database name.

## Remaining scope

Bulk, `/enrich`, the synchronous Symfony provider, `LlmGenerationClient`,
`OllamaClient`, and the local Ollama stack remain. Page refresh is the only UI
update mechanism. Phase 3B should close the documented durable-publication recovery
window with a narrowly scoped dispatch/reconciliation mechanism and operational
visibility, before retiring synchronous/local Ollama ownership. No power management,
frontend polling, generic retry/DLQ framework or worker changes belong to 3A.

## Implementation inventory and verification

Added application files:

- `app/migrations/Version20260923090000.php`
- `app/src/Entity/PublicationVocabularyEnrichmentJob.php`
- `app/src/Enum/EnrichmentJobStatus.php`, `EnrichmentJobStage.php`
- `app/src/Repository/PublicationVocabularyEnrichmentJobRepository.php`
- `app/src/Application/AsyncEnrichmentWorkflow.php`
- `app/src/Controller/EnrichmentResultController.php`
- `app/src/Enrichment/AsyncEnrichmentGatewayInterface.php`, `HttpAsyncEnrichmentGateway.php`,
  `EnrichmentRequestFactory.php`, `EnrichmentPersister.php`
- `app/tests/AsyncEnrichmentWorkflowTest.php`,
  `app/tests/Double/ConfigurableAsyncEnrichmentGateway.php`

Changed application files:

- `app/src/Application/EnrichPublicationVocabularyHandler.php`
- `app/src/Controller/PublicationController.php`
- `app/src/Entity/PublicationVocabulary.php`
- `app/src/Enrichment/VocabularyEnrichmentRequest.php`
- `app/templates/vocabulary/show.html.twig`
- `app/config/services.yaml`, `app/config/services_test.yaml`, `app/.env.example`

Added NLP files: `nlp/wordtracker_nlp/async_enrichment.py`,
`nlp/wordtracker_nlp/enrichment_results.py`, `nlp/tests/test_async_enrichment.py`.
Changed NLP files: `main.py` (router), `enrichment.py` (shared generation options),
`llm_results.py` (consumer exclusivity warning).

Added Bruno requests: `App/Generate Publication Vocabulary Enrichment.bru`,
`App/Apply Async Enrichment Result.bru`, `NLP/Prepare Async Enrichment.bru`,
`NLP/Publish Prepared Enrichment Job.bru`, `NLP/Evaluate Async Generation.bru`,
`NLP/Evaluate Async Repair Fallback.bru`. Changed `bruno/environments/Local.bru`
with a secret-name declaration and nonsecret form placeholders.

Other changes: `docker-compose.yml`, `.env.example`, `.gitignore`, `README.md`,
`docs/llm-jobs.md`, and this new guide. The root `.env` was not edited.

Implementation verification: 157 Python tests pass; full PHP suite passes with
128 tests / 1,557 assertions. PHP syntax, Symfony container lint, Python compile,
Doctrine mapping/database synchronization on `wordtracker_test`, Compose config,
13 offline Bruno parses, and `git diff --check` pass. The Python test client emits
an upstream AnyIO deprecation warning. Migration was applied only to
`wordtracker_test`; the development database was not migrated. No live broker/GPU
end-to-end test was performed and no services were recreated by this implementation.

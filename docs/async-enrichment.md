# Asynchronous enrichment: Phase 3B cutover

All UI enrichment uses the persisted application workflow, not the Phase 2 diagnostic endpoint.
Symfony/PostgreSQL is the only durable workflow store. NLP is stateless: it builds
prompts, parses and validates results, and decides repair/fallback. The worker
receives the existing generic version-1 envelope only. No worker changes are needed.

## Configuration

Merge these into the root **untracked** `.env`, preserving your broker settings:

```dotenv
ASYNC_ENRICHMENT_MODEL=qwen3:14b
ASYNC_ENRICHMENT_PROVIDER=ollama
RABBITMQ_ENABLED=true
ENRICHMENT_APP_BASE_URL=http://nginx-dev
# Set ENRICHMENT_INTERNAL_TOKEN to a strong local secret, shared by Symfony/NLP.
```

Generate, Regenerate and Enrich selected always use `AsyncEnrichmentWorkflow`.
The old `ASYNC_ENRICHMENT_ENABLED` flag is retired and ignored even if a local
`.env` still contains it. There is no synchronous UI fallback.
`ASYNC_ENRICHMENT_MODEL` is independent of legacy `OLLAMA_*` settings; no such
settings are injected by Compose. Provider metadata still defaults to `ollama`,
identifying the external inference backend, not the transport. Historical records
are unchanged. RabbitMQ is enabled by default in Compose; an explicit false value
will fail submissions and prevent consumer operation, not restore synchronous UI.

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
 → PublicationController::enrichPublicationVocabulary / bulkEnrichVocabulary
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

Bulk validates the existing CSRF token, publication selection and 100-item limit.
It finds active selected contextual rows and calls `AsyncEnrichmentWorkflow::submit`
once per eligible row. Each has its own durable application job and LLM UUID.
Preparation/publication failures produce independent FAILED jobs; ineligible rows
are reported without a job. Other rows continue. The redirect reports queued jobs
and submission failures. Neither bulk nor single-item waits for inference. Bulk
still waits for sequential bounded prepare/publish HTTP calls, so broker/service
outages or large selections can make submission slow; this is not a batch engine.

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
  pending job. Do not manually republish old UUIDs. There is no application retry/reconnect loop, deadline, outbox or job recovery
  scheduler. Compose process restarts do not reconcile stranded jobs.
- If Symfony/NLP is unavailable or a database transaction fails during result
  processing, the callback returns 503. The bridge exits, closes its connection,
  and leaves delivery unacknowledged. Compose restarts the process according to
  `unless-stopped`; no retry loop was added to the application. Stop the service
  explicitly while diagnosing a persistent failure, then start it when corrected.
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

The normal Compose service `enrichment-consumer` runs the existing
`python -m wordtracker_nlp.enrichment_results` module using the NLP build and code.
It has no host port and starts no HTTP server. Its `unless-stopped` policy restarts
an exited process (including after application/broker failure), without adding
transport retry logic or Phase 3A reconciliation. It depends on both nginx services
so either supported application callback URL has its startup dependency present.
Dependency ordering means started, not application readiness; startup failures
are handled by the restart policy. It uses the existing WordTracker network.

## Manual verification (operator-run)

1. Ensure the existing DEXTER RabbitMQ `/wordtracker` broker and standalone Silver
   Monkey dexter-worker/Ollama are running. Do not change either deployment.
2. Stop any old manually started `enrichment_results` process and the diagnostic
   `llm_results` consumer before normal startup. Do not start either manually again.
3. Verify root `.env` contains the existing broker credentials,
   `RABBITMQ_ENABLED=true`, the shared `ENRICHMENT_INTERNAL_TOKEN`, and the correct
   `ENRICHMENT_APP_BASE_URL`. Never paste or commit secrets. Phase 3A's migration must
   already be applied to the intended database; Phase 3B adds no migration and does
   not automatically migrate application databases.
4. Start WordTracker normally:

   ```bash
   docker compose config --quiet
   docker compose up -d
   docker compose ps
   docker compose logs -f enrichment-consumer
   ```

   Alternatively use `make start` or `make restart`. Expect `nginx-dev`, `app-dev`,
   `nginx-prod`, `app-prod`, `db`, `nlp`, and `enrichment-consumer`. The consumer logs
   `Waiting for enrichment results` after connecting. No current service starts
   WordTracker Ollama, binds port 11434 or requests GPUs. A previously created,
   stopped Ollama container may be reported as an orphan; this change does not
   delete it or the old model volume. Do not use volume deletion or orphan removal
   as part of this verification.
5. Open `http://localhost:8080`, choose an analyzed English publication and a word.
   Click Generate enrichment. Verify PROCESSING/QUEUED, then refresh to see
   COMPLETED and enrichment. Regenerate follows the same path. A fast worker may
   complete before the first refresh.
6. On the publication page select several eligible items and click Enrich selected.
   Verify the queued count and independent jobs:

   ```bash
   docker compose exec -T app-dev php bin/console doctrine:query:sql 'SELECT id, publication_vocabulary_id, status, stage, active_llm_job_id, failure FROM publication_vocabulary_enrichment_job ORDER BY id DESC LIMIT 20'
   ```

7. Watch `docker compose logs -f enrichment-consumer` for acknowledged job IDs.
   Refresh later; enrichment badges/details should appear. Failed submissions
   should not change successful existing enrichment or other submitted jobs.

No second/manual consumer, model pull, GPU setup, broker change or volume deletion
is needed in WordTracker. The external worker performs all inference.

## Automated checks

No GPU/broker is needed: Python mocks publication and application HTTP, and PHP
uses a configurable gateway double. The database reset guard requires exactly
`wordtracker_test`. Run from the repository root:

```bash
docker compose run --rm --no-deps -T nlp python -m compileall -q wordtracker_nlp
docker compose run --rm --no-deps -T nlp pytest -q
docker compose run --rm --no-deps -T -e APP_ENV=test app-dev php bin/console doctrine:migrations:migrate --env=test --no-interaction
docker compose run --rm --no-deps -T -e APP_ENV=test app-dev ./vendor/bin/phpunit --do-not-cache-result
docker compose run --rm --no-deps -T -e APP_ENV=test app-dev php bin/console doctrine:schema:validate --env=test
python3 scripts/test_compose.py
docker compose config --quiet
git diff --check
```

The explicit `APP_ENV=test` is required with the existing Docker/PHPUnit bootstrap.
Verify `SELECT current_database()` returns `wordtracker_test` before test schema
operations. The repository's test configuration fixes this database name.

## Remaining scope / DEXTER deployment

Legacy `/enrich`, the synchronous Symfony provider/handler, `LlmGenerationClient`,
`OllamaClient`/`OllamaConfig` and their tests remain. No normal UI invokes them.
WordTracker no longer declares an Ollama service or model volume. The old physical
volume is retained. NLP still owns all parsing, validation, repair and fallback.

Phase 3A commit/publish crash windows are unchanged. No reconciliation, generic
retry/DLQ, batch engine, frontend polling, power management or second workflow
store is introduced. `make start` and `make restart` already operate on the Compose
service list and need no topology-specific command changes.

Before moving WordTracker to DEXTER, plan application/database data migration,
verify Phase 3A schema and broker reachability, inject deployment secrets, choose
the intended app/nginx callback URL, and ensure only this production consumer
owns the results queue. The current dual dev/prod Compose setup still shares a
database and source bind mounts; this phase is not a production deployment redesign.

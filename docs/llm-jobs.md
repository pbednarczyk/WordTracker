# Phase 2: asynchronous LLM infrastructure

The two paths intentionally coexist:

```text
POST /enrich -> enrichment prompts/parsing/validation/repair
            -> LlmGenerationClient -> OllamaClient (synchronous)

POST /internal/llm/jobs -> LlmJobPublisher -> RabbitMQ llm.jobs
                                              |
                                     external compute worker
                                              |
                                     RabbitMQ llm.results
                                              |
                                independent NLP result consumer
                                              |
                                  local diagnostic JSON files
```

The job publisher never waits for generation or consumes results. It waits for
broker confirmation only. The result consumer is a separate process because
FastAPI has no asynchronous application workflow to resume in Phase 2.
No Symfony entities or enrichment state are updated by this path.

## Configuration

Copy the relevant settings from the root `.env.example` into your local,
gitignored `.env`; merge with existing settings rather than replacing the file.
Never commit passwords or tokens.

| Variable | Default | Purpose |
| --- | --- | --- |
| `RABBITMQ_ENABLED` | `false` | Enable the diagnostic endpoint/consumer (`true`, `1`, or `yes`) |
| `RABBITMQ_HOST` | `dexter` | External broker hostname; must resolve from the NLP container |
| `RABBITMQ_PORT` | `5672` | AMQP port |
| `RABBITMQ_VHOST` | `/wordtracker` | Required isolation boundary; any other value is rejected |
| `RABBITMQ_USER` | `wordtracker` | Application user |
| `RABBITMQ_PASSWORD` | empty | Required broker secret when using jobs |
| `LLM_JOBS_QUEUE` | `llm.jobs` | Durable classic request queue |
| `LLM_RESULTS_QUEUE` | `llm.results` | Durable classic result queue |
| `LLM_JOBS_API_TOKEN` | empty | Required secret for internal HTTP submission |

Normal synchronous development needs none of these secrets and makes no broker
connection at startup. Ollama remains in Compose. RabbitMQ is external, so no
RabbitMQ service or `depends_on` is added. Queue names may be overridden only if
both NLP and the external worker agree.

Pika `1.4.4` is the sole new dependency: its blocking adapter fits the existing
synchronous handlers and provides publisher confirms and mandatory publishing.
See [Pika's adapter documentation](https://pika.readthedocs.io/en/stable/modules/adapters/blocking.html).
Each publish owns its connection; connections are not shared between request
threads. Connection setup has a 5-second socket timeout, 10-second stack timeout,
one attempt, a 10-second broker-blocked timeout, and a 30-second heartbeat.
There is no application retry loop.

The endpoint is an opt-in diagnostic interface, protected with a token, not a
permanent public generation API. Keep port 8000 within the trusted development
network; use a protected channel if accessing it beyond that network. The given
5672 setup uses plain AMQP. Production access control/TLS is a later deployment
concern.

## Version 1 contracts

All listed envelope fields are required, except `options`, which defaults to an
empty object for submission. Unknown envelope fields and unsupported versions
are rejected. Version is the integer `1` (not a string or boolean). IDs are UUID
strings; producers use canonical UUID text. Timestamps are ISO-8601 UTC (`Z` or
`+00:00`); naive/non-UTC timestamps are rejected.

Job on `llm.jobs`:

```json
{
  "schema_version": 1,
  "job_id": "0dc7c396-3fd0-44c7-b883-b3327d293ca1",
  "type": "llm.generate",
  "model": "qwen3:14b",
  "prompt": "Return a JSON object with a short greeting in the answer field.",
  "format": {
    "type": "object",
    "properties": {"answer": {"type": "string"}},
    "required": ["answer"],
    "additionalProperties": false
  },
  "options": {"temperature": 0.2},
  "created_at": "2026-09-20T12:00:00Z"
}
```

Completed result on `llm.results`:

```json
{
  "schema_version": 1,
  "job_id": "0dc7c396-3fd0-44c7-b883-b3327d293ca1",
  "type": "llm.generate.result",
  "status": "completed",
  "model": "qwen3:14b",
  "response": "{\"answer\":\"Hello!\"}",
  "worker": "SilverMonkey",
  "completed_at": "2026-09-20T12:00:03Z",
  "error": null
}
```

Failed result:

```json
{
  "schema_version": 1,
  "job_id": "0dc7c396-3fd0-44c7-b883-b3327d293ca1",
  "type": "llm.generate.result",
  "status": "failed",
  "model": "qwen3:14b",
  "response": null,
  "worker": "SilverMonkey",
  "completed_at": "2026-09-20T12:00:03Z",
  "error": {"code": "BACKEND_TIMEOUT", "message": "Generation timed out."}
}
```

`response` is raw model text, not a parsed application object. Even empty or
non-JSON model text is a valid completed transport result; application validation
belongs to the calling workflow. Completed results require a string response and
null error; failed results require null response and a nonblank error code and
message. Suggested generic codes include `BACKEND_TIMEOUT`, `BACKEND_UNAVAILABLE`,
`MODEL_NOT_FOUND`, and `INVALID_REQUEST`; codes are not an application enum.
Errors must not expose credentials or backend secrets.

The worker treats `prompt`, `format` (a JSON schema object), and `options` as
backend inputs. It must not interpret vocabulary concepts, implement CEFR or
translation validation, repair examples, or access Symfony entities. A future
backend may translate these generic inputs into its own request format; an
unsupported model/schema/option should produce a failed result, not be silently
ignored. `model` in the result identifies the requested model. No opaque
application metadata is needed in v1.

AMQP properties on both messages:

- `content_type=application/json`, `content_encoding=utf-8`
- `delivery_mode=2` (persistent)
- `correlation_id=<canonical job_id>`; results with missing/mismatched correlation
  are rejected
- `message_id=<job_id>` and `type` matching the envelope are also set by NLP;
  external workers should set them on results

Both queues are declared durable, non-exclusive, non-auto-delete, with
`x-queue-type=classic`. Publication uses the default exchange, queue name as the
routing key, `mandatory=true`, and publisher confirms. Queue property mismatches
fail visibly; the application does not delete or recreate queues.

## Internal HTTP API

```http
POST /internal/llm/jobs
Content-Type: application/json
X-LLM-Jobs-Token: <local secret>
```

```json
{
  "model": "qwen3:14b",
  "prompt": "Return a JSON object with a short greeting in the answer field.",
  "format": {
    "type": "object",
    "properties": {"answer": {"type": "string"}},
    "required": ["answer"],
    "additionalProperties": false
  },
  "options": {"temperature": 0.2}
}
```

After a confirmed publish:

```http
HTTP/1.1 202 Accepted
```

```json
{"job_id":"0dc7c396-3fd0-44c7-b883-b3327d293ca1","status":"queued"}
```

`queued` means the broker accepted the message. It is not a live status and does
not imply a worker is online; execution may already have begun by the time the
HTTP response arrives. There is no status/retrieval HTTP endpoint in this phase.

Errors: `404` when disabled; `401` for missing/incorrect token; `422` for invalid
request fields; `503` for missing configuration or an unconfirmed publish. Error
responses use FastAPI's `detail` field and do not include broker exception text.

## External worker responsibilities (Silver Monkey changes)

The existing proof of concept must be updated before the full round trip works:

1. Connect to DEXTER using environment credentials and **`/wordtracker`**. Ignore
   the old test queue in `/`. Declare both durable classic queues with the same
   properties, and consume `llm.jobs` with manual ACK and bounded prefetch.
2. Validate version, type, UUID, timestamp, input fields and body/header
   correlation. Do not execute unsupported versions or malformed jobs. For a
   malformed job that cannot be safely correlated, log a sanitized reason and
   reject without requeue; no immediate infinite retry loop.
3. Call its configured LLM backend with the requested model, prompt, schema and
   options. Today that is local Ollama at
   `http://localhost:11434/api/generate`, using non-streaming output. Backend
   execution remains entirely external to WordTracker's new job infrastructure.
4. Construct a completed result with raw output, or a failed result for backend
   errors where possible. Preserve the job UUID/model, include worker identity,
   and use a UTC completion timestamp.
5. Publish the result persistently to `llm.results` with matching
   `correlation_id`, mandatory routing, and publisher confirms.
6. **ACK the original job only after result publication is confirmed.** A
   confirmed failed result also completes that delivery. The old ACK-after-LLM
   behavior is insufficient because it can lose results.
7. If result publication fails, stop/pause consumption and leave the job
   unacknowledged for operator recovery. Do not enter an immediate requeue loop.
   A restart can repeat generation or result delivery, so preserve `job_id` and
   plan for duplicate handling. No exactly-once execution is promised.

No worker implementation or backend dependency is added to this repository.

## Result consumer and reliability boundaries

Run one diagnostic consumer against a local Linux filesystem directory:

```bash
docker compose exec nlp python -m wordtracker_nlp.llm_results --output-dir /srv/nlp/.llm-results
```

For valid results the consumer checks UUID/header correlation, writes and fsyncs
a temporary file, atomically links it as `<job_id>.json`, fsyncs the directory,
and only then ACKs. Files are private (mode 0600). Identical redeliveries are
ACKed without overwriting the stored result. A different result for an existing
UUID stops the consumer and preserves the first file; an operator must inspect
the conflict before restarting. Storage failures also stop without ACK, and
connection closure allows broker redelivery. There is no automatic reconnect
loop. Linux local filesystem hard-link/fsync semantics are required; this is not
a shared production result store. Crash leftovers named `.result-*` can be
removed while the consumer is stopped.

Malformed/unsupported/uncorrelated messages are logged without payloads and
rejected with `requeue=false`. **With no DLQ in Phase 2 they are discarded.**
Use this consumer only for the diagnostic queue until the worker contract is
verified. Valid failed results are persisted and ACKed like completed results.

Correlation in this phase means matching the body UUID to the AMQP header and
the returned HTTP job ID to the stored filename. There is no registry of issued
jobs, so this consumer does not prove a result belongs to a previously submitted
job or verify it against the original model request. Persisted application job
state and stronger lifecycle checks belong to Phase 3.

Publisher confirms/persistent messages do not guarantee exactly-once delivery.
A connection loss after acceptance can yield HTTP 503 even though a job was
queued. Retrying the HTTP request creates a new UUID and may repeat work. A 202
also cannot guarantee the HTTP client receives that response. There is no
idempotent submission key or outbox in this diagnostic phase.

## Manual DEXTER / Silver Monkey round-trip test (optional)

Normal unit tests mock RabbitMQ and never connect to DEXTER.

1. On DEXTER verify `/wordtracker` and the `wordtracker` user exist, and the user
   can configure/read/write the two queues (including publishing through the
   default exchange). Verify `llm.jobs` is durable classic. NLP will declare
   `llm.results` with the same durable classic properties; an administrator can
   pre-create it identically. Do not touch the queue in `/`. Ensure port 5672 is
   reachable and `dexter` resolves from the NLP container. No IP is hardcoded.
2. Update/start the external worker as above. Confirm it has the requested model
   available; the previously tested model is `qwen3:14b`.
3. Set the root `.env` secrets and `RABBITMQ_ENABLED=true`. Rebuild/recreate only
   NLP for the new dependency and configuration:

   ```bash
   docker compose build nlp
   docker compose up -d --no-deps nlp
   ```

4. Start the consumer using the command above. In Bruno, use the Local
   environment and set `llmJobsApiToken` as a **local secret variable** (same value
   as `LLM_JOBS_API_TOKEN`; do not export/commit it).
5. Send `NLP / Submit Internal LLM Job`. Expect 202 and save the returned UUID.
   Confirm `/srv/nlp/.llm-results/<job_id>.json` appears and its UUID, model, raw
   response, worker and status match the submission. With the bind mount, the
   same file is under `nlp/.llm-results/` on the host (gitignored).
6. Request a nonexistent model to verify a `failed` result. Re-deliver an
   identical result to verify safe duplicate handling. Test malformed messages
   only deliberately: they will be discarded as described above. Stop the
   consumer before inspecting/resolving conflicting results.
7. Check ordinary `/enrich` still works through the existing Ollama service.
   Disable diagnostics again if no longer needed; stored files contain model
   output and can be removed when the test is complete.

Phase 3 and later work remains deferred: Symfony async enrichment, UI polling,
production job persistence/idempotency, enrichment result application and repair
jobs, worker orchestration, Wake-on-LAN/power management, retries/DLQ, and removal
of the local Ollama service. Nothing here deploys WordTracker onto DEXTER.

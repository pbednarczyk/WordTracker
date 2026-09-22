import json
from types import SimpleNamespace
from unittest.mock import Mock
from uuid import uuid4

import httpx
import pytest
from fastapi.testclient import TestClient

from wordtracker_nlp.main import app, analyzer
from wordtracker_nlp.async_enrichment import prepare, EvaluationRequest, evaluate_result
from wordtracker_nlp.enrichment import (ENRICHMENT_FORMAT_SCHEMA, REPAIRED_SIMPLE_EXAMPLE_FORMAT_SCHEMA,
                                       PROMPT_VERSION, build_enrichment_prompt, Enrichment)
from wordtracker_nlp.llm_jobs import LlmPublishError
from wordtracker_nlp.llm_jobs_api import get_llm_job_publisher
from wordtracker_nlp.enrichment_results import ApplicationResultSink, process_result, consume_results
from wordtracker_nlp.rabbitmq import RabbitMqConfig
from wordtracker_nlp.models import EnrichRequest


@pytest.fixture
def request_data():
    return EnrichRequest(lemma="willingness", part_of_speech="NOUN", original_form="willingness",
                         context_sentence="His willingness to help impressed everyone.")


@pytest.fixture
def content():
    return dict(translation_pl="gotowość", definition_en="being ready", meaning_in_context="readiness to help",
                simple_example="Her willingness to help mattered.", cefr_level="B2")


def result(raw, **changes):
    return dict(schema_version=1, type="llm.generate.result", job_id=str(uuid4()),
                model="qwen3:14b", status="completed", response=raw, worker="test-worker",
                completed_at="2026-09-23T00:00:00Z", error=None) | changes


def evaluate(request_data, payload, stage="GENERATE", candidate=None, **changes):
    return evaluate_result(EvaluationRequest(request=request_data, stage=stage, model="qwen3:14b",
        provider="ollama", prompt_version=PROMPT_VERSION, result=payload, candidate=candidate, **changes), analyzer)


def test_preparation_reuses_real_semantics_and_never_ollama(request_data, monkeypatch):
    monkeypatch.setenv("ASYNC_ENRICHMENT_MODEL", "qwen3:14b")
    monkeypatch.setenv("OLLAMA_BASE_URL", "http://must-not-be-used")
    prepared = prepare(request_data)
    assert prepared.job.prompt == build_enrichment_prompt(request_data)
    assert prepared.job.format == ENRICHMENT_FORMAT_SCHEMA
    assert prepared.job.options == {"temperature": 0.2}
    assert prepared.job.model == "qwen3:14b"
    assert prepared.prompt_version == PROMPT_VERSION
    assert prepared.provider == "ollama"
    assert set(prepared.job.model_dump()) == {"schema_version", "job_id", "type", "created_at", "model", "prompt", "format", "options"}


def test_generate_completion(request_data, content):
    outcome = evaluate(request_data, result(json.dumps(content)))
    assert outcome.outcome == "COMPLETED"
    assert outcome.enrichment.simple_example == content["simple_example"]
    assert outcome.enrichment.prompt_version == PROMPT_VERSION


def test_generate_to_repair_new_uuid_and_original_candidate(request_data, content):
    content["simple_example"] = "She was ready to help."
    incoming = result(json.dumps(content))
    outcome = evaluate(request_data, incoming)
    assert outcome.outcome == "REPAIR"
    assert str(outcome.job.job_id) != incoming["job_id"]
    assert outcome.job.format == REPAIRED_SIMPLE_EXAMPLE_FORMAT_SCHEMA
    assert outcome.job.options == {"temperature": 0.2}
    assert outcome.job.model == "qwen3:14b"
    assert "TARGET_NOT_PRESENT" in outcome.job.prompt
    assert outcome.candidate.model_dump() == content


@pytest.mark.parametrize("example,fallback", [("Her willingness helped.", False), ("She helped.", True)])
def test_repair_and_validated_context_fallback(request_data, content, example, fallback):
    outcome = evaluate(request_data, result(json.dumps({"simple_example": example})),
                       "REPAIR", Enrichment(**content))
    assert outcome.outcome == "COMPLETED"
    assert outcome.enrichment.simple_example == (request_data.context_sentence if fallback else example)
    assert outcome.enrichment.translation_pl == content["translation_pl"]


def test_invalid_context_fallback_fails(request_data, content):
    request_data.context_sentence = "She helped."
    outcome = evaluate(request_data, result('{"simple_example":"She helped."}'), "REPAIR", Enrichment(**content))
    assert outcome.outcome == "FAILED"


@pytest.mark.parametrize("stage", ["GENERATE", "REPAIR"])
@pytest.mark.parametrize("raw", ["", "not-json", '{}', '{"cefr_level":"Z9"}'])
def test_malformed_content_fails_without_repair_or_fallback(request_data, content, stage, raw):
    outcome = evaluate(request_data, result(raw), stage, Enrichment(**content) if stage == "REPAIR" else None)
    assert outcome.outcome == "FAILED"
    assert outcome.job is None


def test_worker_failure_and_model_mismatch(request_data):
    failed = result(None, status="failed", error={"code":"BACKEND_TIMEOUT", "message":"private backend detail"})
    assert evaluate(request_data, failed).failure == "WORKER_FAILED"
    assert evaluate(request_data, result("{}", model="wrong")).failure == "MODEL_MISMATCH"


def test_version_mismatch_fails(request_data):
    data = EvaluationRequest(request=request_data, stage="GENERATE", model="qwen3:14b", provider="ollama",
                             prompt_version="old", result=result("{}"))
    assert evaluate_result(data, analyzer).failure == "PROMPT_VERSION_MISMATCH"


@pytest.mark.parametrize("endpoint,body", [("prepare", {}), ("evaluate", {}), ("publish", {})])
def test_internal_endpoints_require_token(monkeypatch, endpoint, body):
    monkeypatch.setenv("ENRICHMENT_INTERNAL_TOKEN", "test-token")
    with TestClient(app) as client:
        assert client.post("/internal/enrichment/"+endpoint, json=body).status_code == 401


def test_publish_preserves_uuid_and_only_confirms(monkeypatch, request_data):
    monkeypatch.setenv("ENRICHMENT_INTERNAL_TOKEN", "test-token")
    monkeypatch.setenv("RABBITMQ_ENABLED", "true")
    publisher = Mock()
    app.dependency_overrides[get_llm_job_publisher] = lambda: publisher
    try:
        job = prepare(request_data).job
        with TestClient(app) as client:
            response = client.post("/internal/enrichment/publish", json=job.model_dump(mode="json"),
                                   headers={"X-Enrichment-Token":"test-token"})
            assert response.status_code == 202
            assert publisher.publish.call_args.args[0] == job
            publisher.publish.side_effect = LlmPublishError("private exception")
            response = client.post("/internal/enrichment/publish", json=job.model_dump(mode="json"),
                                   headers={"X-Enrichment-Token":"test-token"})
            assert response.status_code == 503
            assert "private" not in response.text
    finally:
        app.dependency_overrides.clear()


def test_consumer_acks_only_after_application_success():
    payload = result("{}")
    channel, sink = Mock(), Mock()
    sink.apply.side_effect = lambda value: channel.basic_ack.assert_not_called()
    process_result(channel, SimpleNamespace(delivery_tag=7), SimpleNamespace(correlation_id=payload["job_id"]),
                   json.dumps(payload).encode(), sink)
    channel.basic_ack.assert_called_once_with(delivery_tag=7)


def test_consumer_does_not_ack_on_application_failure():
    payload = result("{}")
    channel, sink = Mock(), Mock()
    sink.apply.side_effect = RuntimeError("app unavailable")
    with pytest.raises(RuntimeError):
        process_result(channel, SimpleNamespace(delivery_tag=7), SimpleNamespace(correlation_id=payload["job_id"]),
                       json.dumps(payload).encode(), sink)
    channel.basic_ack.assert_not_called()
    channel.basic_reject.assert_not_called()


def test_consumer_rejects_bad_correlation():
    channel, sink = Mock(), Mock()
    process_result(channel, SimpleNamespace(delivery_tag=7), SimpleNamespace(correlation_id="wrong"),
                   json.dumps(result("{}")).encode(), sink)
    sink.apply.assert_not_called()
    channel.basic_reject.assert_called_once_with(delivery_tag=7, requeue=False)


@pytest.mark.parametrize("disposition", ["applied", "ignored"])
def test_sink_authenticated_forwarding(disposition):
    post = Mock(return_value=httpx.Response(200, json={"disposition":disposition}, request=httpx.Request("POST", "http://app")))
    from wordtracker_nlp.llm_jobs import LlmResult
    payload = LlmResult.model_validate(result("{}"))
    ApplicationResultSink("http://app", "test-token", post).apply(payload)
    assert post.call_args.kwargs["headers"]["X-LLM-Correlation-ID"] == str(payload.job_id)
    assert post.call_args.kwargs["json"] == payload.model_dump(mode="json")


def test_consumer_closes_connection_when_app_fails():
    connection = Mock()
    connection.channel.return_value.start_consuming.side_effect = RuntimeError("app down")
    with pytest.raises(RuntimeError):
        consume_results(RabbitMqConfig(password="test-only"), Mock(), Mock(return_value=connection))
    connection.close.assert_called_once()

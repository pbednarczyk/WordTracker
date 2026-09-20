from unittest.mock import Mock
from uuid import UUID

from fastapi.testclient import TestClient
import pytest

from wordtracker_nlp.llm_jobs import LlmPublishError
from wordtracker_nlp.llm_jobs_api import get_llm_job_publisher
from wordtracker_nlp.main import app

TOKEN = "test-only-token"
REQUEST = {"model": "qwen3:14b", "prompt": "Return a greeting", "format": {"type": "object"},
           "options": {"temperature": 0.2}}


@pytest.fixture(autouse=True)
def clean_dependencies(monkeypatch):
    app.dependency_overrides.clear()
    monkeypatch.setenv("RABBITMQ_ENABLED", "true")
    monkeypatch.setenv("LLM_JOBS_API_TOKEN", TOKEN)
    yield
    app.dependency_overrides.clear()


def submit(payload=None, token=TOKEN):
    return TestClient(app).post("/internal/llm/jobs", json=REQUEST if payload is None else payload,
                                headers={} if token is None else {"X-LLM-Jobs-Token": token})


def test_submit_returns_202_after_publishing_generic_job():
    publisher = Mock()
    app.dependency_overrides[get_llm_job_publisher] = lambda: publisher
    response = submit()
    assert response.status_code == 202
    job = publisher.publish.call_args.args[0]
    assert response.json() == {"job_id": str(job.job_id), "status": "queued"}
    assert UUID(response.json()["job_id"]).version == 4
    assert {key: job.model_dump()[key] for key in REQUEST} == REQUEST
    publisher.publish.assert_called_once()


def test_publish_failure_returns_503_without_leaking_error():
    publisher = Mock()
    publisher.publish.side_effect = LlmPublishError("test-secret")
    app.dependency_overrides[get_llm_job_publisher] = lambda: publisher
    response = submit()
    assert response.status_code == 503
    assert response.json() == {"detail": "RabbitMQ did not confirm job publication."}


@pytest.mark.parametrize("enabled, configured_token, sent_token, status", [
    ("false", TOKEN, TOKEN, 404), ("true", "", TOKEN, 503),
    ("true", TOKEN, None, 401), ("true", TOKEN, "incorrect", 401),
])
def test_access_control_precedes_broker_configuration(monkeypatch, enabled, configured_token, sent_token, status):
    monkeypatch.setenv("RABBITMQ_ENABLED", enabled)
    monkeypatch.setenv("LLM_JOBS_API_TOKEN", configured_token)
    dependency = Mock(side_effect=AssertionError("Must not reach broker configuration"))
    # A zero-argument wrapper prevents FastAPI inspecting Mock's dynamic signature.
    app.dependency_overrides[get_llm_job_publisher] = lambda: dependency()
    assert submit(token=sent_token).status_code == status
    dependency.assert_not_called()


def test_no_rabbitmq_configuration_needed_for_health(monkeypatch):
    monkeypatch.delenv("RABBITMQ_ENABLED", raising=False)
    monkeypatch.delenv("RABBITMQ_PASSWORD", raising=False)
    assert TestClient(app).get("/health").status_code == 200
    assert submit().status_code == 404


@pytest.mark.parametrize("name, value", [("RABBITMQ_PASSWORD", ""), ("RABBITMQ_VHOST", "/")])
def test_bad_configuration_returns_safe_503(monkeypatch, name, value):
    monkeypatch.setenv("RABBITMQ_PASSWORD", "test-secret")
    monkeypatch.setenv(name, value)
    response = submit()
    assert response.status_code == 503
    assert response.json() == {"detail": "RabbitMQ configuration is invalid."}


@pytest.mark.parametrize("changes", [
    {"model": ""}, {"prompt": " "}, {"format": "json"}, {"options": []},
    {"publication_vocabulary_id": 42}, {"job_id": "caller-chosen"},
])
def test_invalid_request_is_not_published(changes):
    publisher = Mock()
    app.dependency_overrides[get_llm_job_publisher] = lambda: publisher
    response = submit({**REQUEST, **changes})
    assert response.status_code == 422
    publisher.publish.assert_not_called()

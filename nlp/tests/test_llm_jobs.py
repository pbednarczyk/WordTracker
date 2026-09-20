import json
from datetime import datetime, timezone
from uuid import UUID, uuid4

import pytest
from pydantic import ValidationError

from wordtracker_nlp.llm_jobs import LlmJob, LlmJobRequest, LlmResult, parse_result


@pytest.fixture
def result_payload() -> dict:
    return {
        "schema_version": 1, "job_id": str(uuid4()), "type": "llm.generate.result",
        "status": "completed", "model": "qwen3:14b", "response": '{"answer":"hello"}',
        "worker": "SilverMonkey", "completed_at": "2026-09-20T12:00:00Z", "error": None,
    }


def test_job_serializes_generic_request_with_uuid_and_utc() -> None:
    request = LlmJobRequest(model="qwen3:14b", prompt="Return a greeting.",
                            format={"type": "object"}, options={"temperature": 0.2})
    before = datetime.now(timezone.utc)
    job = LlmJob.create(request)
    body = json.loads(job.model_dump_json())
    assert body == {
        **request.model_dump(), "schema_version": 1, "type": "llm.generate",
        "job_id": str(job.job_id), "created_at": job.created_at.isoformat().replace("+00:00", "Z"),
    }
    assert isinstance(job.job_id, UUID)
    assert job.job_id.version == 4
    assert before <= job.created_at <= datetime.now(timezone.utc)
    assert LlmJob.model_validate_json(job.model_dump_json()) == job
    assert LlmJob.create(request).job_id != job.job_id


def test_completed_result_keeps_raw_model_text(result_payload: dict) -> None:
    result_payload["response"] = "not JSON; domain parsing is the caller's responsibility"
    result = parse_result(json.dumps(result_payload).encode(), result_payload["job_id"])
    assert result.status == "completed"
    assert result.response == result_payload["response"]
    assert result.error is None
    assert result.job_id == UUID(result_payload["job_id"])


def test_failed_result(result_payload: dict) -> None:
    result_payload.update(status="failed", response=None,
                          error={"code": "BACKEND_TIMEOUT", "message": "Generation timed out."})
    result = parse_result(json.dumps(result_payload).encode(), result_payload["job_id"])
    assert result.status == "failed"
    assert result.error.code == "BACKEND_TIMEOUT"
    assert result.response is None


@pytest.mark.parametrize("version", [0, 2, "1", True, 1.0, None])
def test_messages_reject_unsupported_schema_versions(result_payload: dict, version) -> None:
    result_payload["schema_version"] = version
    with pytest.raises(ValidationError):
        LlmResult.model_validate(result_payload)
    job = LlmJob.create(LlmJobRequest(model="test", prompt="test", format={})).model_dump()
    job["schema_version"] = version
    with pytest.raises(ValidationError):
        LlmJob.model_validate(job)


@pytest.mark.parametrize("correlation_id", [None, "not-a-uuid", str(uuid4())])
def test_result_requires_matching_amqp_correlation(result_payload: dict, correlation_id) -> None:
    with pytest.raises(ValueError, match="correlation_id"):
        parse_result(json.dumps(result_payload).encode(), correlation_id)


@pytest.mark.parametrize("change", [
    {"type": "enrichment.result"}, {"job_id": "invalid"}, {"status": "queued"},
    {"response": None}, {"error": {"code": "FAILED", "message": "error"}},
    {"status": "failed", "response": None, "error": None},
    {"status": "failed", "error": {"code": "FAILED", "message": "error"}},
    {"status": "failed", "response": None, "error": {"code": "", "message": "error"}},
    {"completed_at": "2026-09-20T12:00:00"},
    {"completed_at": 1789905600}, {"completed_at": "1789905600"},
    {"completed_at": "2026-09-20T12:00:00+02:00"},
    {"worker": " "}, {"model": ""}, {"response": 42}, {"unexpected": "field"},
])
def test_result_rejects_malformed_contract(result_payload: dict, change: dict) -> None:
    result_payload.update(change)
    with pytest.raises(ValueError):
        parse_result(json.dumps(result_payload).encode(), result_payload["job_id"])


@pytest.mark.parametrize("field", ["schema_version", "type", "job_id", "response", "error", "completed_at"])
def test_result_requires_wire_fields(result_payload: dict, field: str) -> None:
    del result_payload[field]
    with pytest.raises(ValidationError):
        LlmResult.model_validate(result_payload)


@pytest.mark.parametrize("body", [b"", b"not json", b"[]", b"null", b"\xff"])
def test_malformed_result_json(body: bytes) -> None:
    with pytest.raises(ValueError):
        parse_result(body, str(uuid4()))


@pytest.mark.parametrize("timestamp", [1789905600, "1789905600", "2026-09-20T12:00:00", "2026-09-20T12:00:00+01:00"])
def test_job_rejects_non_utc_or_non_iso_timestamp(timestamp) -> None:
    job = LlmJob.create(LlmJobRequest(model="test", prompt="test", format={})).model_dump()
    job["created_at"] = timestamp
    with pytest.raises(ValidationError):
        LlmJob.model_validate(job)

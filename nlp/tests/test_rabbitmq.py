from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock
from uuid import uuid4

import pika
import pytest
from pydantic import ValidationError

from wordtracker_nlp.llm_jobs import LlmJob, LlmJobRequest, LlmPublishError, LlmResult
from wordtracker_nlp.llm_results import (
    FileResultStore, ResultConflictError, consume_results, process_result,
)
from wordtracker_nlp.rabbitmq import RabbitMqConfig, RabbitMqLlmJobPublisher


@pytest.fixture
def config() -> RabbitMqConfig:
    return RabbitMqConfig(password="test-only-password")


@pytest.fixture
def broker():
    channel = Mock()
    connection = Mock(is_open=True)
    connection.channel.return_value = channel
    return Mock(return_value=connection), connection, channel


@pytest.fixture
def job() -> LlmJob:
    return LlmJob.create(LlmJobRequest(model="qwen3:14b", prompt="Hello", format={}, options={"temperature": 0.2}))


@pytest.fixture
def result() -> LlmResult:
    return LlmResult(
        schema_version=1, job_id=uuid4(), type="llm.generate.result", status="completed",
        model="qwen3:14b", response="raw text", error=None,
        worker="SilverMonkey", completed_at="2026-09-20T12:00:00Z",
    )


def test_configuration_from_environment(monkeypatch: pytest.MonkeyPatch) -> None:
    for key, value in {
        "RABBITMQ_HOST": "configured-host", "RABBITMQ_PORT": "5673",
        "RABBITMQ_VHOST": "/wordtracker", "RABBITMQ_USER": "configured-user",
        "RABBITMQ_PASSWORD": "test-only-secret", "LLM_JOBS_QUEUE": "custom.jobs",
        "LLM_RESULTS_QUEUE": "custom.results",
    }.items():
        monkeypatch.setenv(key, value)
    config = RabbitMqConfig.from_env()
    params = config.connection_parameters()
    assert (params.host, params.port, params.virtual_host) == ("configured-host", 5673, "/wordtracker")
    assert params.credentials.username == "configured-user"
    assert params.credentials.password == "test-only-secret"
    assert (config.jobs_queue, config.results_queue) == ("custom.jobs", "custom.results")
    assert params.connection_attempts == 1
    assert params.socket_timeout == 5
    assert params.stack_timeout == 10
    assert params.blocked_connection_timeout == 10
    assert params.heartbeat == 30
    assert "test-only-secret" not in repr(config)
    assert "test-only-secret" not in config.model_dump_json()


@pytest.mark.parametrize("change", [{"vhost": "/"}, {"vhost": "/other"}, {"password": ""}, {"port": 0},
    {"jobs_queue": "same", "results_queue": "same"}])
def test_invalid_config_is_rejected(change: dict) -> None:
    with pytest.raises(ValidationError):
        RabbitMqConfig(**{"password": "test-only-secret", **change})


def test_publish_confirms_persistent_correlated_job(config, broker, job) -> None:
    factory, connection, channel = broker
    events = []
    channel.confirm_delivery.side_effect = lambda: events.append("confirm")
    channel.basic_publish.side_effect = lambda **kwargs: events.append("publish")
    publisher = RabbitMqLlmJobPublisher(config, factory)
    assert publisher.publish(job) == job.job_id
    assert events == ["confirm", "publish"]
    assert factory.call_args.args[0].virtual_host == "/wordtracker"
    assert channel.queue_declare.call_count == 2
    for queue in ["llm.jobs", "llm.results"]:
        channel.queue_declare.assert_any_call(
            queue=queue, durable=True, exclusive=False, auto_delete=False,
            arguments={"x-queue-type": "classic"},
        )
    publish = channel.basic_publish.call_args.kwargs
    assert publish["mandatory"] is True
    assert publish["exchange"] == ""
    assert publish["routing_key"] == "llm.jobs"
    assert LlmJob.model_validate_json(publish["body"]) == job
    properties = publish["properties"]
    assert properties.delivery_mode == 2
    assert properties.correlation_id == str(job.job_id)
    assert properties.message_id == str(job.job_id)
    assert properties.content_type == "application/json"
    assert properties.content_encoding == "utf-8"
    channel.basic_consume.assert_not_called()
    channel.basic_get.assert_not_called()
    connection.close.assert_called_once()


@pytest.mark.parametrize("failure", [
    pika.exceptions.NackError([]), pika.exceptions.UnroutableError([]),
    pika.exceptions.AMQPConnectionError("test-only-secret"),
    pika.exceptions.ConnectionBlockedTimeout("blocked"), OSError("connection reset"),
])
def test_publish_failure_is_not_reported_as_success(config, broker, job, failure) -> None:
    factory, connection, channel = broker
    channel.basic_publish.side_effect = failure
    with pytest.raises(LlmPublishError) as exc:
        RabbitMqLlmJobPublisher(config, factory).publish(job)
    assert str(exc.value) == "RabbitMQ did not confirm job publication."
    connection.close.assert_called_once()


@pytest.mark.parametrize("stage", ["connection", "declaration", "confirm"])
def test_setup_failure(config, broker, job, stage) -> None:
    factory, connection, channel = broker
    target = {"connection": factory, "declaration": channel.queue_declare, "confirm": channel.confirm_delivery}[stage]
    target.side_effect = pika.exceptions.AMQPConnectionError()
    with pytest.raises(LlmPublishError):
        RabbitMqLlmJobPublisher(config, factory).publish(job)
    channel.basic_publish.assert_not_called()
    if stage != "connection":
        connection.close.assert_called_once()


def test_close_failure_does_not_undo_confirmation(config, broker, job) -> None:
    factory, connection, channel = broker
    connection.close.side_effect = pika.exceptions.AMQPConnectionError()
    assert RabbitMqLlmJobPublisher(config, factory).publish(job) == job.job_id


@pytest.mark.parametrize("failed", [False, True])
def test_result_is_persisted_before_ack_and_duplicate_is_safe(tmp_path, result, failed) -> None:
    if failed:
        result = LlmResult.model_validate({
            **result.model_dump(), "status": "failed", "response": None,
            "error": {"code": "BACKEND_TIMEOUT", "message": "Timed out."},
        })
    store = FileResultStore(tmp_path)
    channel = Mock()
    target = tmp_path / f"{result.job_id}.json"

    def acknowledge(**kwargs):
        assert LlmResult.model_validate_json(target.read_bytes()) == result
        assert kwargs == {"delivery_tag": 7}

    channel.basic_ack.side_effect = acknowledge
    for _ in range(2):
        process_result(channel, SimpleNamespace(delivery_tag=7),
                       SimpleNamespace(correlation_id=str(result.job_id)),
                       result.model_dump_json().encode(), store)
    assert channel.basic_ack.call_count == 2
    assert list(tmp_path.iterdir()) == [target]
    channel.basic_reject.assert_not_called()


@pytest.mark.parametrize("body, correlation", [(b"bad json", None), (None, "wrong-id")])
def test_invalid_result_rejected_without_requeue(result, body, correlation, caplog) -> None:
    channel, store = Mock(), Mock()
    process_result(channel, SimpleNamespace(delivery_tag=8), SimpleNamespace(correlation_id=correlation),
                   body or result.model_dump_json().encode(), store)
    channel.basic_reject.assert_called_once_with(delivery_tag=8, requeue=False)
    channel.basic_ack.assert_not_called()
    store.save.assert_not_called()
    assert result.response not in caplog.text


def test_storage_failure_leaves_result_unacknowledged(result) -> None:
    channel, store = Mock(), Mock()
    store.save.side_effect = OSError("disk full")
    with pytest.raises(OSError):
        process_result(channel, SimpleNamespace(delivery_tag=9),
                       SimpleNamespace(correlation_id=str(result.job_id)),
                       result.model_dump_json().encode(), store)
    channel.basic_ack.assert_not_called()
    channel.basic_reject.assert_not_called()


def test_conflicting_result_preserves_original(tmp_path: Path, result) -> None:
    store = FileResultStore(tmp_path)
    store.save(result)
    with pytest.raises(ResultConflictError):
        store.save(result.model_copy(update={"response": "different output"}))
    assert LlmResult.model_validate_json((tmp_path / f"{result.job_id}.json").read_bytes()) == result
    assert len(list(tmp_path.iterdir())) == 1


def test_fsync_failure_does_not_ack(tmp_path, result, monkeypatch) -> None:
    store = FileResultStore(tmp_path)
    channel = Mock()
    monkeypatch.setattr("wordtracker_nlp.llm_results.os.fsync", Mock(side_effect=OSError("disk error")))
    with pytest.raises(OSError):
        process_result(channel, SimpleNamespace(delivery_tag=1),
                       SimpleNamespace(correlation_id=str(result.job_id)),
                       result.model_dump_json().encode(), store)
    channel.basic_ack.assert_not_called()
    assert not list(tmp_path.iterdir())


def test_consumer_configuration_and_closes_on_storage_failure(config, broker, result) -> None:
    factory, connection, channel = broker
    store = Mock()
    store.save.side_effect = OSError("disk full")

    def deliver():
        callback = channel.basic_consume.call_args.kwargs["on_message_callback"]
        callback(channel, SimpleNamespace(delivery_tag=1),
                 SimpleNamespace(correlation_id=str(result.job_id)), result.model_dump_json().encode())

    channel.start_consuming.side_effect = deliver
    with pytest.raises(OSError):
        consume_results(config, store, factory)
    channel.basic_qos.assert_called_once_with(prefetch_count=1)
    assert channel.basic_consume.call_args.kwargs["auto_ack"] is False
    assert channel.basic_consume.call_args.kwargs["queue"] == "llm.results"
    assert channel.queue_declare.call_count == 2
    connection.close.assert_called_once()
    channel.basic_ack.assert_not_called()

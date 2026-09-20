"""RabbitMQ transport only: no enrichment or model-output semantics."""

import os
from contextlib import suppress
from typing import Callable, Literal
from uuid import UUID

import pika
from pydantic import BaseModel, ConfigDict, Field, SecretStr, field_validator, model_validator

from wordtracker_nlp.llm_jobs import LlmJob, LlmJobPublisher, LlmPublishError, NonBlank


class RabbitMqConfig(BaseModel):
    model_config = ConfigDict(hide_input_in_errors=True)

    host: NonBlank = "dexter"
    port: int = Field(default=5672, ge=1, le=65535)
    vhost: Literal["/wordtracker"] = "/wordtracker"
    user: NonBlank = "wordtracker"
    password: SecretStr = Field(repr=False)
    jobs_queue: NonBlank = "llm.jobs"
    results_queue: NonBlank = "llm.results"

    @field_validator("password")
    @classmethod
    def password_required(cls, value: SecretStr) -> SecretStr:
        if not value.get_secret_value():
            raise ValueError("RABBITMQ_PASSWORD is required.")
        return value

    @model_validator(mode="after")
    def distinct_queues(self) -> "RabbitMqConfig":
        if self.jobs_queue == self.results_queue:
            raise ValueError("Job and result queues must be distinct.")
        return self

    @classmethod
    def from_env(cls) -> "RabbitMqConfig":
        return cls(
            host=os.getenv("RABBITMQ_HOST", "dexter"),
            port=os.getenv("RABBITMQ_PORT", "5672"),
            vhost=os.getenv("RABBITMQ_VHOST", "/wordtracker"),
            user=os.getenv("RABBITMQ_USER", "wordtracker"),
            password=os.getenv("RABBITMQ_PASSWORD", ""),
            jobs_queue=os.getenv("LLM_JOBS_QUEUE", "llm.jobs"),
            results_queue=os.getenv("LLM_RESULTS_QUEUE", "llm.results"),
        )

    def connection_parameters(self) -> pika.ConnectionParameters:
        return pika.ConnectionParameters(
            host=self.host, port=self.port, virtual_host=self.vhost,
            credentials=pika.PlainCredentials(self.user, self.password.get_secret_value()),
            connection_attempts=1, socket_timeout=5, stack_timeout=10,
            blocked_connection_timeout=10, heartbeat=30,
        )


def rabbitmq_enabled() -> bool:
    return os.getenv("RABBITMQ_ENABLED", "false").lower() in {"1", "true", "yes"}


def declare_queues(channel, config: RabbitMqConfig) -> None:
    for queue in (config.jobs_queue, config.results_queue):
        channel.queue_declare(
            queue=queue, durable=True, exclusive=False, auto_delete=False,
            arguments={"x-queue-type": "classic"},
        )


def close_connection(connection) -> None:
    # Cleanup must not turn a confirmed publish into an apparent publish failure.
    with suppress(pika.exceptions.AMQPError, OSError):
        if connection.is_open:
            connection.close()


class RabbitMqLlmJobPublisher(LlmJobPublisher):
    def __init__(
        self, config: RabbitMqConfig,
        connection_factory: Callable = pika.BlockingConnection,
    ) -> None:
        self.config = config
        self.connection_factory = connection_factory

    def publish(self, job: LlmJob) -> UUID:
        connection = None
        try:
            # Each call owns its connection: BlockingConnection is not thread-safe.
            connection = self.connection_factory(self.config.connection_parameters())
            channel = connection.channel()
            declare_queues(channel, self.config)
            channel.confirm_delivery()
            channel.basic_publish(
                exchange="", routing_key=self.config.jobs_queue,
                body=job.model_dump_json().encode("utf-8"),
                properties=pika.BasicProperties(
                    content_type="application/json", content_encoding="utf-8",
                    delivery_mode=2, correlation_id=str(job.job_id),
                    message_id=str(job.job_id), type=job.type,
                ),
                mandatory=True,
            )
            return job.job_id
        except (pika.exceptions.AMQPError, OSError) as exc:
            # Never expose connection credentials or message contents in HTTP errors.
            raise LlmPublishError("RabbitMQ did not confirm job publication.") from exc
        finally:
            if connection is not None:
                close_connection(connection)

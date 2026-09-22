"""Independent diagnostic consumer: python -m wordtracker_nlp.llm_results."""

import argparse
import logging
import os
from pathlib import Path
import tempfile
from typing import Callable

import pika
from pydantic import ValidationError

from wordtracker_nlp.llm_jobs import LlmResult, parse_result
from wordtracker_nlp.rabbitmq import (
    RabbitMqConfig, close_connection, declare_queues, rabbitmq_enabled,
)

logger = logging.getLogger(__name__)


class ResultConflictError(Exception):
    pass


class FileResultStore:
    """Temporary, local diagnostic inbox. Run one consumer against this directory."""

    def __init__(self, directory: Path) -> None:
        self.directory = directory
        self.directory.mkdir(parents=True, exist_ok=True)

    def save(self, result: LlmResult) -> None:
        target = self.directory / f"{result.job_id}.json"
        # Persist a complete file before making it visible. Hard linking prevents
        # replacement of an already recorded result (including concurrent delivery).
        fd, temporary = tempfile.mkstemp(dir=self.directory, prefix=".result-")
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as stream:
                stream.write(result.model_dump_json() + "\n")
                stream.flush()
                os.fsync(stream.fileno())
            try:
                os.link(temporary, target)
            except FileExistsError:
                if LlmResult.model_validate_json(target.read_bytes()) != result:
                    raise ResultConflictError("A different result already exists for this job_id.")
            directory_fd = os.open(self.directory, os.O_RDONLY | os.O_DIRECTORY)
            try:
                os.fsync(directory_fd)
            finally:
                os.close(directory_fd)
        finally:
            os.unlink(temporary)


def process_result(channel, method, properties, body: bytes, store: FileResultStore) -> None:
    try:
        result = parse_result(body, properties.correlation_id)
    except ValueError:
        # No DLQ in this phase: invalid messages are explicitly discarded.
        logger.warning("Rejected malformed or uncorrelated LLM result; payload omitted")
        channel.basic_reject(delivery_tag=method.delivery_tag, requeue=False)
        return

    # Any storage failure escapes the callback. The CLI closes the connection,
    # leaving delivery unacknowledged for a later, explicit operator restart.
    store.save(result)
    channel.basic_ack(delivery_tag=method.delivery_tag)
    logger.info("Stored LLM result job_id=%s status=%s", result.job_id, result.status)


def consume_results(
    config: RabbitMqConfig, store: FileResultStore,
    connection_factory: Callable = pika.BlockingConnection,
) -> None:
    connection = connection_factory(config.connection_parameters())
    try:
        channel = connection.channel()
        declare_queues(channel, config)
        channel.basic_qos(prefetch_count=1)
        channel.basic_consume(
            queue=config.results_queue, auto_ack=False,
            on_message_callback=lambda ch, method, properties, body: process_result(
                ch, method, properties, body, store,
            ),
        )
        channel.start_consuming()
    finally:
        close_connection(connection)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", type=Path, required=True)
    args = parser.parse_args()
    logging.basicConfig(level=logging.INFO)
    # Pika diagnostics are not needed here; do not log broker error text.
    logging.getLogger("pika").setLevel(logging.CRITICAL)
    if not rabbitmq_enabled():
        logger.error("Set RABBITMQ_ENABLED=true to run the diagnostic result consumer")
        return 1
    logger.warning("Diagnostic consumer requires the production enrichment_results consumer to be STOPPED")
    try:
        consume_results(RabbitMqConfig.from_env(), FileResultStore(args.output_dir))
    except KeyboardInterrupt:
        return 0
    except (pika.exceptions.AMQPError, OSError, ValidationError, ResultConflictError):
        logger.error("Result consumer stopped; check configuration, broker, and result storage")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

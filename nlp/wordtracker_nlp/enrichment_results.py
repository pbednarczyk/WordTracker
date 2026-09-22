"""Production result bridge. Symfony/PostgreSQL alone owns workflow state."""
import logging
import os

import httpx
import pika
from pydantic import ValidationError

from wordtracker_nlp.llm_jobs import parse_result
from wordtracker_nlp.rabbitmq import RabbitMqConfig, close_connection, declare_queues, rabbitmq_enabled

logger = logging.getLogger(__name__)


class ApplicationResultSink:
    def __init__(self, base_url: str, token: str, post=httpx.post):
        if not token or not base_url:
            raise ValueError("Configure the application URL and internal token.")
        self.url = base_url.rstrip("/") + "/internal/enrichment/results"
        self.token = token
        self.post = post

    def apply(self, result):
        response = self.post(self.url, json=result.model_dump(mode="json"),
                             headers={"X-Enrichment-Token": self.token,
                                      "X-LLM-Correlation-ID": str(result.job_id)}, timeout=60)
        response.raise_for_status()
        payload = response.json()
        if payload not in ({"disposition": "applied"}, {"disposition": "ignored"}):
            raise ValueError("Application did not confirm a durable disposition.")


def process_result(channel, method, properties, body, sink):
    try:
        result = parse_result(body, properties.correlation_id)
    except ValueError:
        logger.warning("Rejecting malformed/uncorrelated result; payload omitted")
        channel.basic_reject(delivery_tag=method.delivery_tag, requeue=False)
        return
    # An exception exits consumption. No ACK, no automatic retry loop.
    sink.apply(result)
    channel.basic_ack(delivery_tag=method.delivery_tag)
    logger.info("Acknowledged application disposition for job_id=%s", result.job_id)


def consume_results(config, sink, connection_factory=pika.BlockingConnection):
    connection = connection_factory(config.connection_parameters())
    try:
        channel = connection.channel()
        declare_queues(channel, config)
        channel.basic_qos(prefetch_count=1)
        channel.basic_consume(queue=config.results_queue, auto_ack=False,
            on_message_callback=lambda ch, method, properties, body:
                process_result(ch, method, properties, body, sink))
        channel.start_consuming()
    finally:
        close_connection(connection)


def main():
    logging.basicConfig(level=logging.INFO)
    logging.getLogger("pika").setLevel(logging.CRITICAL)
    if not rabbitmq_enabled():
        logger.error("Set RABBITMQ_ENABLED=true to run the production consumer")
        return 1
    logger.warning("Production consumer requires the diagnostic llm_results consumer to be STOPPED")
    try:
        sink = ApplicationResultSink(os.getenv("ENRICHMENT_APP_BASE_URL", "http://nginx-dev"),
                                     os.getenv("ENRICHMENT_INTERNAL_TOKEN", ""))
        consume_results(RabbitMqConfig.from_env(), sink)
    except KeyboardInterrupt:
        return 0
    except (pika.exceptions.AMQPError, OSError, httpx.HTTPError, ValueError, ValidationError):
        logger.error("Consumer stopped without ACK; check application, broker and configuration")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

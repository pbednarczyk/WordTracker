"""Opt-in diagnostic API; not the application enrichment workflow."""

import os
import secrets
from typing import Literal
from uuid import UUID

from fastapi import APIRouter, Depends, Header, HTTPException
from pydantic import BaseModel, ValidationError

from wordtracker_nlp.llm_jobs import LlmJob, LlmJobPublisher, LlmJobRequest, LlmPublishError
from wordtracker_nlp.rabbitmq import RabbitMqConfig, RabbitMqLlmJobPublisher, rabbitmq_enabled

router = APIRouter(prefix="/internal/llm", tags=["internal LLM diagnostics"])


class LlmJobAccepted(BaseModel):
    job_id: UUID
    status: Literal["queued"] = "queued"


def require_internal_access(x_llm_jobs_token: str | None = Header(default=None)) -> None:
    if not rabbitmq_enabled():
        raise HTTPException(status_code=404, detail="LLM job submission is disabled.")
    expected = os.getenv("LLM_JOBS_API_TOKEN", "")
    if not expected:
        raise HTTPException(status_code=503, detail="LLM job submission is not configured.")
    if x_llm_jobs_token is None or not secrets.compare_digest(
        x_llm_jobs_token.encode("utf-8"), expected.encode("utf-8")
    ):
        raise HTTPException(status_code=401, detail="Invalid internal API token.")


def get_llm_job_publisher() -> LlmJobPublisher:
    try:
        return RabbitMqLlmJobPublisher(RabbitMqConfig.from_env())
    except ValidationError as exc:
        raise HTTPException(status_code=503, detail="RabbitMQ configuration is invalid.") from exc


@router.post("/jobs", status_code=202, response_model=LlmJobAccepted,
             dependencies=[Depends(require_internal_access)])
def submit_llm_job(
    request: LlmJobRequest,
    publisher: LlmJobPublisher = Depends(get_llm_job_publisher),
) -> LlmJobAccepted:
    job = LlmJob.create(request)
    try:
        publisher.publish(job)
    except LlmPublishError as exc:
        raise HTTPException(status_code=503, detail="RabbitMQ did not confirm job publication.") from exc
    return LlmJobAccepted(job_id=job.job_id)

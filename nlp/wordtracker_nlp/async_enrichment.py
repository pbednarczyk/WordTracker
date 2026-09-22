"""Stateless enrichment semantics. All resumable state is supplied by Symfony."""
import os
import secrets
from typing import Literal

from fastapi import APIRouter, Depends, Header, HTTPException
from pydantic import BaseModel, ConfigDict, model_validator

from wordtracker_nlp.analyzer import TextAnalyzer
from wordtracker_nlp.enrichment import (
    Enrichment, RepairedSimpleExample, PROMPT_VERSION, GENERATION_OPTIONS,
    ENRICHMENT_FORMAT_SCHEMA, REPAIRED_SIMPLE_EXAMPLE_FORMAT_SCHEMA,
    build_enrichment_prompt, build_simple_example_repair_prompt,
)
from wordtracker_nlp.enrichment_validation import EnrichmentValidationError, validate_enrichment
from wordtracker_nlp.llm_jobs import LlmJob, LlmJobRequest, LlmJobPublisher, LlmPublishError, LlmResult, NonBlank
from wordtracker_nlp.llm_jobs_api import get_llm_job_publisher
from wordtracker_nlp.models import EnrichRequest, EnrichResponse
from wordtracker_nlp.rabbitmq import rabbitmq_enabled


def require_enrichment_access(x_enrichment_token: str | None = Header(default=None)) -> None:
    expected = os.getenv("ENRICHMENT_INTERNAL_TOKEN", "")
    if not expected:
        raise HTTPException(503, "Internal enrichment is not configured.")
    if x_enrichment_token is None or not secrets.compare_digest(
        x_enrichment_token.encode(), expected.encode()
    ):
        raise HTTPException(401, "Invalid internal enrichment token.")


router = APIRouter(prefix="/internal/enrichment", tags=["internal enrichment"],
                   dependencies=[Depends(require_enrichment_access)])


class PreparedGeneration(BaseModel):
    job: LlmJob
    prompt_version: str
    provider: NonBlank


class EvaluationRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")
    request: EnrichRequest
    stage: Literal["GENERATE", "REPAIR"]
    model: NonBlank
    provider: NonBlank
    prompt_version: str
    result: LlmResult
    candidate: Enrichment | None = None

    @model_validator(mode="after")
    def consistent_stage(self):
        if (self.stage == "REPAIR") != (self.candidate is not None):
            raise ValueError("Only REPAIR requires a candidate.")
        return self


class EvaluationResponse(BaseModel):
    outcome: Literal["COMPLETED", "REPAIR", "FAILED"]
    enrichment: EnrichResponse | None = None
    candidate: Enrichment | None = None
    job: LlmJob | None = None
    failure: str | None = None


def check_language(request: EnrichRequest) -> None:
    if request.source_language != "en" or request.target_language != "pl":
        raise HTTPException(422, "Only English to Polish enrichment is supported.")


@router.post("/prepare", response_model=PreparedGeneration)
def prepare(request: EnrichRequest) -> PreparedGeneration:
    check_language(request)
    return PreparedGeneration(
        job=LlmJob.create(LlmJobRequest(
            model=os.getenv("ASYNC_ENRICHMENT_MODEL", "qwen3:14b"),
            prompt=build_enrichment_prompt(request), format=ENRICHMENT_FORMAT_SCHEMA,
            options=GENERATION_OPTIONS.copy(),
        )),
        prompt_version=PROMPT_VERSION,
        provider=os.getenv("ASYNC_ENRICHMENT_PROVIDER", "ollama"),
    )


def evaluate_result(data: EvaluationRequest, analyzer: TextAnalyzer) -> EvaluationResponse:
    check_language(data.request)
    if data.prompt_version != PROMPT_VERSION:
        return EvaluationResponse(outcome="FAILED", failure="PROMPT_VERSION_MISMATCH")
    if data.result.model != data.model:
        return EvaluationResponse(outcome="FAILED", failure="MODEL_MISMATCH")
    if data.result.status == "failed":
        # Do not persist arbitrary worker exception text or possible secrets.
        return EvaluationResponse(outcome="FAILED", failure="WORKER_FAILED")
    try:
        if data.stage == "GENERATE":
            enrichment = Enrichment.from_model_response(data.result.response)
            try:
                validate_enrichment(data.request, enrichment, analyzer)
            except EnrichmentValidationError as exc:
                if not exc.simple_example_only():
                    raise
                repair = LlmJob.create(LlmJobRequest(
                    model=data.model,
                    prompt=build_simple_example_repair_prompt(data.request, enrichment, exc.issues[0]),
                    format=REPAIRED_SIMPLE_EXAMPLE_FORMAT_SCHEMA,
                    options=GENERATION_OPTIONS.copy(),
                ))
                return EvaluationResponse(outcome="REPAIR", candidate=enrichment, job=repair)
        else:
            # Invalid JSON/transport errors fail, just as in synchronous enrichment.
            repaired = RepairedSimpleExample.from_model_response(data.result.response)
            enrichment = data.candidate.model_copy(update={"simple_example": repaired.simple_example})
            try:
                validate_enrichment(data.request, enrichment, analyzer)
            except EnrichmentValidationError:
                enrichment = data.candidate.model_copy(update={"simple_example": data.request.context_sentence})
                validate_enrichment(data.request, enrichment, analyzer)
        return EvaluationResponse(outcome="COMPLETED", enrichment=EnrichResponse(
            **enrichment.model_dump(), provider=data.provider, model=data.model,
            prompt_version=data.prompt_version,
        ))
    except (ValueError, EnrichmentValidationError):
        return EvaluationResponse(outcome="FAILED", failure="INVALID_ENRICHMENT")


@router.post("/evaluate", response_model=EvaluationResponse)
def evaluate(data: EvaluationRequest) -> EvaluationResponse:
    # Share the existing spaCy instance, without a second startup/model load.
    from wordtracker_nlp.main import analyzer
    return evaluate_result(data, analyzer)


@router.post("/publish", status_code=202)
def publish(job: LlmJob, publisher: LlmJobPublisher = Depends(get_llm_job_publisher)) -> dict[str, str]:
    if not rabbitmq_enabled():
        raise HTTPException(503, "RabbitMQ publication is disabled.")
    try:
        publisher.publish(job)
    except LlmPublishError as exc:
        raise HTTPException(503, "RabbitMQ did not confirm job publication.") from exc
    return {"job_id": str(job.job_id), "status": "queued"}

"""Backend-neutral wire contracts for asynchronous LLM execution."""

from datetime import datetime, timedelta, timezone
from typing import Annotated, Literal, Protocol
from uuid import UUID, uuid4

from pydantic import (
    AwareDatetime, BaseModel, ConfigDict, Field, JsonValue, StringConstraints,
    field_validator, model_validator,
)

NonBlank = Annotated[str, StringConstraints(min_length=1, pattern=r"\S")]


class ContractModel(BaseModel):
    model_config = ConfigDict(extra="forbid", frozen=True, allow_inf_nan=False)


class LlmJobRequest(ContractModel):
    model: NonBlank
    prompt: NonBlank
    format: dict[str, JsonValue]
    options: dict[str, JsonValue] = Field(default_factory=dict)


class VersionedMessage(ContractModel):
    schema_version: Literal[1]
    job_id: UUID

    @field_validator("schema_version", mode="before")
    @classmethod
    def exact_version(cls, value: object) -> object:
        if type(value) is not int or value != 1:
            raise ValueError("Unsupported schema_version; expected integer 1.")
        return value

    @field_validator("created_at", "completed_at", mode="before", check_fields=False)
    @classmethod
    def iso_timestamp(cls, value: object) -> object:
        if not isinstance(value, (str, datetime)) or (isinstance(value, str) and "T" not in value):
            raise ValueError("Timestamp must be an ISO-8601 datetime, not a Unix timestamp.")
        return value

    @field_validator("job_id", mode="before")
    @classmethod
    def uuid_only(cls, value: object) -> object:
        if not isinstance(value, (str, UUID)):
            raise ValueError("job_id must be a UUID string.")
        return value


def require_utc(value: datetime) -> datetime:
    if value.utcoffset() != timedelta(0):
        raise ValueError("Timestamp must use UTC.")
    return value


class LlmJob(VersionedMessage, LlmJobRequest):
    type: Literal["llm.generate"]
    created_at: AwareDatetime

    _utc = field_validator("created_at")(require_utc)

    @classmethod
    def create(cls, request: LlmJobRequest) -> "LlmJob":
        return cls(
            **request.model_dump(), schema_version=1, job_id=uuid4(),
            type="llm.generate", created_at=datetime.now(timezone.utc),
        )


class LlmResultError(ContractModel):
    code: NonBlank
    message: NonBlank


class LlmResult(VersionedMessage):
    type: Literal["llm.generate.result"]
    status: Literal["completed", "failed"]
    model: NonBlank
    response: str | None
    worker: NonBlank
    completed_at: AwareDatetime
    error: LlmResultError | None

    _utc = field_validator("completed_at")(require_utc)

    @model_validator(mode="after")
    def consistent_outcome(self) -> "LlmResult":
        if self.status == "completed" and (self.response is None or self.error is not None):
            raise ValueError("Completed results require response text and error=null.")
        if self.status == "failed" and (self.response is not None or self.error is None):
            raise ValueError("Failed results require response=null and an error.")
        return self


def parse_result(body: bytes, correlation_id: str | None) -> LlmResult:
    result = LlmResult.model_validate_json(body)
    if correlation_id != str(result.job_id):
        raise ValueError("AMQP correlation_id must match the result job_id.")
    return result


class LlmPublishError(Exception):
    """Publication was not confirmed; acceptance may be indeterminate."""


class LlmJobPublisher(Protocol):
    def publish(self, job: LlmJob) -> UUID:
        """Return the job ID only after broker confirmation, without awaiting execution."""
        ...

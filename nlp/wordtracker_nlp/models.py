from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator

MAX_TEXT_BYTES = 1_000_000
CefrLevel = Literal["A1", "A2", "B1", "B2", "C1", "C2"]


class AnalyzeRequest(BaseModel):
    text: str = Field(..., description="English text to analyze.")

    @field_validator("text")
    @classmethod
    def text_must_not_be_blank(cls, value: str) -> str:
        if value.strip() == "":
            raise ValueError("Text must not be empty.")

        return value

    @property
    def size_bytes(self) -> int:
        return len(self.text.encode("utf-8"))


class AnalyzedToken(BaseModel):
    text: str
    lemma: str
    pos: str
    entity_type: str | None
    sentence: str
    position: int
    is_proper_noun: bool


class AnalyzeResponse(BaseModel):
    language: str
    token_count: int
    word_count: int
    unique_lemma_count: int
    tokens: list[AnalyzedToken]


class EnrichRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    lemma: str
    part_of_speech: str
    original_form: str
    context_sentence: str
    source_language: str = "en"
    target_language: str = "pl"

    @field_validator("lemma", "part_of_speech", "original_form", "context_sentence", "source_language", "target_language")
    @classmethod
    def fields_must_not_be_blank(cls, value: str) -> str:
        if value.strip() == "":
            raise ValueError("Field must not be empty.")

        return value


class EnrichResponse(BaseModel):
    translation_pl: str
    definition_en: str
    meaning_in_context: str
    simple_example: str
    cefr_level: CefrLevel | None
    provider: str | None
    model: str | None
    prompt_version: str | None

    @field_validator("translation_pl", "definition_en", "meaning_in_context", "simple_example")
    @classmethod
    def enrichment_fields_must_not_be_blank(cls, value: str) -> str:
        if value.strip() == "":
            raise ValueError("Enrichment field must not be empty.")

        return value

from pydantic import BaseModel, Field, field_validator

MAX_TEXT_BYTES = 1_000_000


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
    lemma: str
    part_of_speech: str
    original_form: str
    context_sentence: str
    source_language: str = "en"
    target_language: str = "pl"
    model: str | None = None

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
    cefr_level: str | None
    provider: str | None
    model: str | None
    prompt_version: str | None

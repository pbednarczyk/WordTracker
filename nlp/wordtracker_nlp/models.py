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
    sentence: str
    position: int
    is_proper_noun: bool


class AnalyzeResponse(BaseModel):
    language: str
    token_count: int
    word_count: int
    unique_lemma_count: int
    tokens: list[AnalyzedToken]

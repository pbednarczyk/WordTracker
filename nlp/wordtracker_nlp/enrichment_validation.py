from dataclasses import asdict, dataclass
from typing import TYPE_CHECKING

from wordtracker_nlp.analyzer import TextAnalyzer
from wordtracker_nlp.models import EnrichRequest

if TYPE_CHECKING:
    from wordtracker_nlp.ollama import OllamaEnrichment

TARGET_NOT_PRESENT = "TARGET_NOT_PRESENT"


@dataclass(frozen=True)
class ValidationIssue:
    field: str
    code: str
    message: str

    def to_dict(self) -> dict[str, str]:
        return asdict(self)


class EnrichmentValidationError(Exception):
    def __init__(self, issues: list[ValidationIssue]) -> None:
        self.issues = issues
        summary = "; ".join(f"{issue.field}:{issue.code}" for issue in issues)
        super().__init__(summary)

    def simple_example_only(self) -> bool:
        return len(self.issues) == 1 and self.issues[0].field == "simple_example"


def validate_simple_example_contains_target(
    request: EnrichRequest,
    enrichment: "OllamaEnrichment",
    analyzer: TextAnalyzer,
) -> None:
    validate_enrichment(request, enrichment, analyzer)


def validate_enrichment(
    request: EnrichRequest,
    enrichment: "OllamaEnrichment",
    analyzer: TextAnalyzer,
) -> None:
    target_lemma = request.lemma.strip().lower()
    example_tokens = analyzer.analyze(enrichment.simple_example).tokens
    example_lemmas = {token.lemma.lower() for token in example_tokens}

    if target_lemma not in example_lemmas:
        raise EnrichmentValidationError([
            ValidationIssue(
                field="simple_example",
                code=TARGET_NOT_PRESENT,
                message=f'Target lemma "{target_lemma}" is not present in simple_example.',
            )
        ])

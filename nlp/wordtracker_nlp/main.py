import logging

from fastapi import Depends, FastAPI, HTTPException

from wordtracker_nlp.analyzer import TextAnalyzer
from wordtracker_nlp.enrichment_validation import EnrichmentValidationError, ValidationIssue, validate_enrichment
from wordtracker_nlp.models import AnalyzeRequest, AnalyzeResponse, EnrichRequest, EnrichResponse, MAX_TEXT_BYTES
from wordtracker_nlp.ollama import OllamaEnrichment, PROMPT_VERSION, OllamaClient

analyzer = TextAnalyzer.from_model("en_core_web_sm")
app = FastAPI(title="WordTracker NLP")
logger = logging.getLogger(__name__)
MAX_REPAIR_ATTEMPTS = 1


def get_ollama_client() -> OllamaClient:
    return OllamaClient()


@app.get("/health")
def health() -> dict[str, str]:
    return {
        "status": "ok",
        "service": "wordtracker-nlp",
    }


@app.post("/analyze", response_model=AnalyzeResponse)
def analyze(request: AnalyzeRequest) -> AnalyzeResponse:
    if request.size_bytes > MAX_TEXT_BYTES:
        raise HTTPException(
            status_code=413,
            detail=f"Text payload is too large. Maximum size is {MAX_TEXT_BYTES} bytes.",
        )

    return analyzer.analyze(request.text)


@app.post("/enrich", response_model=EnrichResponse)
def enrich(request: EnrichRequest, ollama_client: OllamaClient = Depends(get_ollama_client)) -> EnrichResponse:
    if request.source_language != "en" or request.target_language != "pl":
        raise HTTPException(status_code=422, detail="Only English to Polish enrichment is supported.")

    try:
        enrichment = ollama_client.generate_enrichment(request)
    except TimeoutError as exc:
        raise HTTPException(status_code=504, detail=str(exc)) from exc
    except ConnectionError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc
    except RuntimeError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc
    except ValueError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc

    try:
        enrichment = validate_or_repair_enrichment(request, enrichment, ollama_client)
    except EnrichmentValidationError as exc:
        issue = exc.issues[0]
        raise HTTPException(
            status_code=502,
            detail=(
                f"Enrichment validation failed after {MAX_REPAIR_ATTEMPTS} repair attempts: "
                f"field={issue.field} code={issue.code}"
            ),
        ) from exc
    except TimeoutError as exc:
        raise HTTPException(status_code=504, detail=str(exc)) from exc
    except ConnectionError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc
    except RuntimeError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc
    except ValueError as exc:
        raise HTTPException(status_code=502, detail=str(exc)) from exc

    return EnrichResponse(
        translation_pl=enrichment.translation_pl,
        definition_en=enrichment.definition_en,
        meaning_in_context=enrichment.meaning_in_context,
        simple_example=enrichment.simple_example,
        cefr_level=enrichment.cefr_level,
        provider="ollama",
        model=ollama_client.config.model,
        prompt_version=PROMPT_VERSION,
    )


def validate_or_repair_enrichment(
    request: EnrichRequest,
    enrichment: OllamaEnrichment,
    ollama_client: OllamaClient,
) -> OllamaEnrichment:
    try:
        validate_enrichment(request, enrichment, analyzer)
        return enrichment
    except EnrichmentValidationError as exc:
        if not exc.simple_example_only():
            raise

        issue = exc.issues[0]
        last_error = exc
        logger.warning(
            "enrichment validation failed",
            extra={
                "field": issue.field,
                "code": issue.code,
                "phase": "initial_generation",
                "target": request.lemma,
            },
        )

    current_enrichment = enrichment
    for attempt in range(1, MAX_REPAIR_ATTEMPTS + 1):
        repaired_simple_example = ollama_client.repair_simple_example(
            request=request,
            enrichment=current_enrichment,
            validation_issue=issue,
        )
        current_enrichment = current_enrichment.model_copy(update={"simple_example": repaired_simple_example})

        try:
            validate_enrichment(request, current_enrichment, analyzer)
        except EnrichmentValidationError as repair_error:
            last_error = repair_error
            issue = repair_error.issues[0]
            logger.warning(
                "enrichment validation failed",
                extra={
                    "field": issue.field,
                    "code": issue.code,
                    "phase": "repair",
                    "repair_attempt": attempt,
                    "target": request.lemma,
                },
            )
            continue

        logger.info(
            "enrichment repair succeeded",
            extra={
                "field": "simple_example",
                "repair_attempt": attempt,
            },
        )
        return current_enrichment

    fallback_enrichment = enrichment.model_copy(update={"simple_example": request.context_sentence})
    try:
        validate_enrichment(request, fallback_enrichment, analyzer)
    except EnrichmentValidationError as fallback_error:
        last_error = fallback_error
        issue = fallback_error.issues[0]
        logger.warning(
            "enrichment validation failed",
            extra={
                "field": issue.field,
                "code": issue.code,
                "phase": "context_sentence_fallback",
                "target": request.lemma,
            },
        )
        raise last_error

    logger.info(
        "enrichment simple_example fell back to source context sentence",
        extra={
            "field": "simple_example",
        },
    )
    return fallback_enrichment

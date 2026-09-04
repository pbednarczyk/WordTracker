from fastapi import Depends, FastAPI, HTTPException

from wordtracker_nlp.analyzer import TextAnalyzer
from wordtracker_nlp.models import AnalyzeRequest, AnalyzeResponse, EnrichRequest, EnrichResponse, MAX_TEXT_BYTES
from wordtracker_nlp.ollama import PROMPT_VERSION, OllamaClient

analyzer = TextAnalyzer.from_model("en_core_web_sm")
app = FastAPI(title="WordTracker NLP")


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

    model = request.model or ollama_client.config.model
    return EnrichResponse(
        translation_pl=enrichment.translation_pl,
        definition_en=enrichment.definition_en,
        meaning_in_context=enrichment.meaning_in_context,
        simple_example=enrichment.simple_example,
        cefr_level=enrichment.cefr_level,
        provider="ollama",
        model=model,
        prompt_version=PROMPT_VERSION,
    )

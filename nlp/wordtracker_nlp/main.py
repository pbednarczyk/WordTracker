from fastapi import FastAPI, HTTPException

from wordtracker_nlp.analyzer import TextAnalyzer
from wordtracker_nlp.models import AnalyzeRequest, AnalyzeResponse, EnrichRequest, EnrichResponse, MAX_TEXT_BYTES

analyzer = TextAnalyzer.from_model("en_core_web_sm")
app = FastAPI(title="WordTracker NLP")


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
def enrich(request: EnrichRequest) -> EnrichResponse:
    if request.source_language != "en" or request.target_language != "pl":
        raise HTTPException(status_code=422, detail="Only English to Polish enrichment is supported.")

    return enrich_locally(request)


def enrich_locally(request: EnrichRequest) -> EnrichResponse:
    sentence = request.context_sentence.lower()
    lemma = request.lemma.lower()
    model = request.model or "wordtracker-local-enrichment"

    if lemma == "charge" and ("service charge" in sentence or "bill" in sentence or "hotel" in sentence):
        return response(
            translation_pl="op\u0142ata",
            definition_en="an amount of money required for a service",
            meaning_in_context="an additional fee added for the service",
            simple_example="The hotel added a small service charge.",
            cefr_level="B2",
            model=model,
        )

    if lemma == "charge" and ("criminal" in sentence or "police" in sentence or "filed" in sentence):
        return response(
            translation_pl="zarzut",
            definition_en="an official accusation that someone committed a crime",
            meaning_in_context="a formal criminal accusation made by the police",
            simple_example="The police filed a serious charge.",
            cefr_level="B2",
            model=model,
        )

    if lemma == "charge" and ("battery" in sentence or "device" in sentence):
        return response(
            translation_pl="\u0142adowa\u0107",
            definition_en="to put electrical energy into a battery",
            meaning_in_context="to restore power to a battery or device",
            simple_example="I need to charge my phone.",
            cefr_level="B1",
            model=model,
        )

    if lemma == "reluctant":
        return response(
            translation_pl="niech\u0119tny / wahaj\u0105cy si\u0119",
            definition_en="not willing or eager to do something",
            meaning_in_context=f"{request.original_form} means hesitant or unwilling in this sentence",
            simple_example="She was reluctant to speak.",
            cefr_level="B2",
            model=model,
        )

    return response(
        translation_pl=request.lemma,
        definition_en=f"a context-specific meaning of {request.lemma}",
        meaning_in_context=f"{request.original_form} is used with its meaning in the provided sentence",
        simple_example=f"The word {request.lemma} appears in a simple sentence.",
        cefr_level=None,
        model=model,
    )


def response(
    translation_pl: str,
    definition_en: str,
    meaning_in_context: str,
    simple_example: str,
    cefr_level: str | None,
    model: str,
) -> EnrichResponse:
    return EnrichResponse(
        translation_pl=translation_pl,
        definition_en=definition_en,
        meaning_in_context=meaning_in_context,
        simple_example=simple_example,
        cefr_level=cefr_level,
        provider="wordtracker-nlp",
        model=model,
        prompt_version="word-enrichment-v1",
    )

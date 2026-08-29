from fastapi import FastAPI, HTTPException

from wordtracker_nlp.analyzer import TextAnalyzer
from wordtracker_nlp.models import AnalyzeRequest, AnalyzeResponse, MAX_TEXT_BYTES

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

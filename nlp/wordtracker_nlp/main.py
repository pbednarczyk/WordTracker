from typing import Any

from fastapi import FastAPI

app = FastAPI(title="WordTracker NLP")


@app.get("/health")
def health() -> dict[str, Any]:
    return {
        "status": "ok",
        "service": "wordtracker-nlp",
    }

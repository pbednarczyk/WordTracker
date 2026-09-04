import httpx
import pytest
from fastapi.testclient import TestClient

from wordtracker_nlp.main import app, get_ollama_client
from wordtracker_nlp.models import EnrichRequest
from wordtracker_nlp.ollama import ENRICHMENT_FORMAT_SCHEMA, OllamaClient, OllamaConfig, OllamaEnrichment


class FakeOllamaClient:
    def __init__(self, result: OllamaEnrichment | None = None, error: Exception | None = None) -> None:
        self.config = OllamaConfig(model="fake-model")
        self.result = result or OllamaEnrichment(
            translation_pl="gotowość",
            definition_en="the quality of being ready to do something",
            meaning_in_context="willingness means readiness to grow and accept responsibility here",
            simple_example="Her willingness to help was appreciated.",
            cefr_level="B2",
        )
        self.error = error
        self.requests: list[EnrichRequest] = []

    def generate_enrichment(self, request: EnrichRequest) -> OllamaEnrichment:
        self.requests.append(request)
        if self.error is not None:
            raise self.error

        return self.result


@pytest.fixture(autouse=True)
def clear_dependency_overrides() -> None:
    app.dependency_overrides.clear()
    yield
    app.dependency_overrides.clear()


def enrich_request(model: str | None = None) -> dict[str, str]:
    payload = {
        "lemma": "willingness",
        "part_of_speech": "NOUN",
        "original_form": "willingness",
        "context_sentence": "His willingness to grow and accept responsibility impressed everyone.",
        "source_language": "en",
        "target_language": "pl",
    }
    if model is not None:
        payload["model"] = model

    return payload


def test_enrich_uses_ollama_dependency_and_returns_metadata() -> None:
    fake = FakeOllamaClient()
    app.dependency_overrides[get_ollama_client] = lambda: fake

    response = TestClient(app).post("/enrich", json=enrich_request())

    assert response.status_code == 200
    assert response.json() == {
        "translation_pl": "gotowość",
        "definition_en": "the quality of being ready to do something",
        "meaning_in_context": "willingness means readiness to grow and accept responsibility here",
        "simple_example": "Her willingness to help was appreciated.",
        "cefr_level": "B2",
        "provider": "ollama",
        "model": "fake-model",
        "prompt_version": "word-enrichment-v2",
    }
    assert fake.requests[0].lemma == "willingness"


def test_enrich_rejects_unsupported_language_pair() -> None:
    payload = enrich_request()
    payload["source_language"] = "de"

    response = TestClient(app).post("/enrich", json=payload)

    assert response.status_code == 422


def test_ollama_client_sends_model_prompt_and_structured_schema(monkeypatch: pytest.MonkeyPatch) -> None:
    captured: dict[str, object] = {}

    def fake_post(url: str, json: dict[str, object], timeout: float) -> httpx.Response:
        captured["url"] = url
        captured["json"] = json
        captured["timeout"] = timeout
        return httpx.Response(
            200,
            json={
                "response": (
                    '{"translation_pl":"gotowość","definition_en":"the quality of being ready",'
                    '"meaning_in_context":"readiness to accept responsibility",'
                    '"simple_example":"His willingness helped the team.","cefr_level":"B2"}'
                )
            },
        )

    monkeypatch.setattr(httpx, "post", fake_post)

    result = OllamaClient(
        OllamaConfig(base_url="http://ollama:11434", model="gemma3", timeout_seconds=91)
    ).generate_enrichment(EnrichRequest.model_validate(enrich_request()))

    assert result.translation_pl == "gotowość"
    assert captured["url"] == "http://ollama:11434/api/generate"
    assert captured["timeout"] == 91
    payload = captured["json"]
    assert isinstance(payload, dict)
    assert payload["model"] == "gemma3"
    assert payload["stream"] is False
    assert payload["format"] == ENRICHMENT_FORMAT_SCHEMA
    assert "SYSTEM INSTRUCTIONS" in str(payload["prompt"])
    assert "USER PROVIDED DATA JSON" in str(payload["prompt"])


def test_ollama_client_allows_request_model_override(monkeypatch: pytest.MonkeyPatch) -> None:
    captured: dict[str, object] = {}

    def fake_post(url: str, json: dict[str, object], timeout: float) -> httpx.Response:
        captured["json"] = json
        return httpx.Response(
            200,
            json={
                "response": (
                    '{"translation_pl":"gotowość","definition_en":"the quality of being ready",'
                    '"meaning_in_context":"readiness in this context",'
                    '"simple_example":"His willingness helped.","cefr_level":null}'
                )
            },
        )

    monkeypatch.setattr(httpx, "post", fake_post)

    OllamaClient(OllamaConfig(model="gemma3")).generate_enrichment(
        EnrichRequest.model_validate(enrich_request(model="llama3.1"))
    )

    payload = captured["json"]
    assert isinstance(payload, dict)
    assert payload["model"] == "llama3.1"


def test_ollama_client_rejects_invalid_json(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(httpx, "post", lambda *args, **kwargs: httpx.Response(200, json={"response": "not json"}))

    with pytest.raises(ValueError, match="invalid JSON"):
        OllamaClient().generate_enrichment(EnrichRequest.model_validate(enrich_request()))


def test_ollama_client_rejects_invalid_cefr(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(
        httpx,
        "post",
        lambda *args, **kwargs: httpx.Response(
            200,
            json={
                "response": (
                    '{"translation_pl":"gotowość","definition_en":"the quality of being ready",'
                    '"meaning_in_context":"readiness in this context",'
                    '"simple_example":"His willingness helped.","cefr_level":"B3"}'
                )
            },
        ),
    )

    with pytest.raises(ValueError, match="schema"):
        OllamaClient().generate_enrichment(EnrichRequest.model_validate(enrich_request()))


def test_ollama_client_handles_timeout(monkeypatch: pytest.MonkeyPatch) -> None:
    def fake_post(*args: object, **kwargs: object) -> httpx.Response:
        raise httpx.TimeoutException("timeout")

    monkeypatch.setattr(httpx, "post", fake_post)

    with pytest.raises(TimeoutError, match="timed out"):
        OllamaClient(OllamaConfig(timeout_seconds=60)).generate_enrichment(
            EnrichRequest.model_validate(enrich_request())
        )


def test_ollama_client_handles_http_error(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(httpx, "post", lambda *args, **kwargs: httpx.Response(404, json={"error": "model not found"}))

    with pytest.raises(RuntimeError, match="model not found"):
        OllamaClient().generate_enrichment(EnrichRequest.model_validate(enrich_request()))


def test_ollama_client_rejects_missing_model_response(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setattr(httpx, "post", lambda *args, **kwargs: httpx.Response(200, json={"done": True}))

    with pytest.raises(ValueError, match="missing"):
        OllamaClient().generate_enrichment(EnrichRequest.model_validate(enrich_request()))


def test_enrich_maps_timeout_to_504() -> None:
    app.dependency_overrides[get_ollama_client] = lambda: FakeOllamaClient(error=TimeoutError("Ollama timed out."))

    response = TestClient(app).post("/enrich", json=enrich_request())

    assert response.status_code == 504


def test_enrich_maps_connection_error_to_503() -> None:
    app.dependency_overrides[get_ollama_client] = lambda: FakeOllamaClient(error=ConnectionError("Ollama unavailable."))

    response = TestClient(app).post("/enrich", json=enrich_request())

    assert response.status_code == 503

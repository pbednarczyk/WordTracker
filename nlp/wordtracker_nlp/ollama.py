import json
import os
from typing import Any

import httpx
from pydantic import BaseModel

from wordtracker_nlp.llm import LlmGenerationClient


class OllamaConfig(BaseModel):
    base_url: str = "http://ollama:11434"
    model: str = "gemma3"
    timeout_seconds: float = 90.0

    @classmethod
    def from_env(cls) -> "OllamaConfig":
        return cls(
            base_url=os.getenv("OLLAMA_BASE_URL", "http://ollama:11434"),
            model=os.getenv("OLLAMA_MODEL", "gemma3"),
            timeout_seconds=float(os.getenv("OLLAMA_TIMEOUT_SECONDS", "90")),
        )


class OllamaClient(LlmGenerationClient):
    def __init__(self, config: OllamaConfig | None = None) -> None:
        self.config = config or OllamaConfig.from_env()
        self.last_payload: dict[str, Any] | None = None

    @property
    def provider(self) -> str:
        return "ollama"

    @property
    def model(self) -> str:
        return self.config.model

    def generate(self, prompt: str, format_schema: dict[str, Any], options: dict[str, Any]) -> str:
        payload = {
            "model": self.config.model,
            "prompt": prompt,
            "stream": False,
            "format": format_schema,
            "options": options,
        }
        self.last_payload = payload

        try:
            response = httpx.post(
                f"{self.config.base_url.rstrip('/')}/api/generate",
                json=payload,
                timeout=self.config.timeout_seconds,
            )
        except httpx.TimeoutException as exc:
            raise TimeoutError(f"Ollama request timed out after {self.config.timeout_seconds:g} seconds.") from exc
        except httpx.ConnectError as exc:
            raise ConnectionError("Ollama is unavailable or refused the connection.") from exc
        except httpx.HTTPError as exc:
            raise RuntimeError("Ollama request failed before a response was received.") from exc

        if response.status_code >= 400:
            detail = extract_ollama_error(response)
            raise RuntimeError(f"Ollama returned HTTP {response.status_code}: {detail}")

        try:
            ollama_payload = response.json()
        except json.JSONDecodeError as exc:
            raise ValueError("Ollama returned a non-JSON HTTP response.") from exc

        model_response = ollama_payload.get("response")
        if not isinstance(model_response, str):
            raise ValueError("Ollama response is missing the generated response field.")

        return model_response


def extract_ollama_error(response: httpx.Response) -> str:
    try:
        payload = response.json()
    except json.JSONDecodeError:
        return response.text[:500]

    error = payload.get("error")
    if isinstance(error, str) and error.strip() != "":
        return error

    return response.text[:500]

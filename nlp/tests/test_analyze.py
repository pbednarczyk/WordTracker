from pathlib import Path

from fastapi.testclient import TestClient

from wordtracker_nlp.main import app

client = TestClient(app)


def analyze(text: str) -> dict:
    response = client.post("/analyze", json={"text": text})

    assert response.status_code == 200

    return response.json()


def token_by_text(payload: dict, text: str) -> dict:
    for token in payload["tokens"]:
        if token["text"] == text:
            return token

    raise AssertionError(f"Token {text!r} not found.")


def test_analyze_lemmatizes_basic_verb_forms() -> None:
    payload = analyze("run runs running ran")
    lemmas = {token["text"]: token["lemma"] for token in payload["tokens"]}

    assert lemmas["run"] == "run"
    assert lemmas["runs"] == "run"
    assert lemmas["running"] == "run"
    assert lemmas["ran"] == "run"
    assert payload["unique_lemma_count"] == 1


def test_analyze_handles_irregular_forms() -> None:
    payload = analyze("children went better")
    lemmas = {token["text"]: token["lemma"] for token in payload["tokens"]}

    assert lemmas["children"] == "child"
    assert lemmas["went"] == "go"
    assert lemmas["better"] in {"well", "good", "better"}


def test_analyze_returns_pos_tags() -> None:
    payload = analyze("The children were running.")

    assert token_by_text(payload, "children")["pos"] == "NOUN"
    assert token_by_text(payload, "running")["pos"] == "VERB"
    assert token_by_text(payload, "were")["pos"] == "AUX"


def test_analyze_marks_proper_nouns() -> None:
    payload = analyze("Eiffel built a tower in Paris.")

    proper_nouns = {
        token["text"]
        for token in payload["tokens"]
        if token["is_proper_noun"] and token["pos"] == "PROPN"
    }

    assert {"Eiffel", "Paris"} <= proper_nouns


def test_analyze_omits_punctuation() -> None:
    payload = analyze("Hello, world!")
    token_texts = [token["text"] for token in payload["tokens"]]

    assert token_texts == ["Hello", "world"]
    assert payload["token_count"] == 4
    assert payload["word_count"] == 2


def test_analyze_omits_numbers() -> None:
    payload = analyze("The tower is 300 meters high.")
    token_texts = [token["text"] for token in payload["tokens"]]

    assert "300" not in token_texts
    assert {"The", "tower", "is", "meters", "high"} <= set(token_texts)


def test_analyze_assigns_sentences_to_tokens() -> None:
    payload = analyze("The tower is tall. Children went inside.")

    assert token_by_text(payload, "tower")["sentence"] == "The tower is tall."
    assert token_by_text(payload, "Children")["sentence"] == "Children went inside."


def test_analyze_returns_character_positions() -> None:
    payload = analyze("The children")

    assert token_by_text(payload, "The")["position"] == 0
    assert token_by_text(payload, "children")["position"] == 4


def test_analyze_rejects_blank_text() -> None:
    response = client.post("/analyze", json={"text": "   "})

    assert response.status_code == 422


def test_analyze_rejects_payloads_over_one_megabyte() -> None:
    response = client.post("/analyze", json={"text": "a" * 1_000_001})

    assert response.status_code == 413


def test_analyze_fixture_sample_text() -> None:
    text = Path("/srv/fixtures/sample.txt").read_text(encoding="utf-8")

    payload = analyze(text)
    lemmas = {token["lemma"] for token in payload["tokens"]}
    token_texts = {token["text"] for token in payload["tokens"]}

    assert payload["word_count"] > 0
    assert payload["unique_lemma_count"] > 0
    assert "statue" in lemmas
    assert "build" in lemmas
    assert "Eiffel" in token_texts

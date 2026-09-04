from fastapi.testclient import TestClient

from wordtracker_nlp.main import app

client = TestClient(app)


def test_enrich_reluctant_returns_structured_contextual_response() -> None:
    response = client.post(
        "/enrich",
        json={
            "lemma": "reluctant",
            "part_of_speech": "ADJ",
            "original_form": "reluctant",
            "context_sentence": "He was reluctant to enter the cave.",
            "source_language": "en",
            "target_language": "pl",
            "model": "wordtracker-local-enrichment",
        },
    )

    assert response.status_code == 200
    assert response.json() == {
        "translation_pl": "niech\u0119tny / wahaj\u0105cy si\u0119",
        "definition_en": "not willing or eager to do something",
        "meaning_in_context": "reluctant means hesitant or unwilling in this sentence",
        "simple_example": "She was reluctant to speak.",
        "cefr_level": "B2",
        "provider": "wordtracker-nlp",
        "model": "wordtracker-local-enrichment",
        "prompt_version": "word-enrichment-v1",
    }


def test_enrich_charge_uses_context() -> None:
    service_response = client.post(
        "/enrich",
        json={
            "lemma": "charge",
            "part_of_speech": "NOUN",
            "original_form": "charge",
            "context_sentence": "The hotel added a service charge.",
            "source_language": "en",
            "target_language": "pl",
        },
    )
    criminal_response = client.post(
        "/enrich",
        json={
            "lemma": "charge",
            "part_of_speech": "NOUN",
            "original_form": "charge",
            "context_sentence": "The police filed a criminal charge.",
            "source_language": "en",
            "target_language": "pl",
        },
    )

    assert service_response.status_code == 200
    assert criminal_response.status_code == 200
    assert service_response.json()["translation_pl"] == "op\u0142ata"
    assert criminal_response.json()["translation_pl"] == "zarzut"


def test_enrich_rejects_unsupported_language_pair() -> None:
    response = client.post(
        "/enrich",
        json={
            "lemma": "reluctant",
            "part_of_speech": "ADJ",
            "original_form": "reluctant",
            "context_sentence": "He was reluctant to enter the cave.",
            "source_language": "de",
            "target_language": "pl",
        },
    )

    assert response.status_code == 422

from wordtracker_nlp.analyzer import TextAnalyzer
from wordtracker_nlp.models import EnrichRequest
from wordtracker_nlp.ollama import OllamaEnrichment


def validate_simple_example_contains_target(
    request: EnrichRequest,
    enrichment: OllamaEnrichment,
    analyzer: TextAnalyzer,
) -> None:
    target_lemma = request.lemma.strip().lower()
    example_tokens = analyzer.analyze(enrichment.simple_example).tokens
    example_lemmas = {token.lemma.lower() for token in example_tokens}

    if target_lemma not in example_lemmas:
        raise ValueError("Ollama simple_example does not contain the target vocabulary item or its inflected form.")

import spacy
from spacy.language import Language

from wordtracker_nlp.models import AnalyzeResponse, AnalyzedToken


class TextAnalyzer:
    def __init__(self, nlp: Language) -> None:
        self._nlp = nlp

    @classmethod
    def from_model(cls, model_name: str) -> "TextAnalyzer":
        return cls(spacy.load(model_name))

    def analyze(self, text: str) -> AnalyzeResponse:
        doc = self._nlp(text)
        analyzed_tokens: list[AnalyzedToken] = []

        for token in doc:
            if not token.is_alpha:
                continue

            is_proper_noun = token.pos_ == "PROPN"
            lemma = self._normalize_lemma(token.lemma_ or token.text, is_proper_noun)
            sentence = token.sent.text.strip()

            analyzed_tokens.append(
                AnalyzedToken(
                    text=token.text,
                    lemma=lemma,
                    pos=token.pos_,
                    entity_type=self._entity_type(token),
                    sentence=sentence,
                    position=token.idx,
                    is_proper_noun=is_proper_noun,
                ),
            )

        unique_lemmas = {token.lemma for token in analyzed_tokens}

        return AnalyzeResponse(
            language="en",
            token_count=len(doc),
            word_count=len(analyzed_tokens),
            unique_lemma_count=len(unique_lemmas),
            tokens=analyzed_tokens,
        )

    def _normalize_lemma(self, lemma: str, is_proper_noun: bool) -> str:
        if is_proper_noun:
            return lemma

        return lemma.lower()

    def _entity_type(self, token) -> str | None:
        entity_type = token.ent_type_ or None
        if entity_type != "ORG":
            return entity_type

        span = next(
            (entity for entity in token.doc.ents if entity.start <= token.i < entity.end),
            None,
        )
        if span is None:
            return entity_type

        if len(span) <= 1:
            return entity_type

        organization_markers = {
            "agency",
            "association",
            "bank",
            "bureau",
            "company",
            "corp",
            "corporation",
            "council",
            "foundation",
            "group",
            "inc",
            "institute",
            "ltd",
            "ministry",
            "university",
        }
        has_organization_marker = any(part.text.lower().strip(".") in organization_markers for part in span)
        looks_like_title_phrase = all(part.is_alpha and part.text.istitle() for part in span)

        if looks_like_title_phrase and not has_organization_marker:
            return None

        return entity_type

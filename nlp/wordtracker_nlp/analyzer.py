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

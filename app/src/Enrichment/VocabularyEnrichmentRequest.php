<?php

declare(strict_types=1);

namespace App\Enrichment;

final readonly class VocabularyEnrichmentRequest
{
    public function __construct(
        public string $lemma,
        public string $partOfSpeech,
        public string $originalForm,
        public string $contextSentence,
        public string $sourceLanguage,
        public string $targetLanguage,
    ) {
    }
    public function toArray(): array
    {
        return ['lemma' => $this->lemma, 'part_of_speech' => $this->partOfSpeech,
            'original_form' => $this->originalForm, 'context_sentence' => $this->contextSentence,
            'source_language' => $this->sourceLanguage, 'target_language' => $this->targetLanguage];
    }
}

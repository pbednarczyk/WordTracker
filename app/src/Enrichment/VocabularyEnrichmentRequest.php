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
}

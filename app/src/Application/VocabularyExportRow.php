<?php

declare(strict_types=1);

namespace App\Application;

final readonly class VocabularyExportRow
{
    public function __construct(
        public string $lemma,
        public string $partOfSpeech,
        public string $status,
        public int $occurrences,
        public string $language,
        public string $translationPl,
        public string $definitionEn,
        public string $meaningInContext,
        public string $simpleExample,
        public string $cefrLevel,
        public string $firstContextSentence,
    ) {
    }
}

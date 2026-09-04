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
        public string $firstContextSentence,
    ) {
    }
}

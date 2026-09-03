<?php

declare(strict_types=1);

namespace App\Application;

final readonly class AnalyzePublicationResult
{
    public function __construct(
        public int $tokenCount,
        public int $wordCount,
        public int $vocabularyOccurrences,
        public int $uniqueVocabularyItems,
        public int $ignoredNamedEntities,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Nlp;

/**
 * @param list<AnalyzedToken> $tokens
 */
final readonly class TextAnalysis
{
    public function __construct(
        public string $language,
        public int $tokenCount,
        public int $wordCount,
        public int $uniqueLemmaCount,
        public array $tokens,
    ) {
    }
}

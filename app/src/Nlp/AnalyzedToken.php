<?php

declare(strict_types=1);

namespace App\Nlp;

final readonly class AnalyzedToken
{
    public function __construct(
        public string $text,
        public string $lemma,
        public string $pos,
        public ?string $entityType,
        public string $sentence,
        public int $position,
        public bool $isProperNoun,
    ) {
    }
}

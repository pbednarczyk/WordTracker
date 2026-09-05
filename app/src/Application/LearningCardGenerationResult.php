<?php

declare(strict_types=1);

namespace App\Application;

final readonly class LearningCardGenerationResult
{
    public function __construct(
        public int $created,
        public int $existing,
        public int $skippedWithoutEnrichment,
        public int $skippedCloze,
    ) {
    }

    public function merge(self $other): self
    {
        return new self(
            created: $this->created + $other->created,
            existing: $this->existing + $other->existing,
            skippedWithoutEnrichment: $this->skippedWithoutEnrichment + $other->skippedWithoutEnrichment,
            skippedCloze: $this->skippedCloze + $other->skippedCloze,
        );
    }
}

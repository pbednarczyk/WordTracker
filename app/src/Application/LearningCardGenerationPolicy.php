<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\PublicationVocabulary;
use App\Enum\LearningCardType;

final readonly class LearningCardGenerationPolicy
{
    /**
     * @return list<LearningCardType>
     */
    public function defaultTypesFor(PublicationVocabulary $publicationVocabulary): array
    {
        return [
            LearningCardType::FORWARD,
            LearningCardType::REVERSE,
            LearningCardType::CONTEXT_MEANING,
        ];
    }
}

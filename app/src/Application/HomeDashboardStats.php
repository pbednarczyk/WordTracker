<?php

declare(strict_types=1);

namespace App\Application;

final readonly class HomeDashboardStats
{
    public function __construct(
        public int $knownVocabularyItems,
        public int $totalVocabularyItems,
        public int $publications,
        public int $learningReviewsCompleted,
        public int $dueCardsNow,
        public int $newCardsAvailable,
        public int $reviewsCompletedToday,
        public int $scheduledCards,
        public int $totalVocabularyOccurrences,
        public int $activePublicationVocabularyContexts,
        public int $enrichedPublicationVocabularyContexts,
        public int $learningCards,
        public int $enrichedContextsWithLearningCards,
        public ?float $averageVocabularyCoverage,
        public ?float $averageTextCoverage,
    ) {
    }

    public function knownVocabularyPercent(): float
    {
        return $this->percentage($this->knownVocabularyItems, $this->totalVocabularyItems);
    }

    public function enrichmentPercent(): float
    {
        return $this->percentage($this->enrichedPublicationVocabularyContexts, $this->activePublicationVocabularyContexts);
    }

    public function learningCardCoveragePercent(): float
    {
        return $this->percentage($this->enrichedContextsWithLearningCards, $this->enrichedPublicationVocabularyContexts);
    }

    private function percentage(int $part, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($part / $total) * 100, 1);
    }
}

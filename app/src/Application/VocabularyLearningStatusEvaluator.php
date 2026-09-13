<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\VocabularyItem;
use App\Enum\KnowledgeStatusOrigin;
use App\Enum\ReviewRating;
use App\Enum\VocabularyStatus;
use Doctrine\DBAL\Connection;

final readonly class VocabularyLearningStatusEvaluator
{
    private const KNOWN_MIN_SUCCESSFUL_REVIEWS = 3;
    private const KNOWN_MIN_DISTINCT_REVIEW_DAYS = 2;
    private const KNOWN_MIN_FSRS_INTERVAL_DAYS = 7;

    private const MATURE_MIN_SUCCESSFUL_REVIEWS = 5;
    private const MATURE_MIN_DISTINCT_REVIEW_DAYS = 3;
    private const MATURE_MIN_FSRS_INTERVAL_DAYS = 30;

    public function __construct(private Connection $connection)
    {
    }

    public function evaluateAfterReview(VocabularyItem $item, ReviewRating $latestRating): ?VocabularyStatus
    {
        if ($item->isManualKnown()) {
            return null;
        }

        $current = $item->getStatus();
        if ($latestRating === ReviewRating::AGAIN) {
            if (
                $item->getKnowledgeStatusOrigin() === KnowledgeStatusOrigin::LEARNING
                && ($current === VocabularyStatus::KNOWN || $current === VocabularyStatus::MATURE)
            ) {
                return VocabularyStatus::LAPSED;
            }

            return null;
        }

        $evidence = $this->evidenceFor($item);
        if ($evidence['successfulReviews'] === 0) {
            return null;
        }

        if ($this->meetsMaturePolicy($evidence)) {
            return VocabularyStatus::MATURE;
        }

        if ($this->meetsKnownPolicy($evidence)) {
            return VocabularyStatus::KNOWN;
        }

        if ($current === VocabularyStatus::UNKNOWN || $current === VocabularyStatus::LAPSED) {
            return VocabularyStatus::LEARNING;
        }

        return null;
    }

    /**
     * Aggregates all active, non-deleted-context cards for the global vocabulary item.
     * Newly generated cards do not downgrade an already learned item; the strongest
     * active card can satisfy FSRS interval/stability evidence while every reviewed
     * active card contributes successful recall history.
     *
     * @return array{successfulReviews: int, distinctReviewDays: int, maxFsrsIntervalDays: float}
     */
    private function evidenceFor(VocabularyItem $item): array
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                WITH active_cards AS (
                    SELECT lc.id, lc.fsrs_scheduled_days, lc.fsrs_stability
                    FROM learning_card lc
                    LEFT JOIN publication_vocabulary pv ON pv.id = lc.publication_vocabulary_id
                    WHERE lc.vocabulary_item_id = :itemId
                      AND lc.is_active = true
                      AND (pv.id IS NULL OR pv.deleted_at IS NULL)
                ), last_lapse AS (
                    SELECT MAX(lr.reviewed_at) AS reviewed_at
                    FROM learning_review lr
                    INNER JOIN active_cards ac ON ac.id = lr.learning_card_id
                    WHERE lr.rating = 'AGAIN'
                )
                SELECT
                    COUNT(lr.id) FILTER (
                        WHERE lr.rating IN ('HARD', 'GOOD', 'EASY')
                          AND (last_lapse.reviewed_at IS NULL OR lr.reviewed_at > last_lapse.reviewed_at)
                    ) AS successful_reviews,
                    COUNT(DISTINCT DATE(lr.reviewed_at)) FILTER (
                        WHERE lr.rating IN ('HARD', 'GOOD', 'EASY')
                          AND (last_lapse.reviewed_at IS NULL OR lr.reviewed_at > last_lapse.reviewed_at)
                    ) AS distinct_review_days,
                    GREATEST(
                        COALESCE(MAX(ac.fsrs_scheduled_days), 0),
                        COALESCE(FLOOR(MAX(ac.fsrs_stability)), 0)
                    ) AS max_fsrs_interval_days
                FROM active_cards ac
                CROSS JOIN last_lapse
                LEFT JOIN learning_review lr ON lr.learning_card_id = ac.id
                SQL,
            ['itemId' => $item->getId()],
        );

        if ($row === false) {
            return ['successfulReviews' => 0, 'distinctReviewDays' => 0, 'maxFsrsIntervalDays' => 0.0];
        }

        return [
            'successfulReviews' => (int) $row['successful_reviews'],
            'distinctReviewDays' => (int) $row['distinct_review_days'],
            'maxFsrsIntervalDays' => (float) $row['max_fsrs_interval_days'],
        ];
    }

    /**
     * @param array{successfulReviews: int, distinctReviewDays: int, maxFsrsIntervalDays: float} $evidence
     */
    private function meetsKnownPolicy(array $evidence): bool
    {
        return $evidence['successfulReviews'] >= self::KNOWN_MIN_SUCCESSFUL_REVIEWS
            && $evidence['distinctReviewDays'] >= self::KNOWN_MIN_DISTINCT_REVIEW_DAYS
            && $evidence['maxFsrsIntervalDays'] >= self::KNOWN_MIN_FSRS_INTERVAL_DAYS;
    }

    /**
     * @param array{successfulReviews: int, distinctReviewDays: int, maxFsrsIntervalDays: float} $evidence
     */
    private function meetsMaturePolicy(array $evidence): bool
    {
        return $evidence['successfulReviews'] >= self::MATURE_MIN_SUCCESSFUL_REVIEWS
            && $evidence['distinctReviewDays'] >= self::MATURE_MIN_DISTINCT_REVIEW_DAYS
            && $evidence['maxFsrsIntervalDays'] >= self::MATURE_MIN_FSRS_INTERVAL_DAYS;
    }
}

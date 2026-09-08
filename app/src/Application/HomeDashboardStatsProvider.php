<?php

declare(strict_types=1);

namespace App\Application;

use App\Clock\ClockInterface;
use Doctrine\DBAL\Connection;

final readonly class HomeDashboardStatsProvider
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function getStats(): HomeDashboardStats
    {
        $now = $this->clock->now();
        [$dayStart, $dayEnd] = $this->currentDayBounds($now);

        return new HomeDashboardStats(
            knownVocabularyItems: $this->countKnownVocabularyItems(),
            totalVocabularyItems: $this->countTotalVocabularyItems(),
            publications: $this->countPublications(),
            learningReviewsCompleted: $this->countLearningReviewsCompleted(),
            dueCardsNow: $this->countDueCardsNow($now),
            newCardsAvailable: $this->countNewCardsAvailable(),
            reviewsCompletedToday: $this->countReviewsCompletedToday($dayStart, $dayEnd),
            scheduledCards: $this->countScheduledCards($now),
            totalVocabularyOccurrences: $this->countActiveVocabularyOccurrences(),
            activePublicationVocabularyContexts: $this->countActivePublicationVocabularyContexts(),
            enrichedPublicationVocabularyContexts: $this->countEnrichedPublicationVocabularyContexts(),
            learningCards: $this->countActiveLearningCards(),
            enrichedContextsWithLearningCards: $this->countEnrichedContextsWithActiveLearningCards(),
            averageVocabularyCoverage: $this->averageVocabularyCoverage(),
            averageTextCoverage: $this->averageTextCoverage(),
        );
    }

    private function countKnownVocabularyItems(): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(DISTINCT vi.id)
                FROM vocabulary_item vi
                INNER JOIN publication_vocabulary pv ON pv.vocabulary_item_id = vi.id
                WHERE pv.deleted_at IS NULL AND vi.status = 'KNOWN'
                SQL,
        );
    }

    private function countTotalVocabularyItems(): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(DISTINCT vi.id)
                FROM vocabulary_item vi
                INNER JOIN publication_vocabulary pv ON pv.vocabulary_item_id = vi.id
                WHERE pv.deleted_at IS NULL
                SQL,
        );
    }

    private function countPublications(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM publication');
    }

    private function countLearningReviewsCompleted(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM learning_review');
    }

    private function countDueCardsNow(\DateTimeImmutable $now): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(lc.id)
                FROM learning_card lc
                LEFT JOIN publication_vocabulary pv ON pv.id = lc.publication_vocabulary_id
                WHERE lc.is_active = true
                  AND lc.fsrs_state IS NOT NULL
                  AND lc.next_review_at <= :now
                  AND (pv.id IS NULL OR pv.deleted_at IS NULL)
                SQL,
            ['now' => $this->formatSqlTime($now)],
        );
    }

    private function countNewCardsAvailable(): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(lc.id)
                FROM learning_card lc
                LEFT JOIN publication_vocabulary pv ON pv.id = lc.publication_vocabulary_id
                WHERE lc.is_active = true
                  AND lc.fsrs_state IS NULL
                  AND (pv.id IS NULL OR pv.deleted_at IS NULL)
                SQL,
        );
    }

    private function countReviewsCompletedToday(\DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(lr.id)
                FROM learning_review lr
                WHERE lr.reviewed_at >= :dayStart AND lr.reviewed_at < :dayEnd
                SQL,
            [
                'dayStart' => $this->formatSqlTime($dayStart),
                'dayEnd' => $this->formatSqlTime($dayEnd),
            ],
        );
    }

    private function countScheduledCards(\DateTimeImmutable $now): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(lc.id)
                FROM learning_card lc
                LEFT JOIN publication_vocabulary pv ON pv.id = lc.publication_vocabulary_id
                WHERE lc.is_active = true
                  AND lc.fsrs_state IS NOT NULL
                  AND lc.next_review_at > :now
                  AND (pv.id IS NULL OR pv.deleted_at IS NULL)
                SQL,
            ['now' => $this->formatSqlTime($now)],
        );
    }

    private function countActiveVocabularyOccurrences(): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(vo.id)
                FROM vocabulary_occurrence vo
                INNER JOIN publication_vocabulary pv
                    ON pv.publication_id = vo.publication_id
                   AND pv.vocabulary_item_id = vo.vocabulary_item_id
                   AND pv.deleted_at IS NULL
                SQL,
        );
    }

    private function countActivePublicationVocabularyContexts(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM publication_vocabulary WHERE deleted_at IS NULL');
    }

    private function countEnrichedPublicationVocabularyContexts(): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(pv.id)
                FROM publication_vocabulary pv
                INNER JOIN publication_vocabulary_enrichment e ON e.publication_vocabulary_id = pv.id
                WHERE pv.deleted_at IS NULL
                SQL,
        );
    }

    private function countActiveLearningCards(): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(lc.id)
                FROM learning_card lc
                LEFT JOIN publication_vocabulary pv ON pv.id = lc.publication_vocabulary_id
                WHERE lc.is_active = true
                  AND (pv.id IS NULL OR pv.deleted_at IS NULL)
                SQL,
        );
    }

    private function countEnrichedContextsWithActiveLearningCards(): int
    {
        return (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(DISTINCT pv.id)
                FROM publication_vocabulary pv
                INNER JOIN publication_vocabulary_enrichment e ON e.publication_vocabulary_id = pv.id
                INNER JOIN learning_card lc ON lc.publication_vocabulary_id = pv.id AND lc.is_active = true
                WHERE pv.deleted_at IS NULL
                SQL,
        );
    }

    private function averageVocabularyCoverage(): ?float
    {
        return $this->floatOrNull($this->connection->fetchOne(
            <<<'SQL'
                SELECT ROUND((AVG(unique_known::float / NULLIF(unique_total, 0)) * 100)::numeric, 1)
                FROM (
                    SELECT pv.publication_id,
                           COUNT(pv.id) AS unique_total,
                           SUM(CASE WHEN vi.status = 'KNOWN' THEN 1 ELSE 0 END) AS unique_known
                    FROM publication_vocabulary pv
                    INNER JOIN vocabulary_item vi ON vi.id = pv.vocabulary_item_id
                    WHERE pv.deleted_at IS NULL
                    GROUP BY pv.publication_id
                ) stats
                SQL,
        ));
    }

    private function averageTextCoverage(): ?float
    {
        return $this->floatOrNull($this->connection->fetchOne(
            <<<'SQL'
                SELECT ROUND((AVG(occurrences_known::float / NULLIF(occurrences_total, 0)) * 100)::numeric, 1)
                FROM (
                    SELECT pv.publication_id,
                           SUM(pv.occurrences) AS occurrences_total,
                           SUM(CASE WHEN vi.status = 'KNOWN' THEN pv.occurrences ELSE 0 END) AS occurrences_known
                    FROM publication_vocabulary pv
                    INNER JOIN vocabulary_item vi ON vi.id = pv.vocabulary_item_id
                    WHERE pv.deleted_at IS NULL
                    GROUP BY pv.publication_id
                ) stats
                SQL,
        ));
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function currentDayBounds(\DateTimeImmutable $now): array
    {
        $timezone = new \DateTimeZone(date_default_timezone_get());
        $localNow = $now->setTimezone($timezone);
        $start = $localNow->setTime(0, 0);

        return [$start, $start->modify('+1 day')];
    }

    private function formatSqlTime(\DateTimeImmutable $time): string
    {
        return $time->format('Y-m-d H:i:s');
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === false || $value === null) {
            return null;
        }

        return (float) $value;
    }
}

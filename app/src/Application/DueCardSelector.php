<?php

declare(strict_types=1);

namespace App\Application;

use App\Clock\ClockInterface;
use App\Repository\LearningCardQuery;
use App\Repository\LearningCardRepository;
use App\Repository\LearningReviewRepository;

final readonly class DueCardSelector
{
    public function __construct(
        private LearningCardRepository $learningCardRepository,
        private LearningReviewRepository $learningReviewRepository,
        private ClockInterface $clock,
        private int $studyDailyReviewLimit,
        private int $studyDailyNewLimit,
    ) {
    }

    public function select(LearningCardQuery $query, int $sessionLimit): DueCardSelection
    {
        $now = $this->clock->now();
        [$dayStart, $dayEnd] = $this->currentDayBounds($now);
        $dailyReviewRemaining = max(0, $this->studyDailyReviewLimit - $this->learningReviewRepository->countDailyRepeatReviews($dayStart, $dayEnd));
        $dailyNewRemaining = max(0, $this->studyDailyNewLimit - $this->learningReviewRepository->countDailyNewIntroductions($dayStart, $dayEnd));
        $dueToday = $this->learningCardRepository->countActiveDue($query, $now);
        $newTotal = $this->learningCardRepository->countActiveNew($query);

        $dueLimit = min($sessionLimit, $dailyReviewRemaining);
        $dueCards = $this->learningCardRepository->findDueReviewCandidates($query, $now, $dueLimit);
        $remainingCapacity = max(0, $sessionLimit - count($dueCards));
        $newLimit = min($remainingCapacity, $dailyNewRemaining);
        $newCards = $this->learningCardRepository->findNewStudyCandidates($query, $newLimit);

        return new DueCardSelection(
            cards: [...$dueCards, ...$newCards],
            dueToday: $dueToday,
            newAvailable: min($newTotal, $dailyNewRemaining),
            dailyReviewRemaining: $dailyReviewRemaining,
            dailyNewRemaining: $dailyNewRemaining,
            nextReviewAt: $this->learningCardRepository->nextReviewAt($query, $now),
        );
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
}

<?php

declare(strict_types=1);

namespace App\Fsrs;

use App\Entity\LearningCard;
use App\Enum\ReviewRating;
use Scottlaurent\FSRS\Card;
use Scottlaurent\FSRS\State;

final readonly class LibraryFsrsScheduler implements FsrsSchedulerInterface
{
    public function __construct(private FsrsSchedulerConfig $config)
    {
    }

    public function scheduleReview(LearningCard $card, ReviewRating $rating, \DateTimeImmutable $reviewedAt): FsrsScheduleResult
    {
        $manager = $this->config->createManager();
        $result = $manager->reviewCard(
            $this->toLibraryCard($card),
            FsrsRatingMapper::toLibraryRating($rating),
            $this->toUtcDateTime($reviewedAt),
        );

        $updatedCard = $result['card'];
        \assert($updatedCard instanceof Card);

        return new FsrsScheduleResult(
            state: (int) $updatedCard->state,
            stability: (float) $updatedCard->stability,
            difficulty: (float) $updatedCard->difficulty,
            elapsedDays: (int) $updatedCard->elapsedDays,
            scheduledDays: (int) $updatedCard->scheduledDays,
            reps: (int) $updatedCard->reps,
            lapses: (int) $updatedCard->lapses,
            step: (int) $updatedCard->step,
            nextReviewAt: $this->toImmutableUtc($updatedCard->due),
            lastReviewAt: $this->toImmutableUtc($updatedCard->lastReview),
        );
    }

    private function toLibraryCard(LearningCard $card): Card
    {
        return new Card(
            due: $this->toUtcDateTime($card->getNextReviewAt() ?? $card->getCreatedAt()),
            stability: $card->getFsrsStability() ?? 0.0,
            difficulty: $card->getFsrsDifficulty() ?? 0.0,
            elapsedDays: $card->getFsrsElapsedDays() ?? 0,
            scheduledDays: $card->getFsrsScheduledDays() ?? 0,
            reps: $card->getFsrsReps() ?? 0,
            lapses: $card->getFsrsLapses() ?? 0,
            state: $card->getFsrsState() ?? State::NEW,
            step: $card->getFsrsStep() ?? 0,
            lastReview: $card->getLastReviewAt() !== null ? $this->toUtcDateTime($card->getLastReviewAt()) : null,
            cardId: $card->getId() !== null ? (string) $card->getId() : null,
        );
    }

    private function toUtcDateTime(\DateTimeImmutable $time): \DateTime
    {
        return (new \DateTime('@'.$time->getTimestamp()))->setTimezone(new \DateTimeZone('UTC'));
    }

    private function toImmutableUtc(?\DateTimeInterface $time): \DateTimeImmutable
    {
        if ($time === null) {
            throw new \RuntimeException('FSRS scheduler returned an incomplete card timestamp.');
        }

        return (new \DateTimeImmutable('@'.$time->getTimestamp()))->setTimezone(new \DateTimeZone('UTC'));
    }
}

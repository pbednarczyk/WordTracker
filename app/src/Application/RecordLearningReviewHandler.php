<?php

declare(strict_types=1);

namespace App\Application;

use App\Clock\ClockInterface;
use App\Entity\LearningCard;
use App\Entity\LearningReview;
use App\Enum\ReviewRating;
use App\Repository\LearningReviewRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RecordLearningReviewHandler
{
    private const MAX_RESPONSE_TIME_MS = 30 * 60 * 1000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LearningReviewRepository $learningReviewRepository,
        private ClockInterface $clock,
    ) {
    }

    public function record(
        LearningCard $card,
        ReviewRating $rating,
        string $studySessionId,
        int $studyPosition,
        \DateTimeImmutable $startedAt,
    ): LearningReview {
        $existing = $this->learningReviewRepository->findOneByPresentation($studySessionId, $studyPosition);
        if ($existing !== null) {
            return $existing;
        }

        $now = $this->clock->now();
        $review = new LearningReview(
            learningCard: $card,
            rating: $rating,
            reviewedAt: $now,
            responseTimeMs: $this->responseTimeMs($startedAt, $now),
            studySessionId: $studySessionId,
            studyPosition: $studyPosition,
        );

        $this->entityManager->persist($review);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            $this->entityManager->clear();
            $existing = $this->learningReviewRepository->findOneByPresentation($studySessionId, $studyPosition);
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }

        return $review;
    }

    private function responseTimeMs(\DateTimeImmutable $startedAt, \DateTimeImmutable $reviewedAt): int
    {
        $startedMs = ((int) $startedAt->format('U')) * 1000 + ((int) $startedAt->format('v'));
        $reviewedMs = ((int) $reviewedAt->format('U')) * 1000 + ((int) $reviewedAt->format('v'));

        return min(self::MAX_RESPONSE_TIME_MS, max(0, $reviewedMs - $startedMs));
    }
}

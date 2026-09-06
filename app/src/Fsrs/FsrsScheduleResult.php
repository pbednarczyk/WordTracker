<?php

declare(strict_types=1);

namespace App\Fsrs;

final readonly class FsrsScheduleResult
{
    public function __construct(
        public int $state,
        public float $stability,
        public float $difficulty,
        public int $elapsedDays,
        public int $scheduledDays,
        public int $reps,
        public int $lapses,
        public int $step,
        public \DateTimeImmutable $nextReviewAt,
        public \DateTimeImmutable $lastReviewAt,
    ) {
    }
}

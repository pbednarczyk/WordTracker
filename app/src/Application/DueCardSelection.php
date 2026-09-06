<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\LearningCard;

final readonly class DueCardSelection
{
    /**
     * @param list<LearningCard> $cards
     */
    public function __construct(
        public array $cards,
        public int $dueToday,
        public int $newAvailable,
        public int $dailyReviewRemaining,
        public int $dailyNewRemaining,
        public ?\DateTimeImmutable $nextReviewAt,
    ) {
    }
}

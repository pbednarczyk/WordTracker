<?php

declare(strict_types=1);

namespace App\Fsrs;

use App\Entity\LearningCard;
use App\Enum\ReviewRating;

interface FsrsSchedulerInterface
{
    public function scheduleReview(LearningCard $card, ReviewRating $rating, \DateTimeImmutable $reviewedAt): FsrsScheduleResult;
}

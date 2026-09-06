<?php

declare(strict_types=1);

namespace App\Fsrs;

use App\Enum\ReviewRating;
use Scottlaurent\FSRS\Rating;

final readonly class FsrsRatingMapper
{
    public static function toLibraryRating(ReviewRating $rating): int
    {
        return match ($rating) {
            ReviewRating::AGAIN => Rating::AGAIN,
            ReviewRating::HARD => Rating::HARD,
            ReviewRating::GOOD => Rating::GOOD,
            ReviewRating::EASY => Rating::EASY,
        };
    }
}

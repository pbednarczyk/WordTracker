<?php

declare(strict_types=1);

namespace App\Enum;

enum ReviewRating: string
{
    case AGAIN = 'AGAIN';
    case HARD = 'HARD';
    case GOOD = 'GOOD';
    case EASY = 'EASY';

    public function label(): string
    {
        return match ($this) {
            self::AGAIN => 'Again',
            self::HARD => 'Hard',
            self::GOOD => 'Good',
            self::EASY => 'Easy',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AGAIN => "Didn't remember",
            self::HARD => 'Remembered with difficulty',
            self::GOOD => 'Remembered',
            self::EASY => 'Immediate',
        };
    }
}

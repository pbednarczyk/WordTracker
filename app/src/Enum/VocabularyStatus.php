<?php

declare(strict_types=1);

namespace App\Enum;

enum VocabularyStatus: string
{
    case UNKNOWN = 'UNKNOWN';
    case LEARNING = 'LEARNING';
    case KNOWN = 'KNOWN';
    case MATURE = 'MATURE';
    case LAPSED = 'LAPSED';

    /**
     * @return list<self>
     */
    public static function knownForCoverage(): array
    {
        return [self::KNOWN, self::MATURE];
    }

    public function isKnownForCoverage(): bool
    {
        return match ($this) {
            self::KNOWN, self::MATURE => true,
            self::UNKNOWN, self::LEARNING, self::LAPSED => false,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Vocabulary;

final class PartOfSpeech
{
    /**
     * @var list<string>
     */
    public const VALUES = [
        'NOUN',
        'VERB',
        'ADJ',
        'ADV',
        'PROPN',
        'PRON',
        'DET',
        'ADP',
        'AUX',
        'CCONJ',
        'SCONJ',
        'NUM',
        'PART',
        'INTJ',
        'X',
        'UNKNOWN',
    ];

    public static function normalize(?string $value): ?string
    {
        $normalized = strtoupper(trim((string) $value));
        if ($normalized === '' || $normalized === 'ALL') {
            return null;
        }

        return in_array($normalized, self::VALUES, true) ? $normalized : null;
    }
}

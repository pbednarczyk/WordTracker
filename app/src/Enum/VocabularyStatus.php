<?php

declare(strict_types=1);

namespace App\Enum;

enum VocabularyStatus: string
{
    case UNKNOWN = 'UNKNOWN';
    case KNOWN = 'KNOWN';
}

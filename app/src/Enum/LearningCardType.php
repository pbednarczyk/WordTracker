<?php

declare(strict_types=1);

namespace App\Enum;

enum LearningCardType: string
{
    case FORWARD = 'FORWARD';
    case REVERSE = 'REVERSE';
    case CLOZE = 'CLOZE';
    case CONTEXT_MEANING = 'CONTEXT_MEANING';
}

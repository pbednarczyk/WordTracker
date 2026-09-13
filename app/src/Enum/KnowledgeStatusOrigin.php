<?php

declare(strict_types=1);

namespace App\Enum;

enum KnowledgeStatusOrigin: string
{
    case MANUAL = 'MANUAL';
    case LEARNING = 'LEARNING';
}

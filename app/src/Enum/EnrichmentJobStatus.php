<?php

declare(strict_types=1);

namespace App\Enum;

enum EnrichmentJobStatus: string
{
    case QUEUED = 'QUEUED';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
}

<?php

declare(strict_types=1);

namespace App\Enum;

enum EnrichmentJobStage: string
{
    case GENERATE = 'GENERATE';
    case REPAIR = 'REPAIR';
}

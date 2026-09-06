<?php

declare(strict_types=1);

namespace App\Fsrs;

use Scottlaurent\FSRS\Manager;

final readonly class FsrsSchedulerConfig
{
    public function createManager(): Manager
    {
        return new Manager();
    }
}

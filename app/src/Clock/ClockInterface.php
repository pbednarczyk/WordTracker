<?php

declare(strict_types=1);

namespace App\Clock;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}

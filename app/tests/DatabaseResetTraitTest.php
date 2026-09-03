<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

final class DatabaseResetTraitTest extends TestCase
{
    public function testGuardRejectsDevelopmentDatabaseBeforeResetSqlCanRun(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('REFUSING TO RESET NON-TEST DATABASE "wordtracker".');

        $guard = new class {
            use DatabaseResetTrait;
        };
        $guard::assertSafeTestDatabaseName('wordtracker');
    }
}

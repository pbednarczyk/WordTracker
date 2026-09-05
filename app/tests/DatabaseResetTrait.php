<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\DBAL\Connection;

trait DatabaseResetTrait
{
    private function resetDatabase(): void
    {
        $connection = $this->entityManager->getConnection();
        self::assertSafeTestDatabase($connection);

        $connection->executeStatement(
            'TRUNCATE TABLE learning_card, publication_vocabulary_enrichment, publication_vocabulary, vocabulary_occurrence, vocabulary_item, publication RESTART IDENTITY CASCADE',
        );
    }

    public static function assertSafeTestDatabase(Connection $connection): void
    {
        self::assertSafeTestDatabaseName($connection->getDatabase());
    }

    public static function assertSafeTestDatabaseName(string $databaseName): void
    {
        if ($databaseName !== 'wordtracker_test') {
            throw new \RuntimeException(sprintf('REFUSING TO RESET NON-TEST DATABASE "%s".', $databaseName));
        }
    }
}

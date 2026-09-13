<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add vocabulary learning lifecycle origin.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vocabulary_item ADD knowledge_status_origin VARCHAR(16) DEFAULT NULL');
        $this->addSql("UPDATE vocabulary_item SET knowledge_status_origin = 'MANUAL' WHERE status = 'KNOWN'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vocabulary_item DROP knowledge_status_origin');
    }
}

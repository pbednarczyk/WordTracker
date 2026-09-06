<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add publication-specific soft deletion for publication vocabulary rows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE publication_vocabulary ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_publication_vocabulary_publication_deleted ON publication_vocabulary (publication_id, deleted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_publication_vocabulary_publication_deleted');
        $this->addSql('ALTER TABLE publication_vocabulary DROP deleted_at');
    }
}

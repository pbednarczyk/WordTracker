<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add FSRS scheduling state to learning cards.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE learning_card ADD fsrs_state INT DEFAULT NULL');
        $this->addSql('ALTER TABLE learning_card ADD fsrs_stability DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE learning_card ADD fsrs_difficulty DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE learning_card ADD fsrs_elapsed_days INT DEFAULT NULL');
        $this->addSql('ALTER TABLE learning_card ADD fsrs_scheduled_days INT DEFAULT NULL');
        $this->addSql('ALTER TABLE learning_card ADD fsrs_reps INT DEFAULT NULL');
        $this->addSql('ALTER TABLE learning_card ADD fsrs_lapses INT DEFAULT NULL');
        $this->addSql('ALTER TABLE learning_card ADD fsrs_step INT DEFAULT NULL');
        $this->addSql('ALTER TABLE learning_card ADD next_review_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE learning_card ADD last_review_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_learning_card_active_next_review ON learning_card (is_active, next_review_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_learning_card_active_next_review');
        $this->addSql('ALTER TABLE learning_card DROP fsrs_state');
        $this->addSql('ALTER TABLE learning_card DROP fsrs_stability');
        $this->addSql('ALTER TABLE learning_card DROP fsrs_difficulty');
        $this->addSql('ALTER TABLE learning_card DROP fsrs_elapsed_days');
        $this->addSql('ALTER TABLE learning_card DROP fsrs_scheduled_days');
        $this->addSql('ALTER TABLE learning_card DROP fsrs_reps');
        $this->addSql('ALTER TABLE learning_card DROP fsrs_lapses');
        $this->addSql('ALTER TABLE learning_card DROP fsrs_step');
        $this->addSql('ALTER TABLE learning_card DROP next_review_at');
        $this->addSql('ALTER TABLE learning_card DROP last_review_at');
    }
}

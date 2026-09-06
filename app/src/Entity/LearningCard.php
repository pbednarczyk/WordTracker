<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\LearningCardType;
use App\Repository\LearningCardRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LearningCardRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_learning_card_publication_vocabulary_type', columns: ['publication_vocabulary_id', 'type'])]
#[ORM\Index(name: 'idx_learning_card_vocabulary_item', columns: ['vocabulary_item_id'])]
#[ORM\Index(name: 'idx_learning_card_publication_vocabulary', columns: ['publication_vocabulary_id'])]
#[ORM\Index(name: 'IDX_5D70B629F0E11780', columns: ['publication_vocabulary_enrichment_id'])]
#[ORM\Index(name: 'idx_learning_card_type', columns: ['type'])]
#[ORM\Index(name: 'idx_learning_card_active', columns: ['is_active'])]
#[ORM\Index(name: 'idx_learning_card_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_learning_card_active_next_review', columns: ['is_active', 'next_review_at'])]
class LearningCard
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VocabularyItem::class)]
    #[ORM\JoinColumn(nullable: false)]
    private VocabularyItem $vocabularyItem;

    #[ORM\ManyToOne(targetEntity: PublicationVocabulary::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?PublicationVocabulary $publicationVocabulary;

    #[ORM\ManyToOne(targetEntity: PublicationVocabularyEnrichment::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?PublicationVocabularyEnrichment $publicationVocabularyEnrichment;

    #[ORM\Column(length: 32, enumType: LearningCardType::class)]
    private LearningCardType $type;

    #[ORM\Column(type: Types::TEXT)]
    private string $front;

    #[ORM\Column(type: Types::TEXT)]
    private string $back;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contextSentence;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $clozeSentence;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?int $fsrsState = null;

    #[ORM\Column(nullable: true)]
    private ?float $fsrsStability = null;

    #[ORM\Column(nullable: true)]
    private ?float $fsrsDifficulty = null;

    #[ORM\Column(nullable: true)]
    private ?int $fsrsElapsedDays = null;

    #[ORM\Column(nullable: true)]
    private ?int $fsrsScheduledDays = null;

    #[ORM\Column(nullable: true)]
    private ?int $fsrsReps = null;

    #[ORM\Column(nullable: true)]
    private ?int $fsrsLapses = null;

    #[ORM\Column(nullable: true)]
    private ?int $fsrsStep = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $nextReviewAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastReviewAt = null;

    public function __construct(
        VocabularyItem $vocabularyItem,
        ?PublicationVocabulary $publicationVocabulary,
        ?PublicationVocabularyEnrichment $publicationVocabularyEnrichment,
        LearningCardType $type,
        string $front,
        string $back,
        ?string $contextSentence = null,
        ?string $clozeSentence = null,
    ) {
        $this->vocabularyItem = $vocabularyItem;
        $this->publicationVocabulary = $publicationVocabulary;
        $this->publicationVocabularyEnrichment = $publicationVocabularyEnrichment;
        $this->type = $type;
        $this->front = $front;
        $this->back = $back;
        $this->contextSentence = $contextSentence;
        $this->clozeSentence = $clozeSentence;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVocabularyItem(): VocabularyItem
    {
        return $this->vocabularyItem;
    }

    public function getPublicationVocabulary(): ?PublicationVocabulary
    {
        return $this->publicationVocabulary;
    }

    public function getPublicationVocabularyEnrichment(): ?PublicationVocabularyEnrichment
    {
        return $this->publicationVocabularyEnrichment;
    }

    public function getType(): LearningCardType
    {
        return $this->type;
    }

    public function getFront(): string
    {
        return $this->front;
    }

    public function getBack(): string
    {
        return $this->back;
    }

    public function getContextSentence(): ?string
    {
        return $this->contextSentence;
    }

    public function getClozeSentence(): ?string
    {
        return $this->clozeSentence;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function activate(): void
    {
        $this->isActive = true;
        $this->touch();
    }

    public function deactivate(): void
    {
        $this->isActive = false;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getFsrsState(): ?int
    {
        return $this->fsrsState;
    }

    public function getFsrsStability(): ?float
    {
        return $this->fsrsStability;
    }

    public function getFsrsDifficulty(): ?float
    {
        return $this->fsrsDifficulty;
    }

    public function getFsrsElapsedDays(): ?int
    {
        return $this->fsrsElapsedDays;
    }

    public function getFsrsScheduledDays(): ?int
    {
        return $this->fsrsScheduledDays;
    }

    public function getFsrsReps(): ?int
    {
        return $this->fsrsReps;
    }

    public function getFsrsLapses(): ?int
    {
        return $this->fsrsLapses;
    }

    public function getFsrsStep(): ?int
    {
        return $this->fsrsStep;
    }

    public function getNextReviewAt(): ?\DateTimeImmutable
    {
        return $this->nextReviewAt;
    }

    public function getLastReviewAt(): ?\DateTimeImmutable
    {
        return $this->lastReviewAt;
    }

    public function hasFsrsState(): bool
    {
        return $this->fsrsState !== null;
    }

    public function schedulingStatus(\DateTimeImmutable $now): string
    {
        if (!$this->hasFsrsState()) {
            return 'NEW';
        }

        if ($this->nextReviewAt !== null && $this->nextReviewAt <= $now) {
            return 'DUE';
        }

        return 'SCHEDULED';
    }

    public function applyFsrsState(
        int $state,
        float $stability,
        float $difficulty,
        int $elapsedDays,
        int $scheduledDays,
        int $reps,
        int $lapses,
        int $step,
        \DateTimeImmutable $nextReviewAt,
        \DateTimeImmutable $lastReviewAt,
    ): void {
        $this->fsrsState = $state;
        $this->fsrsStability = $stability;
        $this->fsrsDifficulty = $difficulty;
        $this->fsrsElapsedDays = $elapsedDays;
        $this->fsrsScheduledDays = $scheduledDays;
        $this->fsrsReps = $reps;
        $this->fsrsLapses = $lapses;
        $this->fsrsStep = $step;
        $this->nextReviewAt = $nextReviewAt;
        $this->lastReviewAt = $lastReviewAt;
        $this->touch();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

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

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

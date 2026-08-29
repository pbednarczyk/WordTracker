<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VocabularyOccurrenceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VocabularyOccurrenceRepository::class)]
#[ORM\Index(name: 'idx_vocabulary_occurrence_publication', columns: ['publication_id'])]
#[ORM\Index(name: 'idx_vocabulary_occurrence_vocabulary_item', columns: ['vocabulary_item_id'])]
class VocabularyOccurrence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Publication::class, inversedBy: 'occurrences')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Publication $publication;

    #[ORM\ManyToOne(targetEntity: VocabularyItem::class, inversedBy: 'occurrences')]
    #[ORM\JoinColumn(nullable: false)]
    private VocabularyItem $vocabularyItem;

    #[ORM\Column(length: 255)]
    private string $originalForm;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $sentence;

    #[ORM\Column(nullable: true)]
    private ?int $position;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Publication $publication,
        VocabularyItem $vocabularyItem,
        string $originalForm,
        ?string $sentence = null,
        ?int $position = null,
    ) {
        $this->publication = $publication;
        $this->vocabularyItem = $vocabularyItem;
        $this->originalForm = $originalForm;
        $this->sentence = $sentence;
        $this->position = $position;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublication(): Publication
    {
        return $this->publication;
    }

    public function getVocabularyItem(): VocabularyItem
    {
        return $this->vocabularyItem;
    }

    public function getOriginalForm(): string
    {
        return $this->originalForm;
    }

    public function getSentence(): ?string
    {
        return $this->sentence;
    }

    public function getPosition(): ?int
    {
        return $this->position;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

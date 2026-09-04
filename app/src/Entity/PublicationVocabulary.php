<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PublicationVocabularyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PublicationVocabularyRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_publication_vocabulary_identity', columns: ['publication_id', 'vocabulary_item_id'])]
#[ORM\Index(name: 'idx_publication_vocabulary_publication', columns: ['publication_id'])]
#[ORM\Index(name: 'idx_publication_vocabulary_vocabulary_item', columns: ['vocabulary_item_id'])]
class PublicationVocabulary
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Publication::class, inversedBy: 'publicationVocabulary')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Publication $publication;

    #[ORM\ManyToOne(targetEntity: VocabularyItem::class, inversedBy: 'publicationVocabulary')]
    #[ORM\JoinColumn(nullable: false)]
    private VocabularyItem $vocabularyItem;

    #[ORM\Column]
    private int $occurrences;

    #[ORM\OneToOne(mappedBy: 'publicationVocabulary', targetEntity: PublicationVocabularyEnrichment::class, cascade: ['persist'], orphanRemoval: true)]
    private ?PublicationVocabularyEnrichment $enrichment = null;

    public function __construct(
        Publication $publication,
        VocabularyItem $vocabularyItem,
        int $occurrences,
    ) {
        if ($occurrences < 0) {
            throw new \InvalidArgumentException('Occurrences cannot be negative.');
        }

        $this->publication = $publication;
        $this->vocabularyItem = $vocabularyItem;
        $this->occurrences = $occurrences;
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

    public function getOccurrences(): int
    {
        return $this->occurrences;
    }

    public function updateOccurrences(int $occurrences): void
    {
        if ($occurrences < 0) {
            throw new \InvalidArgumentException('Occurrences cannot be negative.');
        }

        $this->occurrences = $occurrences;
    }

    public function getEnrichment(): ?PublicationVocabularyEnrichment
    {
        return $this->enrichment;
    }

    public function setEnrichment(?PublicationVocabularyEnrichment $enrichment): void
    {
        $this->enrichment = $enrichment;
    }
}

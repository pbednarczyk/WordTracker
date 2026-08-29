<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PublicationType;
use App\Repository\PublicationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PublicationRepository::class)]
class Publication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $author;

    #[ORM\Column(length: 20, enumType: PublicationType::class)]
    private PublicationType $type;

    #[ORM\Column(length: 8)]
    private string $language;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rawText;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $analyzedAt = null;

    /**
     * @var Collection<int, VocabularyOccurrence>
     */
    #[ORM\OneToMany(mappedBy: 'publication', targetEntity: VocabularyOccurrence::class, orphanRemoval: true)]
    private Collection $occurrences;

    /**
     * @var Collection<int, PublicationVocabulary>
     */
    #[ORM\OneToMany(mappedBy: 'publication', targetEntity: PublicationVocabulary::class, orphanRemoval: true)]
    private Collection $publicationVocabulary;

    public function __construct(
        string $title,
        PublicationType $type,
        string $language = 'en',
        ?string $author = null,
        ?string $rawText = null,
    ) {
        $this->title = $title;
        $this->type = $type;
        $this->language = $language;
        $this->author = $author;
        $this->rawText = $rawText;
        $this->createdAt = new \DateTimeImmutable();
        $this->occurrences = new ArrayCollection();
        $this->publicationVocabulary = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function getType(): PublicationType
    {
        return $this->type;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getRawText(): ?string
    {
        return $this->rawText;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAnalyzedAt(): ?\DateTimeImmutable
    {
        return $this->analyzedAt;
    }

    public function markAnalyzed(?\DateTimeImmutable $analyzedAt = null): void
    {
        $this->analyzedAt = $analyzedAt ?? new \DateTimeImmutable();
    }

    /**
     * @return Collection<int, VocabularyOccurrence>
     */
    public function getOccurrences(): Collection
    {
        return $this->occurrences;
    }

    /**
     * @return Collection<int, PublicationVocabulary>
     */
    public function getPublicationVocabulary(): Collection
    {
        return $this->publicationVocabulary;
    }
}

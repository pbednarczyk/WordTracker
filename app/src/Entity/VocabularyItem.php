<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VocabularyStatus;
use App\Repository\VocabularyItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VocabularyItemRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_vocabulary_item_identity', columns: ['language', 'lemma', 'part_of_speech'])]
class VocabularyItem
{
    public const UNKNOWN_PART_OF_SPEECH = 'UNKNOWN';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 8)]
    private string $language;

    #[ORM\Column(length: 255)]
    private string $lemma;

    #[ORM\Column(length: 32)]
    private string $partOfSpeech;

    #[ORM\Column(length: 16, enumType: VocabularyStatus::class)]
    private VocabularyStatus $status = VocabularyStatus::UNKNOWN;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, VocabularyOccurrence>
     */
    #[ORM\OneToMany(mappedBy: 'vocabularyItem', targetEntity: VocabularyOccurrence::class)]
    private Collection $occurrences;

    /**
     * @var Collection<int, PublicationVocabulary>
     */
    #[ORM\OneToMany(mappedBy: 'vocabularyItem', targetEntity: PublicationVocabulary::class)]
    private Collection $publicationVocabulary;

    public function __construct(
        string $language,
        string $lemma,
        string $partOfSpeech = self::UNKNOWN_PART_OF_SPEECH,
    ) {
        $this->language = $language;
        $this->lemma = $lemma;
        $this->partOfSpeech = $partOfSpeech;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->occurrences = new ArrayCollection();
        $this->publicationVocabulary = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getLemma(): string
    {
        return $this->lemma;
    }

    public function getPartOfSpeech(): string
    {
        return $this->partOfSpeech;
    }

    public function getStatus(): VocabularyStatus
    {
        return $this->status;
    }

    public function markKnown(): void
    {
        $this->status = VocabularyStatus::KNOWN;
        $this->touch();
    }

    public function markUnknown(): void
    {
        $this->status = VocabularyStatus::UNKNOWN;
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

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PublicationVocabularyEnrichmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PublicationVocabularyEnrichmentRepository::class)]
#[ORM\HasLifecycleCallbacks]
class PublicationVocabularyEnrichment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'enrichment', targetEntity: PublicationVocabulary::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PublicationVocabulary $publicationVocabulary;

    #[ORM\Column(type: Types::TEXT)]
    private string $translationPl;

    #[ORM\Column(type: Types::TEXT)]
    private string $definitionEn;

    #[ORM\Column(type: Types::TEXT)]
    private string $meaningInContext;

    #[ORM\Column(type: Types::TEXT)]
    private string $simpleExample;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $cefrLevel;

    #[ORM\Column(type: Types::TEXT)]
    private string $sourceSentence;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $provider;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $model;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $promptVersion;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        PublicationVocabulary $publicationVocabulary,
        string $translationPl,
        string $definitionEn,
        string $meaningInContext,
        string $simpleExample,
        ?string $cefrLevel,
        string $sourceSentence,
        ?string $provider = null,
        ?string $model = null,
        ?string $promptVersion = null,
    ) {
        $this->publicationVocabulary = $publicationVocabulary;
        $publicationVocabulary->setEnrichment($this);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->update(
            translationPl: $translationPl,
            definitionEn: $definitionEn,
            meaningInContext: $meaningInContext,
            simpleExample: $simpleExample,
            cefrLevel: $cefrLevel,
            sourceSentence: $sourceSentence,
            provider: $provider,
            model: $model,
            promptVersion: $promptVersion,
        );
    }

    public function update(
        string $translationPl,
        string $definitionEn,
        string $meaningInContext,
        string $simpleExample,
        ?string $cefrLevel,
        string $sourceSentence,
        ?string $provider,
        ?string $model,
        ?string $promptVersion,
    ): void {
        $this->translationPl = $translationPl;
        $this->definitionEn = $definitionEn;
        $this->meaningInContext = $meaningInContext;
        $this->simpleExample = $simpleExample;
        $this->cefrLevel = $cefrLevel;
        $this->sourceSentence = $sourceSentence;
        $this->provider = $provider;
        $this->model = $model;
        $this->promptVersion = $promptVersion;
        $this->touch();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicationVocabulary(): PublicationVocabulary
    {
        return $this->publicationVocabulary;
    }

    public function getTranslationPl(): string
    {
        return $this->translationPl;
    }

    public function getDefinitionEn(): string
    {
        return $this->definitionEn;
    }

    public function getMeaningInContext(): string
    {
        return $this->meaningInContext;
    }

    public function getSimpleExample(): string
    {
        return $this->simpleExample;
    }

    public function getCefrLevel(): ?string
    {
        return $this->cefrLevel;
    }

    public function getSourceSentence(): string
    {
        return $this->sourceSentence;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getPromptVersion(): ?string
    {
        return $this->promptVersion;
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

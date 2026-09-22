<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EnrichmentJobStage;
use App\Enum\EnrichmentJobStatus;
use App\Repository\PublicationVocabularyEnrichmentJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PublicationVocabularyEnrichmentJobRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_enrichment_job_uuid', columns: ['active_llm_job_id'])]
#[ORM\Index(name: 'idx_enrichment_job_context', columns: ['publication_vocabulary_id', 'id'])]
class PublicationVocabularyEnrichmentJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PublicationVocabulary::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PublicationVocabulary $publicationVocabulary;

    #[ORM\Column]
    private int $requestRevision;

    #[ORM\Column(length: 16, enumType: EnrichmentJobStatus::class)]
    private EnrichmentJobStatus $status = EnrichmentJobStatus::QUEUED;

    #[ORM\Column(length: 16, enumType: EnrichmentJobStage::class)]
    private EnrichmentJobStage $stage = EnrichmentJobStage::GENERATE;

    #[ORM\Column(length: 36, nullable: true)]
    private ?string $activeLlmJobId = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $provider = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $promptVersion = null;

    #[ORM\Column(type: Types::JSON)]
    private array $requestSnapshot;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $candidate = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $failure = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(PublicationVocabulary $context, int $revision, array $snapshot)
    {
        $this->publicationVocabulary = $context;
        $this->requestRevision = $revision;
        $this->requestSnapshot = $snapshot;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getPublicationVocabulary(): PublicationVocabulary { return $this->publicationVocabulary; }
    public function getRequestRevision(): int { return $this->requestRevision; }
    public function getStatus(): EnrichmentJobStatus { return $this->status; }
    public function getStage(): EnrichmentJobStage { return $this->stage; }
    public function getActiveLlmJobId(): ?string { return $this->activeLlmJobId; }
    public function getModel(): ?string { return $this->model; }
    public function getProvider(): ?string { return $this->provider; }
    public function getPromptVersion(): ?string { return $this->promptVersion; }
    public function getRequestSnapshot(): array { return $this->requestSnapshot; }
    public function getCandidate(): ?array { return $this->candidate; }
    public function getFailure(): ?string { return $this->failure; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function isPending(): bool { return in_array($this->status, [EnrichmentJobStatus::QUEUED, EnrichmentJobStatus::PROCESSING], true); }

    public function prepare(array $prepared): void
    {
        $this->activeLlmJobId = $prepared['job']['job_id'];
        $this->model = $prepared['job']['model'];
        $this->provider = $prepared['provider'];
        $this->promptVersion = $prepared['prompt_version'];
        $this->touch();
    }

    public function repair(array $candidate, string $uuid): void
    {
        $this->stage = EnrichmentJobStage::REPAIR;
        $this->status = EnrichmentJobStatus::QUEUED;
        $this->candidate = $candidate;
        $this->activeLlmJobId = $uuid;
        $this->touch();
    }

    public function processing(): void { $this->status = EnrichmentJobStatus::PROCESSING; $this->touch(); }
    public function complete(): void { $this->status = EnrichmentJobStatus::COMPLETED; $this->candidate = null; $this->touch(); }
    public function fail(string $reason): void { $this->status = EnrichmentJobStatus::FAILED; $this->failure = $reason; $this->touch(); }
    private function touch(): void { $this->updatedAt = new \DateTimeImmutable(); }
}

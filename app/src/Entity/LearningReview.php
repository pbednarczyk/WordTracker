<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReviewRating;
use App\Repository\LearningReviewRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LearningReviewRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_learning_review_study_presentation', columns: ['study_session_id', 'study_position'])]
#[ORM\Index(name: 'idx_learning_review_card', columns: ['learning_card_id'])]
#[ORM\Index(name: 'idx_learning_review_reviewed_at', columns: ['reviewed_at'])]
#[ORM\Index(name: 'idx_learning_review_rating', columns: ['rating'])]
#[ORM\Index(name: 'idx_learning_review_session', columns: ['study_session_id'])]
class LearningReview
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: LearningCard::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?LearningCard $learningCard;

    #[ORM\Column(length: 16, enumType: ReviewRating::class)]
    private ReviewRating $rating;

    #[ORM\Column]
    private \DateTimeImmutable $reviewedAt;

    #[ORM\Column]
    private int $responseTimeMs;

    #[ORM\Column(length: 36)]
    private string $studySessionId;

    #[ORM\Column]
    private int $studyPosition;

    public function __construct(
        ?LearningCard $learningCard,
        ReviewRating $rating,
        \DateTimeImmutable $reviewedAt,
        int $responseTimeMs,
        string $studySessionId,
        int $studyPosition,
    ) {
        if ($responseTimeMs < 0) {
            throw new \InvalidArgumentException('Response time cannot be negative.');
        }

        if ($studySessionId === '') {
            throw new \InvalidArgumentException('Study session ID cannot be empty.');
        }

        if ($studyPosition < 0) {
            throw new \InvalidArgumentException('Study position cannot be negative.');
        }

        $this->learningCard = $learningCard;
        $this->rating = $rating;
        $this->reviewedAt = $reviewedAt;
        $this->responseTimeMs = $responseTimeMs;
        $this->studySessionId = $studySessionId;
        $this->studyPosition = $studyPosition;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLearningCard(): ?LearningCard
    {
        return $this->learningCard;
    }

    public function getRating(): ReviewRating
    {
        return $this->rating;
    }

    public function getReviewedAt(): \DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getResponseTimeMs(): int
    {
        return $this->responseTimeMs;
    }

    public function getStudySessionId(): string
    {
        return $this->studySessionId;
    }

    public function getStudyPosition(): int
    {
        return $this->studyPosition;
    }
}

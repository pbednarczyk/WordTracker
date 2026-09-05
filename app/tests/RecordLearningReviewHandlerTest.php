<?php

declare(strict_types=1);

namespace App\Tests;

use App\Application\RecordLearningReviewHandler;
use App\Clock\ClockInterface;
use App\Entity\LearningCard;
use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
use App\Entity\VocabularyItem;
use App\Enum\LearningCardType;
use App\Enum\PublicationType;
use App\Enum\ReviewRating;
use App\Repository\LearningReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecordLearningReviewHandlerTest extends KernelTestCase
{
    use DatabaseResetTrait;

    private EntityManagerInterface $entityManager;
    private LearningReviewRepository $learningReviewRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $repository = self::getContainer()->get(LearningReviewRepository::class);
        self::assertInstanceOf(LearningReviewRepository::class, $repository);
        $this->learningReviewRepository = $repository;

        $this->resetDatabase();
    }

    public function testRecordsReviewedAtFromClockAndClampsLongResponseTime(): void
    {
        $card = $this->persistCard('reluctant');
        $now = new \DateTimeImmutable('2026-09-05 23:14:00');
        $handler = new RecordLearningReviewHandler(
            $this->entityManager,
            $this->learningReviewRepository,
            new FixedClock($now),
        );

        $review = $handler->record(
            card: $card,
            rating: ReviewRating::HARD,
            studySessionId: '550e8400-e29b-41d4-a716-446655440000',
            studyPosition: 17,
            startedAt: $now->modify('-2 hours'),
        );

        self::assertSame(ReviewRating::HARD, $review->getRating());
        self::assertSame($now->getTimestamp(), $review->getReviewedAt()->getTimestamp());
        self::assertSame(30 * 60 * 1000, $review->getResponseTimeMs());
    }

    public function testHandlerIsIdempotentPerStudyPresentation(): void
    {
        $card = $this->persistCard('reluctant');
        $now = new \DateTimeImmutable('2026-09-05 23:14:00');
        $handler = new RecordLearningReviewHandler(
            $this->entityManager,
            $this->learningReviewRepository,
            new FixedClock($now),
        );

        $first = $handler->record(
            card: $card,
            rating: ReviewRating::GOOD,
            studySessionId: '550e8400-e29b-41d4-a716-446655440000',
            studyPosition: 3,
            startedAt: $now->modify('-10 seconds'),
        );
        $second = $handler->record(
            card: $card,
            rating: ReviewRating::AGAIN,
            studySessionId: '550e8400-e29b-41d4-a716-446655440000',
            studyPosition: 3,
            startedAt: $now->modify('-10 seconds'),
        );

        self::assertSame($first->getId(), $second->getId());
        self::assertSame(ReviewRating::GOOD, $second->getRating());
        self::assertSame(1, $this->learningReviewRepository->count([]));
    }

    private function persistCard(string $lemma): LearningCard
    {
        $publication = new Publication(
            title: 'Review handler source',
            type: PublicationType::ARTICLE,
            language: 'en',
            rawText: 'Review handler source.',
        );
        $item = new VocabularyItem('en', $lemma, 'ADJ');
        $publicationVocabulary = new PublicationVocabulary($publication, $item, 1);
        $card = new LearningCard(
            vocabularyItem: $item,
            publicationVocabulary: $publicationVocabulary,
            publicationVocabularyEnrichment: null,
            type: LearningCardType::FORWARD,
            front: $lemma,
            back: 'translation',
            contextSentence: 'Review handler source.',
            clozeSentence: null,
        );

        $this->entityManager->persist($publication);
        $this->entityManager->persist($item);
        $this->entityManager->persist($publicationVocabulary);
        $this->entityManager->persist($card);
        $this->entityManager->flush();

        return $card;
    }
}

final readonly class FixedClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests;

use App\Application\RecordLearningReviewHandler;
use App\Application\VocabularyLearningStatusEvaluator;
use App\Clock\ClockInterface;
use App\Entity\LearningCard;
use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
use App\Entity\VocabularyItem;
use App\Enum\LearningCardType;
use App\Enum\PublicationType;
use App\Enum\ReviewRating;
use App\Enum\VocabularyStatus;
use App\Fsrs\FsrsScheduleResult;
use App\Fsrs\FsrsSchedulerInterface;
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
            new FakeFsrsScheduler(),
            new VocabularyLearningStatusEvaluator($this->entityManager->getConnection()),
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
            new FakeFsrsScheduler(),
            new VocabularyLearningStatusEvaluator($this->entityManager->getConnection()),
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

    public function testFirstReviewUpdatesFsrsState(): void
    {
        $card = $this->persistCard('reluctant');
        $now = new \DateTimeImmutable('2026-09-05 23:14:00');
        $scheduler = new FakeFsrsScheduler();
        $handler = new RecordLearningReviewHandler(
            $this->entityManager,
            $this->learningReviewRepository,
            new FixedClock($now),
            $scheduler,
            new VocabularyLearningStatusEvaluator($this->entityManager->getConnection()),
        );

        $handler->record(
            card: $card,
            rating: ReviewRating::GOOD,
            studySessionId: '550e8400-e29b-41d4-a716-446655440001',
            studyPosition: 1,
            startedAt: $now->modify('-10 seconds'),
        );

        self::assertSame(1, $this->learningReviewRepository->count([]));
        self::assertTrue($card->hasFsrsState());
        self::assertSame(1, $card->getFsrsReps());
        self::assertNotNull($card->getNextReviewAt());
        self::assertGreaterThan($now, $card->getNextReviewAt());
        self::assertSame(1, $scheduler->calls);
    }

    public function testDuplicatePresentationDoesNotAdvanceFsrsTwice(): void
    {
        $card = $this->persistCard('reluctant');
        $now = new \DateTimeImmutable('2026-09-05 23:14:00');
        $scheduler = new FakeFsrsScheduler();
        $handler = new RecordLearningReviewHandler(
            $this->entityManager,
            $this->learningReviewRepository,
            new FixedClock($now),
            $scheduler,
            new VocabularyLearningStatusEvaluator($this->entityManager->getConnection()),
        );

        $handler->record($card, ReviewRating::GOOD, '550e8400-e29b-41d4-a716-446655440002', 1, $now);
        $nextReviewAt = $card->getNextReviewAt();
        $handler->record($card, ReviewRating::EASY, '550e8400-e29b-41d4-a716-446655440002', 1, $now);

        self::assertSame(1, $this->learningReviewRepository->count([]));
        self::assertSame(1, $scheduler->calls);
        self::assertEquals($nextReviewAt, $card->getNextReviewAt());
        self::assertSame(VocabularyStatus::LEARNING, $card->getVocabularyItem()->getStatus());
    }

    public function testFsrsFailureRollsBackReview(): void
    {
        $card = $this->persistCard('reluctant');
        $now = new \DateTimeImmutable('2026-09-05 23:14:00');
        $handler = new RecordLearningReviewHandler(
            $this->entityManager,
            $this->learningReviewRepository,
            new FixedClock($now),
            new ThrowingFsrsScheduler(),
            new VocabularyLearningStatusEvaluator($this->entityManager->getConnection()),
        );

        $this->expectException(\RuntimeException::class);

        try {
            $handler->record($card, ReviewRating::GOOD, '550e8400-e29b-41d4-a716-446655440003', 1, $now);
        } finally {
            self::assertSame(0, $this->learningReviewRepository->count([]));
        }
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

final class FakeFsrsScheduler implements FsrsSchedulerInterface
{
    public int $calls = 0;

    public function scheduleReview(LearningCard $card, ReviewRating $rating, \DateTimeImmutable $reviewedAt): FsrsScheduleResult
    {
        ++$this->calls;

        return new FsrsScheduleResult(
            state: 2,
            stability: 2.5 + $this->calls,
            difficulty: 5.0,
            elapsedDays: 0,
            scheduledDays: 1,
            reps: ($card->getFsrsReps() ?? 0) + 1,
            lapses: ($card->getFsrsLapses() ?? 0) + ($rating === ReviewRating::AGAIN ? 1 : 0),
            step: 0,
            nextReviewAt: $reviewedAt->modify('+1 day'),
            lastReviewAt: $reviewedAt,
        );
    }
}

final readonly class ThrowingFsrsScheduler implements FsrsSchedulerInterface
{
    public function scheduleReview(LearningCard $card, ReviewRating $rating, \DateTimeImmutable $reviewedAt): FsrsScheduleResult
    {
        throw new \RuntimeException('scheduler failed');
    }
}

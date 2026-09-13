<?php

declare(strict_types=1);

namespace App\Tests;

use App\Application\VocabularyLearningStatusEvaluator;
use App\Entity\LearningCard;
use App\Entity\LearningReview;
use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
use App\Entity\VocabularyItem;
use App\Enum\KnowledgeStatusOrigin;
use App\Enum\LearningCardType;
use App\Enum\PublicationType;
use App\Enum\ReviewRating;
use App\Enum\VocabularyStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VocabularyLearningStatusEvaluatorTest extends KernelTestCase
{
    use DatabaseResetTrait;

    private EntityManagerInterface $entityManager;
    private VocabularyLearningStatusEvaluator $evaluator;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->evaluator = new VocabularyLearningStatusEvaluator($entityManager->getConnection());

        $this->resetDatabase();
    }

    public function testGeneratingLearningCardsDoesNotChangeUnknownVocabulary(): void
    {
        [$item] = $this->persistItemWithCard('reluctant');

        self::assertSame(VocabularyStatus::UNKNOWN, $item->getStatus());
        self::assertNull($item->getKnowledgeStatusOrigin());
    }

    public function testAgainOnUnknownDoesNotPromoteIt(): void
    {
        [$item, $card] = $this->persistItemWithCard('reluctant');
        $this->review($card, ReviewRating::AGAIN, '2026-09-01 09:00:00 UTC', 1, 1);
        $this->entityManager->flush();

        self::assertNull($this->evaluator->evaluateAfterReview($item, ReviewRating::AGAIN));
    }

    public function testFirstSuccessfulReviewMovesUnknownToLearning(): void
    {
        [$item, $card] = $this->persistItemWithCard('reluctant');
        $this->review($card, ReviewRating::GOOD, '2026-09-01 09:00:00 UTC', 1, 1);
        $this->entityManager->flush();

        self::assertSame(VocabularyStatus::LEARNING, $this->evaluator->evaluateAfterReview($item, ReviewRating::GOOD));
    }

    public function testRepeatedSuccessfulReviewsWithFsrsEvidenceMoveLearningToKnown(): void
    {
        [$item, $card] = $this->persistItemWithCard('reluctant');
        $item->applyLearningStatus(VocabularyStatus::LEARNING);
        $this->successfulReviews($card, ['2026-09-01', '2026-09-03', '2026-09-08'], 7);
        $this->entityManager->flush();

        self::assertSame(VocabularyStatus::KNOWN, $this->evaluator->evaluateAfterReview($item, ReviewRating::GOOD));
    }

    public function testStrongerLongTermEvidenceMovesKnownToMature(): void
    {
        [$item, $card] = $this->persistItemWithCard('reluctant');
        $item->applyLearningStatus(VocabularyStatus::KNOWN);
        $this->successfulReviews($card, ['2026-09-01', '2026-09-10', '2026-09-20', '2026-10-01', '2026-10-20'], 30);
        $this->entityManager->flush();

        self::assertSame(VocabularyStatus::MATURE, $this->evaluator->evaluateAfterReview($item, ReviewRating::EASY));
    }

    public function testAgainMovesAutomaticallyLearnedKnownAndMatureToLapsed(): void
    {
        [$known] = $this->persistItemWithCard('known');
        $known->applyLearningStatus(VocabularyStatus::KNOWN);
        [$mature] = $this->persistItemWithCard('mature');
        $mature->applyLearningStatus(VocabularyStatus::MATURE);
        $this->entityManager->flush();

        self::assertSame(VocabularyStatus::LAPSED, $this->evaluator->evaluateAfterReview($known, ReviewRating::AGAIN));
        self::assertSame(VocabularyStatus::LAPSED, $this->evaluator->evaluateAfterReview($mature, ReviewRating::AGAIN));
    }

    public function testOneSuccessfulReviewDoesNotImmediatelyRestoreLapsedToKnown(): void
    {
        [$item, $card] = $this->persistItemWithCard('reluctant');
        $item->applyLearningStatus(VocabularyStatus::LAPSED);
        $this->successfulReviews($card, ['2026-09-01', '2026-09-05', '2026-09-10'], 14);
        $this->review($card, ReviewRating::AGAIN, '2026-09-15 09:00:00 UTC', 4, 1);
        $this->review($card, ReviewRating::GOOD, '2026-09-16 09:00:00 UTC', 5, 14);
        $this->entityManager->flush();

        self::assertSame(VocabularyStatus::LEARNING, $this->evaluator->evaluateAfterReview($item, ReviewRating::GOOD));
    }

    public function testLapsedVocabularyCanRecoverToKnown(): void
    {
        [$item, $card] = $this->persistItemWithCard('reluctant');
        $item->applyLearningStatus(VocabularyStatus::LAPSED);
        $this->review($card, ReviewRating::AGAIN, '2026-09-15 09:00:00 UTC', 1, 1);
        $this->successfulReviews($card, ['2026-09-16', '2026-09-20', '2026-09-27'], 7, startPosition: 2);
        $this->entityManager->flush();

        self::assertSame(VocabularyStatus::KNOWN, $this->evaluator->evaluateAfterReview($item, ReviewRating::GOOD));
    }

    public function testAddingNewLearningCardDoesNotDowngradeKnownOrMatureVocabulary(): void
    {
        [$item, $card] = $this->persistItemWithCard('reluctant');
        $item->applyLearningStatus(VocabularyStatus::MATURE);
        $this->successfulReviews($card, ['2026-09-01', '2026-09-10', '2026-09-20', '2026-10-01', '2026-10-20'], 30);
        $this->persistCardFor($item, 'Another context');
        $this->entityManager->flush();

        self::assertSame(VocabularyStatus::MATURE, $this->evaluator->evaluateAfterReview($item, ReviewRating::GOOD));
    }

    public function testManualKnownVocabularyIsNotAutomaticallyLapsed(): void
    {
        [$item] = $this->persistItemWithCard('reluctant');
        $item->markKnown();
        $this->entityManager->flush();

        self::assertSame(KnowledgeStatusOrigin::MANUAL, $item->getKnowledgeStatusOrigin());
        self::assertNull($this->evaluator->evaluateAfterReview($item, ReviewRating::AGAIN));
    }

    public function testCoverageMeaningIncludesKnownAndMatureOnly(): void
    {
        self::assertFalse(VocabularyStatus::UNKNOWN->isKnownForCoverage());
        self::assertFalse(VocabularyStatus::LEARNING->isKnownForCoverage());
        self::assertTrue(VocabularyStatus::KNOWN->isKnownForCoverage());
        self::assertTrue(VocabularyStatus::MATURE->isKnownForCoverage());
        self::assertFalse(VocabularyStatus::LAPSED->isKnownForCoverage());
    }

    public function testExistingUnknownAndKnownDataRemainValid(): void
    {
        $unknown = new VocabularyItem('en', 'unknown', 'ADJ');
        $known = new VocabularyItem('en', 'known', 'ADJ');
        $known->markKnown();

        self::assertSame(VocabularyStatus::UNKNOWN, $unknown->getStatus());
        self::assertSame(VocabularyStatus::KNOWN, $known->getStatus());
        self::assertSame(KnowledgeStatusOrigin::MANUAL, $known->getKnowledgeStatusOrigin());
    }

    /**
     * @return array{0: VocabularyItem, 1: LearningCard}
     */
    private function persistItemWithCard(string $lemma): array
    {
        $publication = new Publication('Lifecycle '.$lemma, PublicationType::ARTICLE, 'en', 'Lifecycle source.');
        $item = new VocabularyItem('en', $lemma, 'ADJ');
        $publicationVocabulary = new PublicationVocabulary($publication, $item, 1);
        $card = new LearningCard(
            vocabularyItem: $item,
            publicationVocabulary: $publicationVocabulary,
            publicationVocabularyEnrichment: null,
            type: LearningCardType::FORWARD,
            front: $lemma,
            back: 'translation',
            contextSentence: 'Lifecycle source.',
            clozeSentence: null,
        );

        foreach ([$publication, $item, $publicationVocabulary, $card] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        return [$item, $card];
    }

    private function persistCardFor(VocabularyItem $item, string $title): LearningCard
    {
        $publication = new Publication($title, PublicationType::ARTICLE, 'en', 'Another source.');
        $publicationVocabulary = new PublicationVocabulary($publication, $item, 1);
        $card = new LearningCard($item, $publicationVocabulary, null, LearningCardType::REVERSE, 'front', 'back');
        foreach ([$publication, $publicationVocabulary, $card] as $entity) {
            $this->entityManager->persist($entity);
        }

        return $card;
    }

    /**
     * @param list<string> $days
     */
    private function successfulReviews(LearningCard $card, array $days, int $scheduledDays, int $startPosition = 1): void
    {
        foreach ($days as $index => $day) {
            $this->review($card, ReviewRating::GOOD, $day.' 09:00:00 UTC', $startPosition + $index, $scheduledDays);
        }
    }

    private function review(LearningCard $card, ReviewRating $rating, string $reviewedAt, int $position, int $scheduledDays): void
    {
        $reviewedAtTime = new \DateTimeImmutable($reviewedAt);
        $card->applyFsrsState(
            state: 2,
            stability: (float) $scheduledDays,
            difficulty: 5.0,
            elapsedDays: 1,
            scheduledDays: $scheduledDays,
            reps: ($card->getFsrsReps() ?? 0) + 1,
            lapses: ($card->getFsrsLapses() ?? 0) + ($rating === ReviewRating::AGAIN ? 1 : 0),
            step: 0,
            nextReviewAt: $reviewedAtTime->modify('+'.$scheduledDays.' days'),
            lastReviewAt: $reviewedAtTime,
        );
        $this->entityManager->persist(new LearningReview($card, $rating, $reviewedAtTime, 1000, 'lifecycle-'.$position, $position));
    }
}

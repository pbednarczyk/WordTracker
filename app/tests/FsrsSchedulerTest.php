<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\LearningCard;
use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
use App\Entity\VocabularyItem;
use App\Enum\LearningCardType;
use App\Enum\PublicationType;
use App\Enum\ReviewRating;
use App\Fsrs\FsrsRatingMapper;
use App\Fsrs\FsrsSchedulerConfig;
use App\Fsrs\LibraryFsrsScheduler;
use PHPUnit\Framework\TestCase;
use Scottlaurent\FSRS\Rating;

final class FsrsSchedulerTest extends TestCase
{
    public function testRatingMappingIsExplicitForAllRatings(): void
    {
        self::assertSame(Rating::AGAIN, FsrsRatingMapper::toLibraryRating(ReviewRating::AGAIN));
        self::assertSame(Rating::HARD, FsrsRatingMapper::toLibraryRating(ReviewRating::HARD));
        self::assertSame(Rating::GOOD, FsrsRatingMapper::toLibraryRating(ReviewRating::GOOD));
        self::assertSame(Rating::EASY, FsrsRatingMapper::toLibraryRating(ReviewRating::EASY));
    }

    public function testNewCardGoodReviewInitializesFsrsState(): void
    {
        $scheduler = new LibraryFsrsScheduler(new FsrsSchedulerConfig());
        $card = $this->card('reluctant');
        $now = new \DateTimeImmutable('2026-09-06 10:00:00 UTC');

        $result = $scheduler->scheduleReview($card, ReviewRating::GOOD, $now);

        self::assertGreaterThan(0, $result->state);
        self::assertGreaterThan(0, $result->stability);
        self::assertGreaterThan(0, $result->difficulty);
        self::assertSame(1, $result->reps);
        self::assertGreaterThan($now, $result->nextReviewAt);
        self::assertSame($now->getTimestamp(), $result->lastReviewAt->getTimestamp());
    }

    public function testAgainIsScheduledByFsrsAsFailure(): void
    {
        $scheduler = new LibraryFsrsScheduler(new FsrsSchedulerConfig());
        $card = $this->card('reluctant');
        $now = new \DateTimeImmutable('2026-09-06 10:00:00 UTC');

        $result = $scheduler->scheduleReview($card, ReviewRating::AGAIN, $now);

        self::assertSame(1, $result->reps);
        self::assertGreaterThan(0, $result->stability);
        self::assertGreaterThan(0, $result->difficulty);
        self::assertGreaterThan($now, $result->nextReviewAt);
    }

    public function testProgressionOverTimeUsesPersistedState(): void
    {
        $scheduler = new LibraryFsrsScheduler(new FsrsSchedulerConfig());
        $card = $this->card('reluctant');
        $day0 = new \DateTimeImmutable('2026-09-06 10:00:00 UTC');

        $first = $scheduler->scheduleReview($card, ReviewRating::GOOD, $day0);
        $card->applyFsrsState(
            $first->state,
            $first->stability,
            $first->difficulty,
            $first->elapsedDays,
            $first->scheduledDays,
            $first->reps,
            $first->lapses,
            $first->step,
            $first->nextReviewAt,
            $first->lastReviewAt,
        );
        $second = $scheduler->scheduleReview($card, ReviewRating::GOOD, $first->nextReviewAt);
        $card->applyFsrsState(
            $second->state,
            $second->stability,
            $second->difficulty,
            $second->elapsedDays,
            $second->scheduledDays,
            $second->reps,
            $second->lapses,
            $second->step,
            $second->nextReviewAt,
            $second->lastReviewAt,
        );
        $third = $scheduler->scheduleReview($card, ReviewRating::HARD, $second->nextReviewAt);

        self::assertSame(1, $first->reps);
        self::assertSame(2, $second->reps);
        self::assertSame(3, $third->reps);
        self::assertGreaterThan($second->lastReviewAt, $third->nextReviewAt);
    }

    private function card(string $lemma): LearningCard
    {
        $publication = new Publication('FSRS source', PublicationType::ARTICLE, 'en', 'FSRS source.');
        $item = new VocabularyItem('en', $lemma, 'ADJ');
        $publicationVocabulary = new PublicationVocabulary($publication, $item, 1);

        return new LearningCard(
            vocabularyItem: $item,
            publicationVocabulary: $publicationVocabulary,
            publicationVocabularyEnrichment: null,
            type: LearningCardType::FORWARD,
            front: $lemma,
            back: 'translation',
            contextSentence: 'FSRS source.',
            clozeSentence: null,
        );
    }
}

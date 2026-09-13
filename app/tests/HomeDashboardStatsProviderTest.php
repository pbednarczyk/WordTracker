<?php

declare(strict_types=1);

namespace App\Tests;

use App\Application\HomeDashboardStatsProvider;
use App\Clock\ClockInterface;
use App\Entity\LearningCard;
use App\Entity\LearningReview;
use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
use App\Entity\PublicationVocabularyEnrichment;
use App\Entity\VocabularyItem;
use App\Entity\VocabularyOccurrence;
use App\Enum\LearningCardType;
use App\Enum\PublicationType;
use App\Enum\ReviewRating;
use App\Enum\VocabularyStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HomeDashboardStatsProviderTest extends KernelTestCase
{
    use DatabaseResetTrait;

    private EntityManagerInterface $entityManager;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->now = new \DateTimeImmutable('2026-09-06 12:00:00');

        $this->resetDatabase();
    }

    public function testDashboardStatsExcludeSoftDeletedPublicationVocabularyAndCalculateProgress(): void
    {
        $firstPublication = new Publication('First', PublicationType::ARTICLE, 'en', rawText: 'Known unknown deleted.');
        $secondPublication = new Publication('Second', PublicationType::ARTICLE, 'en', rawText: 'Mastered.');
        $known = $this->item('known', VocabularyStatus::KNOWN);
        $unknown = $this->item('unknown', VocabularyStatus::UNKNOWN);
        $mastered = $this->item('mastered', VocabularyStatus::MATURE);
        $learning = $this->item('learning', VocabularyStatus::LEARNING);
        $lapsed = $this->item('lapsed', VocabularyStatus::LAPSED);
        $deleted = $this->item('deleted', VocabularyStatus::KNOWN);

        $knownContext = new PublicationVocabulary($firstPublication, $known, 7);
        $unknownContext = new PublicationVocabulary($firstPublication, $unknown, 3);
        $learningContext = new PublicationVocabulary($firstPublication, $learning, 2);
        $lapsedContext = new PublicationVocabulary($firstPublication, $lapsed, 4);
        $masteredContext = new PublicationVocabulary($secondPublication, $mastered, 5);
        $deletedContext = new PublicationVocabulary($firstPublication, $deleted, 99);
        $deletedContext->softDelete($this->now->modify('-1 day'));

        foreach ([$firstPublication, $secondPublication, $knownContext, $unknownContext, $learningContext, $lapsedContext, $masteredContext, $deletedContext] as $entity) {
            $this->entityManager->persist($entity);
        }

        $this->persistOccurrence($firstPublication, $known, 'known', 1);
        $this->persistOccurrence($firstPublication, $unknown, 'unknown', 2);
        $this->persistOccurrence($firstPublication, $learning, 'learning', 3);
        $this->persistOccurrence($firstPublication, $lapsed, 'lapsed', 4);
        $this->persistOccurrence($secondPublication, $mastered, 'mastered', 5);
        $this->persistOccurrence($firstPublication, $deleted, 'deleted', 4);
        $this->persistEnrichment($knownContext);
        $this->persistEnrichment($masteredContext);
        $this->persistEnrichment($deletedContext);

        $due = $this->card($known, $knownContext, LearningCardType::FORWARD);
        $due->applyFsrsState(2, 3.0, 4.0, 1, 1, 2, 0, 0, $this->now->modify('-1 hour'), $this->now->modify('-1 day'));
        $new = $this->card($unknown, $unknownContext, LearningCardType::REVERSE);
        $scheduled = $this->card($mastered, $masteredContext, LearningCardType::FORWARD);
        $scheduled->applyFsrsState(2, 3.0, 4.0, 1, 1, 2, 0, 0, $this->now->modify('+1 day'), $this->now->modify('-1 day'));
        $deletedCard = $this->card($deleted, $deletedContext, LearningCardType::FORWARD);
        $inactive = $this->card($unknown, $unknownContext, LearningCardType::CONTEXT_MEANING);
        $inactive->deactivate();

        foreach ([$due, $new, $scheduled, $deletedCard, $inactive] as $card) {
            $this->entityManager->persist($card);
        }

        $this->entityManager->persist(new LearningReview($due, ReviewRating::GOOD, $this->now->modify('-1 hour'), 1000, 'today', 0));
        $this->entityManager->persist(new LearningReview($scheduled, ReviewRating::HARD, $this->now->modify('-2 days'), 1000, 'old', 0));
        $this->entityManager->flush();

        $stats = $this->provider()->getStats();

        self::assertSame(2, $stats->knownVocabularyItems);
        self::assertSame(5, $stats->totalVocabularyItems);
        self::assertSame(2, $stats->publications);
        self::assertSame(2, $stats->learningReviewsCompleted);
        self::assertSame(1, $stats->dueCardsNow);
        self::assertSame(1, $stats->newCardsAvailable);
        self::assertSame(1, $stats->reviewsCompletedToday);
        self::assertSame(1, $stats->scheduledCards);
        self::assertSame(5, $stats->totalVocabularyOccurrences);
        self::assertSame(5, $stats->activePublicationVocabularyContexts);
        self::assertSame(2, $stats->enrichedPublicationVocabularyContexts);
        self::assertSame(3, $stats->learningCards);
        self::assertSame(2, $stats->enrichedContextsWithLearningCards);
        self::assertSame(40.0, $stats->knownVocabularyPercent());
        self::assertSame(40.0, $stats->enrichmentPercent());
        self::assertSame(100.0, $stats->learningCardCoveragePercent());
        self::assertSame(62.5, $stats->averageVocabularyCoverage);
        self::assertSame(71.9, $stats->averageTextCoverage);
    }

    public function testDashboardStatsHandleEmptyDenominatorsSafely(): void
    {
        $stats = $this->provider()->getStats();

        self::assertSame(0, $stats->totalVocabularyItems);
        self::assertSame(0.0, $stats->knownVocabularyPercent());
        self::assertSame(0.0, $stats->enrichmentPercent());
        self::assertSame(0.0, $stats->learningCardCoveragePercent());
        self::assertNull($stats->averageVocabularyCoverage);
        self::assertNull($stats->averageTextCoverage);
    }

    private function provider(): HomeDashboardStatsProvider
    {
        return new HomeDashboardStatsProvider(
            $this->entityManager->getConnection(),
            new HomeDashboardFixedClock($this->now),
        );
    }

    private function item(string $lemma, VocabularyStatus $status): VocabularyItem
    {
        $item = new VocabularyItem('en', $lemma, 'NOUN');
        if ($status === VocabularyStatus::KNOWN) {
            $item->markKnown();
        } elseif ($status !== VocabularyStatus::UNKNOWN) {
            $item->applyLearningStatus($status);
        }

        $this->entityManager->persist($item);

        return $item;
    }

    private function persistOccurrence(Publication $publication, VocabularyItem $item, string $form, int $position): void
    {
        $this->entityManager->persist(new VocabularyOccurrence(
            publication: $publication,
            vocabularyItem: $item,
            originalForm: $form,
            sentence: 'Example sentence.',
            position: $position,
        ));
    }

    private function persistEnrichment(PublicationVocabulary $publicationVocabulary): void
    {
        $this->entityManager->persist(new PublicationVocabularyEnrichment(
            publicationVocabulary: $publicationVocabulary,
            translationPl: 'tlumaczenie',
            definitionEn: 'definition',
            meaningInContext: 'meaning',
            simpleExample: 'A simple example.',
            cefrLevel: 'B1',
            sourceSentence: 'Example sentence.',
            provider: 'test',
            model: 'fake',
            promptVersion: 'word-enrichment-v4',
        ));
    }

    private function card(VocabularyItem $item, PublicationVocabulary $publicationVocabulary, LearningCardType $type): LearningCard
    {
        return new LearningCard(
            vocabularyItem: $item,
            publicationVocabulary: $publicationVocabulary,
            publicationVocabularyEnrichment: $publicationVocabulary->getEnrichment(),
            type: $type,
            front: $item->getLemma(),
            back: 'translation',
            contextSentence: 'Example sentence.',
            clozeSentence: null,
        );
    }
}

final readonly class HomeDashboardFixedClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}

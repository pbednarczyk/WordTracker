<?php

declare(strict_types=1);

namespace App\Tests;

use App\Application\DueCardSelector;
use App\Clock\ClockInterface;
use App\Entity\LearningCard;
use App\Entity\LearningReview;
use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
use App\Entity\VocabularyItem;
use App\Enum\LearningCardType;
use App\Enum\PublicationType;
use App\Enum\ReviewRating;
use App\Repository\LearningCardQuery;
use App\Repository\LearningCardRepository;
use App\Repository\LearningReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DueCardSelectorTest extends KernelTestCase
{
    use DatabaseResetTrait;

    private EntityManagerInterface $entityManager;
    private LearningCardRepository $learningCardRepository;
    private LearningReviewRepository $learningReviewRepository;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $learningCardRepository = self::getContainer()->get(LearningCardRepository::class);
        self::assertInstanceOf(LearningCardRepository::class, $learningCardRepository);
        $this->learningCardRepository = $learningCardRepository;

        $learningReviewRepository = self::getContainer()->get(LearningReviewRepository::class);
        self::assertInstanceOf(LearningReviewRepository::class, $learningReviewRepository);
        $this->learningReviewRepository = $learningReviewRepository;

        $this->now = new \DateTimeImmutable('2026-09-06 12:00:00 UTC');
        $this->resetDatabase();
    }

    public function testNewCardsAreSelectedFromNewPool(): void
    {
        $card = $this->persistCard('new-card');
        $this->entityManager->flush();

        $selection = $this->selector(reviewLimit: 100, newLimit: 20)->select(new LearningCardQuery(), 100);

        self::assertSame([$card->getId()], $this->ids($selection->cards));
        self::assertSame(0, $selection->dueToday);
        self::assertSame(1, $selection->newAvailable);
    }

    public function testDueCardsAreIncludedAndFutureInactiveCardsAreExcluded(): void
    {
        $due = $this->persistCard('due-card');
        $due->applyFsrsState(2, 3.0, 4.0, 1, 1, 2, 0, 0, $this->now->modify('-1 hour'), $this->now->modify('-1 day'));
        $future = $this->persistCard('future-card');
        $future->applyFsrsState(2, 3.0, 4.0, 1, 1, 2, 0, 0, $this->now->modify('+1 day'), $this->now->modify('-1 day'));
        $inactive = $this->persistCard('inactive-card');
        $inactive->applyFsrsState(2, 3.0, 4.0, 1, 1, 2, 0, 0, $this->now->modify('-2 hours'), $this->now->modify('-1 day'));
        $inactive->deactivate();
        $this->entityManager->flush();

        $selection = $this->selector(reviewLimit: 100, newLimit: 20)->select(new LearningCardQuery(), 100);

        self::assertSame([$due->getId()], $this->ids($selection->cards));
        self::assertSame(1, $selection->dueToday);
    }

    public function testDueCardsConsumeSessionCapacityBeforeNewCards(): void
    {
        for ($index = 0; $index < 150; ++$index) {
            $card = $this->persistCard('due-'.$index);
            $card->applyFsrsState(2, 3.0, 4.0, 1, 1, 2, 0, 0, $this->now->modify('-'.($index + 1).' hours'), $this->now->modify('-2 days'));
        }
        for ($index = 0; $index < 200; ++$index) {
            $this->persistCard('new-'.$index);
        }
        $this->entityManager->flush();

        $selection = $this->selector(reviewLimit: 100, newLimit: 20)->select(new LearningCardQuery(), 100);

        self::assertCount(100, $selection->cards);
        self::assertSame(150, $selection->dueToday);
        self::assertSame(20, $selection->newAvailable);
        self::assertTrue(array_reduce(
            $selection->cards,
            static fn (bool $onlyDue, LearningCard $card): bool => $onlyDue && $card->getFsrsState() !== null,
            true,
        ));
    }

    public function testDailyNewLimitUsesFirstReviewsAlreadyRecordedToday(): void
    {
        for ($index = 0; $index < 15; ++$index) {
            $card = $this->persistCard('introduced-'.$index);
            $card->applyFsrsState(1, 1.0, 5.0, 0, 0, 1, 0, 0, $this->now->modify('+10 minutes'), $this->now);
            $this->persistReview($card, $this->now->modify('-1 hour'), 'new-session-'.$index, 0);
        }
        $expectedCards = [];
        for ($index = 0; $index < 10; ++$index) {
            $expectedCards[] = $this->persistCard('available-new-'.$index);
        }
        $this->entityManager->flush();
        $expected = $this->ids($expectedCards);

        $selection = $this->selector(reviewLimit: 100, newLimit: 20)->select(new LearningCardQuery(), 100);

        self::assertSame(array_slice($expected, 0, 5), $this->ids($selection->cards));
        self::assertSame(5, $selection->newAvailable);
        self::assertSame(5, $selection->dailyNewRemaining);
    }

    public function testDailyReviewLimitUsesRepeatReviewsAlreadyRecordedToday(): void
    {
        for ($index = 0; $index < 15; ++$index) {
            $card = $this->persistCard('reviewed-'.$index);
            $card->applyFsrsState(2, 2.0, 5.0, 1, 1, 2, 0, 0, $this->now->modify('+1 day'), $this->now);
            $this->persistReview($card, $this->now->modify('-2 days'), 'old-session-'.$index, 0);
            $this->persistReview($card, $this->now->modify('-1 hour'), 'today-session-'.$index, 0);
        }
        for ($index = 0; $index < 10; ++$index) {
            $card = $this->persistCard('due-limit-'.$index);
            $card->applyFsrsState(2, 2.0, 5.0, 1, 1, 2, 0, 0, $this->now->modify('-'.$index.' hours'), $this->now->modify('-2 days'));
        }
        $this->entityManager->flush();

        $selection = $this->selector(reviewLimit: 20, newLimit: 0)->select(new LearningCardQuery(), 100);

        self::assertCount(5, $selection->cards);
        self::assertSame(5, $selection->dailyReviewRemaining);
    }

    private function selector(int $reviewLimit, int $newLimit): DueCardSelector
    {
        return new DueCardSelector(
            $this->learningCardRepository,
            $this->learningReviewRepository,
            new SelectorFixedClock($this->now),
            $reviewLimit,
            $newLimit,
        );
    }

    private function persistCard(string $lemma): LearningCard
    {
        $publication = new Publication('Due selector source '.$lemma, PublicationType::ARTICLE, 'en', 'Due selector source.');
        $item = new VocabularyItem('en', $lemma, 'NOUN');
        $publicationVocabulary = new PublicationVocabulary($publication, $item, 1);
        $card = new LearningCard(
            vocabularyItem: $item,
            publicationVocabulary: $publicationVocabulary,
            publicationVocabularyEnrichment: null,
            type: LearningCardType::FORWARD,
            front: $lemma,
            back: 'translation',
            contextSentence: 'Due selector source.',
            clozeSentence: null,
        );

        $this->entityManager->persist($publication);
        $this->entityManager->persist($item);
        $this->entityManager->persist($publicationVocabulary);
        $this->entityManager->persist($card);

        return $card;
    }

    private function persistReview(LearningCard $card, \DateTimeImmutable $reviewedAt, string $sessionId, int $position): void
    {
        $this->entityManager->persist(new LearningReview($card, ReviewRating::GOOD, $reviewedAt, 1000, $sessionId, $position));
    }

    /**
     * @param list<LearningCard> $cards
     *
     * @return list<int>
     */
    private function ids(array $cards): array
    {
        return array_map(static fn (LearningCard $card): int => (int) $card->getId(), $cards);
    }
}

final readonly class SelectorFixedClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests;

use App\Application\SmartStudyQueueBuilder;
use App\Entity\LearningCard;
use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
use App\Entity\VocabularyItem;
use App\Enum\LearningCardType;
use App\Enum\PublicationType;
use Doctrine\ORM\EntityManagerInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SmartStudyQueueBuilderTest extends KernelTestCase
{
    use DatabaseResetTrait;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->resetDatabase();
    }

    public function testSeparatesBasicSiblingCardsWhenFeasible(): void
    {
        $cards = $this->persistCards(['reluctant', 'willingness', 'oversight', 'accountability'], [
            LearningCardType::FORWARD,
            LearningCardType::REVERSE,
        ]);

        $orderedIds = $this->builder(seed: 101, minDistance: 1)->build($cards, 100);

        $this->assertSameCardSet($cards, $orderedIds);
        $this->assertSiblingDistanceAtLeast($cards, $orderedIds, 1);
    }

    public function testSeparatesCardsForSameVocabularyItemAcrossPublicationVocabularyRows(): void
    {
        $item = new VocabularyItem('en', 'charge', 'NOUN');
        $firstPublication = new Publication(
            title: 'Legal source',
            type: PublicationType::ARTICLE,
            language: 'en',
            rawText: 'The charge was dismissed.',
        );
        $secondPublication = new Publication(
            title: 'Energy source',
            type: PublicationType::ARTICLE,
            language: 'en',
            rawText: 'The charge faded.',
        );
        $firstPublicationVocabulary = new PublicationVocabulary($firstPublication, $item, 1);
        $secondPublicationVocabulary = new PublicationVocabulary($secondPublication, $item, 1);
        $otherCards = $this->persistCards(['oversight', 'accountability'], [
            LearningCardType::FORWARD,
            LearningCardType::REVERSE,
        ]);

        $this->entityManager->persist($item);
        $this->entityManager->persist($firstPublication);
        $this->entityManager->persist($secondPublication);
        $this->entityManager->persist($firstPublicationVocabulary);
        $this->entityManager->persist($secondPublicationVocabulary);

        $firstCard = new LearningCard(
            vocabularyItem: $item,
            publicationVocabulary: $firstPublicationVocabulary,
            publicationVocabularyEnrichment: null,
            type: LearningCardType::FORWARD,
            front: 'legal charge',
            back: 'charge',
            contextSentence: 'The charge was dismissed.',
            clozeSentence: null,
        );
        $secondCard = new LearningCard(
            vocabularyItem: $item,
            publicationVocabulary: $secondPublicationVocabulary,
            publicationVocabularyEnrichment: null,
            type: LearningCardType::REVERSE,
            front: 'energy charge',
            back: 'charge',
            contextSentence: 'The charge faded.',
            clozeSentence: null,
        );
        $this->entityManager->persist($firstCard);
        $this->entityManager->persist($secondCard);
        $this->entityManager->flush();

        $cards = [...$otherCards, $firstCard, $secondCard];
        $orderedIds = $this->builder(seed: 111, minDistance: 1)->build($cards, 100);

        $this->assertSameCardSet($cards, $orderedIds);
        $this->assertSiblingDistanceAtLeast($cards, $orderedIds, 1);
    }

    public function testSeparatesManyVocabularyItemsAtConfiguredDistanceWhenFeasible(): void
    {
        $words = [];
        for ($index = 1; $index <= 20; ++$index) {
            $words[] = sprintf('word%02d', $index);
        }
        $cards = $this->persistCards($words, [
            LearningCardType::FORWARD,
            LearningCardType::REVERSE,
            LearningCardType::CONTEXT_MEANING,
        ]);

        $orderedIds = $this->builder(seed: 202, minDistance: 5)->build($cards, 100);

        self::assertCount(60, $orderedIds);
        $this->assertSameCardSet($cards, $orderedIds);
        $this->assertSiblingDistanceAtLeast($cards, $orderedIds, 5);
    }

    public function testTerminatesAndMaximizesReasonableSeparationWhenDistanceIsImpossible(): void
    {
        $cards = $this->persistCards(['reluctant', 'willingness'], [
            LearningCardType::FORWARD,
            LearningCardType::REVERSE,
            LearningCardType::CONTEXT_MEANING,
            LearningCardType::CLOZE,
        ]);

        $orderedIds = $this->builder(seed: 303, minDistance: 5)->build($cards, 100);

        self::assertCount(8, $orderedIds);
        $this->assertSameCardSet($cards, $orderedIds);
        $this->assertSiblingDistanceAtLeast($cards, $orderedIds, 1);
    }

    public function testRandomizationIsDeterministicForSameSeedAndDifferentAcrossSeeds(): void
    {
        $words = [];
        for ($index = 1; $index <= 12; ++$index) {
            $words[] = sprintf('term%02d', $index);
        }
        $cards = $this->persistCards($words, [
            LearningCardType::FORWARD,
            LearningCardType::REVERSE,
            LearningCardType::CONTEXT_MEANING,
        ]);

        $first = $this->builder(seed: 404, minDistance: 3)->build($cards, 100);
        $second = $this->builder(seed: 404, minDistance: 3)->build($cards, 100);
        $third = $this->builder(seed: 405, minDistance: 3)->build($cards, 100);

        self::assertSame($first, $second);
        self::assertNotSame($first, $third);
        $this->assertSameCardSet($cards, $first);
        $this->assertSameCardSet($cards, $third);
    }

    public function testAvoidsLongTypeStreaksWhenItCanDoSoWithoutBreakingSiblingSeparation(): void
    {
        $words = [];
        for ($index = 1; $index <= 16; ++$index) {
            $words[] = sprintf('mix%02d', $index);
        }
        $cards = $this->persistCards($words, [
            LearningCardType::FORWARD,
            LearningCardType::REVERSE,
            LearningCardType::CONTEXT_MEANING,
        ]);

        $orderedIds = $this->builder(seed: 505, minDistance: 4)->build($cards, 100);

        $this->assertSameCardSet($cards, $orderedIds);
        $this->assertSiblingDistanceAtLeast($cards, $orderedIds, 4);
        self::assertLessThanOrEqual(3, $this->maxTypeStreak($cards, $orderedIds));
    }

    /**
     * @param list<string> $words
     * @param list<LearningCardType> $types
     *
     * @return list<LearningCard>
     */
    private function persistCards(array $words, array $types): array
    {
        $publication = new Publication(
            title: 'Study queue source',
            type: PublicationType::ARTICLE,
            language: 'en',
            rawText: 'Study queue source.',
        );
        $this->entityManager->persist($publication);

        $cards = [];
        foreach ($words as $word) {
            $item = new VocabularyItem('en', $word, 'NOUN');
            $publicationVocabulary = new PublicationVocabulary($publication, $item, 1);
            $this->entityManager->persist($item);
            $this->entityManager->persist($publicationVocabulary);

            foreach ($types as $type) {
                $card = new LearningCard(
                    vocabularyItem: $item,
                    publicationVocabulary: $publicationVocabulary,
                    publicationVocabularyEnrichment: null,
                    type: $type,
                    front: $type->value.' '.$word,
                    back: $word,
                    contextSentence: 'Context for '.$word,
                    clozeSentence: $type === LearningCardType::CLOZE ? 'Context for _____' : null,
                );
                $this->entityManager->persist($card);
                $cards[] = $card;
            }
        }

        $this->entityManager->flush();

        return $cards;
    }

    private function builder(int $seed, int $minDistance): SmartStudyQueueBuilder
    {
        return new SmartStudyQueueBuilder($minDistance, new Randomizer(new Mt19937($seed)));
    }

    /**
     * @param list<LearningCard> $cards
     * @param list<int> $orderedIds
     */
    private function assertSameCardSet(array $cards, array $orderedIds): void
    {
        $expectedIds = array_map(static fn (LearningCard $card): int => (int) $card->getId(), $cards);
        sort($expectedIds);
        $actualIds = $orderedIds;
        sort($actualIds);

        self::assertSame($expectedIds, $actualIds);
        self::assertSameSize($actualIds, array_unique($actualIds));
    }

    /**
     * @param list<LearningCard> $cards
     * @param list<int> $orderedIds
     */
    private function assertSiblingDistanceAtLeast(array $cards, array $orderedIds, int $minDistance): void
    {
        $byId = $this->cardsById($cards);
        $lastByVocabulary = [];

        foreach ($orderedIds as $position => $id) {
            $card = $byId[$id];
            $vocabularyId = $card->getVocabularyItem()->getId();
            self::assertNotNull($vocabularyId);

            if (isset($lastByVocabulary[$vocabularyId])) {
                self::assertGreaterThanOrEqual($minDistance, $position - $lastByVocabulary[$vocabularyId] - 1);
            }

            $lastByVocabulary[$vocabularyId] = $position;
        }
    }

    /**
     * @param list<LearningCard> $cards
     * @param list<int> $orderedIds
     */
    private function maxTypeStreak(array $cards, array $orderedIds): int
    {
        $byId = $this->cardsById($cards);
        $max = 0;
        $current = 0;
        $previous = null;

        foreach ($orderedIds as $id) {
            $type = $byId[$id]->getType();
            $current = $type === $previous ? $current + 1 : 1;
            $max = max($max, $current);
            $previous = $type;
        }

        return $max;
    }

    /**
     * @param list<LearningCard> $cards
     *
     * @return array<int, LearningCard>
     */
    private function cardsById(array $cards): array
    {
        $byId = [];
        foreach ($cards as $card) {
            $id = $card->getId();
            self::assertNotNull($id);
            $byId[$id] = $card;
        }

        return $byId;
    }
}

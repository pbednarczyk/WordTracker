<?php

declare(strict_types=1);

namespace App\Tests;

use App\Application\EnrichPublicationVocabularyHandler;
use App\Application\LearningCardGenerator;
use App\Entity\LearningCard;
use App\Entity\LearningReview;
use App\Enum\LearningCardType;
use App\Enum\ReviewRating;
use App\Enrichment\EnrichmentPersister;
use App\Enrichment\EnrichmentRequestFactory;
use App\Enrichment\VocabularyEnrichmentProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
use App\Entity\VocabularyItem;
use App\Entity\VocabularyOccurrence;
use App\Enum\PublicationType;
use App\Enrichment\VocabularyEnrichmentException;
use App\Enrichment\VocabularyEnrichmentResult;
use App\Tests\Double\ConfigurableVocabularyEnrichmentProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EnrichPublicationVocabularyHandlerTest extends KernelTestCase
{
    use DatabaseResetTrait;

    private EntityManagerInterface $entityManager;
    private EnrichPublicationVocabularyHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $handler = self::getContainer()->get(EnrichPublicationVocabularyHandler::class);
        self::assertInstanceOf(EnrichPublicationVocabularyHandler::class, $handler);
        $this->handler = $handler;

        $this->resetDatabase();
        ConfigurableVocabularyEnrichmentProvider::reset();
    }

    public function testSuccessfulEnrichmentPersistsContextSpecificFields(): void
    {
        $publicationVocabulary = $this->persistPublicationVocabulary(
            title: 'Reluctant Hero',
            lemma: 'reluctant',
            partOfSpeech: 'ADJ',
            originalForm: 'reluctant',
            sentence: 'He was reluctant to enter the cave.',
            position: 7,
        );
        ConfigurableVocabularyEnrichmentProvider::$result = $this->enrichmentResult('niechetny', 'hesitant to enter');

        $enrichment = ($this->handler)($publicationVocabulary);

        self::assertNotNull($enrichment->getId());
        self::assertSame('niechetny', $enrichment->getTranslationPl());
        self::assertSame('not willing or eager to do something', $enrichment->getDefinitionEn());
        self::assertSame('hesitant to enter', $enrichment->getMeaningInContext());
        self::assertSame('She was reluctant to speak.', $enrichment->getSimpleExample());
        self::assertSame('B2', $enrichment->getCefrLevel());
        self::assertSame('He was reluctant to enter the cave.', $enrichment->getSourceSentence());
        self::assertSame('reluctant', ConfigurableVocabularyEnrichmentProvider::$requests[0]->lemma);
        self::assertSame('ADJ', ConfigurableVocabularyEnrichmentProvider::$requests[0]->partOfSpeech);
        self::assertSame('reluctant', ConfigurableVocabularyEnrichmentProvider::$requests[0]->originalForm);
        self::assertSame('en', ConfigurableVocabularyEnrichmentProvider::$requests[0]->sourceLanguage);
        self::assertSame('pl', ConfigurableVocabularyEnrichmentProvider::$requests[0]->targetLanguage);
    }

    public function testRegenerationUpdatesExistingRecord(): void
    {
        $publicationVocabulary = $this->persistPublicationVocabulary(
            title: 'Regenerate',
            lemma: 'reluctant',
            partOfSpeech: 'ADJ',
            originalForm: 'reluctant',
            sentence: 'He was reluctant to enter the cave.',
            position: 7,
        );

        ConfigurableVocabularyEnrichmentProvider::$result = $this->enrichmentResult('translation A', 'meaning A');
        $first = ($this->handler)($publicationVocabulary);
        $firstId = $first->getId();

        ConfigurableVocabularyEnrichmentProvider::$result = $this->enrichmentResult('translation B', 'meaning B');
        $second = ($this->handler)($publicationVocabulary);

        self::assertSame($firstId, $second->getId());
        self::assertSame(1, $this->countRows('publication_vocabulary_enrichment'));
        self::assertSame('translation B', $second->getTranslationPl());
        self::assertSame('meaning B', $second->getMeaningInContext());
    }

    public function testSameVocabularyItemCanHaveDifferentPublicationSpecificEnrichments(): void
    {
        $item = new VocabularyItem('en', 'charge', 'NOUN');
        $firstPublicationVocabulary = $this->persistPublicationVocabularyWithItem(
            title: 'Hotel Article',
            item: $item,
            originalForm: 'charge',
            sentence: 'The hotel added a service charge.',
            position: 26,
        );
        $secondPublicationVocabulary = $this->persistPublicationVocabularyWithItem(
            title: 'Police Article',
            item: $item,
            originalForm: 'charge',
            sentence: 'The police filed a criminal charge.',
            position: 28,
        );

        ConfigurableVocabularyEnrichmentProvider::$result = $this->enrichmentResult('oplata', 'service fee meaning');
        ($this->handler)($firstPublicationVocabulary);

        ConfigurableVocabularyEnrichmentProvider::$result = $this->enrichmentResult('zarzut', 'criminal accusation meaning');
        ($this->handler)($secondPublicationVocabulary);

        self::assertSame(2, $this->countRows('publication_vocabulary_enrichment'));
        self::assertSame(['oplata', 'zarzut'], $this->enrichmentTranslations());
    }

    public function testProviderFailureDoesNotPersistPartialEnrichmentOrOverwriteExistingOne(): void
    {
        $publicationVocabulary = $this->persistPublicationVocabulary(
            title: 'Failure',
            lemma: 'reluctant',
            partOfSpeech: 'ADJ',
            originalForm: 'reluctant',
            sentence: 'He was reluctant to enter the cave.',
            position: 7,
        );
        ConfigurableVocabularyEnrichmentProvider::$result = $this->enrichmentResult('existing', 'existing meaning');
        ($this->handler)($publicationVocabulary);

        ConfigurableVocabularyEnrichmentProvider::$result = null;
        ConfigurableVocabularyEnrichmentProvider::$exception = new VocabularyEnrichmentException('Provider unavailable.');

        try {
            ($this->handler)($publicationVocabulary);
            self::fail('Expected provider failure.');
        } catch (VocabularyEnrichmentException $exception) {
            self::assertSame('Provider unavailable.', $exception->getMessage());
        }

        self::assertSame(1, $this->countRows('publication_vocabulary_enrichment'));
        self::assertSame('existing', $this->entityManager->getConnection()->fetchOne('SELECT translation_pl FROM publication_vocabulary_enrichment'));
    }

    public function testRegenerationSynchronizesContentAndPreservesIdentityReviewsAndScheduling(): void
    {
        $row = $this->persistPublicationVocabulary('Audience', 'audience', 'NOUN', 'audience', 'An audience waited.', 3);
        ConfigurableVocabularyEnrichmentProvider::$result = $this->enrichmentResult('publicum', 'old meaning');
        ($this->handler)($row);
        $generator = self::getContainer()->get(LearningCardGenerator::class);
        self::assertSame(3, $generator->generate($row)->created);
        $cards = $this->entityManager->getRepository(LearningCard::class)->findBy(['publicationVocabulary' => $row]);
        foreach ($cards as $position => $card) {
            $card->applyFsrsState(2, 4.5, 6.7, 3, 8, 12, 2, 1,
                new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-09-23'));
            $this->entityManager->persist(new LearningReview($card, ReviewRating::GOOD,
                new \DateTimeImmutable('2026-09-23'), 1200, 'sync-test-session', $position));
        }
        $this->entityManager->flush();
        $before = $this->cardRows();
        $reviews = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM learning_review ORDER BY id');
        $newSentence = 'The audience applauded loudly.';
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE vocabulary_occurrence SET sentence = ? WHERE publication_id = ?',
            [$newSentence, $row->getPublication()->getId()],
        );
        $rowId = $row->getId();
        $this->entityManager->clear();
        $row = $this->entityManager->find(PublicationVocabulary::class, $rowId);
        ConfigurableVocabularyEnrichmentProvider::$result = $this->enrichmentResult('publiczność', 'people watching a performance');
        ($this->handler)($row);
        // Repeating synchronization and generation must never create extra cards.
        $generator->synchronize($row);
        $this->entityManager->flush();
        $generation = $generator->generate($row);
        self::assertSame(0, $generation->created);
        self::assertSame(3, $generation->existing);
        $after = $this->cardRows();
        self::assertCount(3, $after);
        foreach ($after as $index => $card) {
            $expected = match ($card['type']) {
                'FORWARD' => ['audience', 'publiczność'],
                'REVERSE' => ["Recall the target English word:\n\npubliczność", 'audience'],
                'CONTEXT_MEANING' => ["What does \"audience\" mean in this context?\n\n\"$newSentence\"", 'people watching a performance'],
            };
            self::assertSame($expected, [$card['front'], $card['back']]);
            self::assertSame($newSentence, $card['context_sentence']);
            // Compare every persisted non-content column, including IDs, relations,
            // creation time, activation and all FSRS fields.
            foreach (['front', 'back', 'context_sentence', 'updated_at'] as $field) {
                unset($card[$field], $before[$index][$field]);
            }
            self::assertSame($before[$index], $card);
        }
        self::assertSame($reviews, $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM learning_review ORDER BY id'));
    }

    public function testSynchronizationLeavesInactiveClozeAndOtherContextsUntouchedAndDoesNotGenerateMissingTypes(): void
    {
        $row = $this->persistPublicationVocabulary('First', 'audience', 'NOUN', 'audience', 'An audience waited.', 3);
        $other = $this->persistPublicationVocabularyWithItem('Other', $row->getVocabularyItem(), 'audience', 'Another audience.', 8);
        ConfigurableVocabularyEnrichmentProvider::$result = $this->enrichmentResult('publicum', 'old meaning');
        ($this->handler)($row);
        ($this->handler)($other);
        $generator = self::getContainer()->get(LearningCardGenerator::class);
        $generator->generate($other);
        $inactive = new LearningCard($row->getVocabularyItem(), $row, $row->getEnrichment(), LearningCardType::FORWARD, 'audience', 'publicum');
        $inactive->deactivate();
        $cloze = new LearningCard($row->getVocabularyItem(), $row, $row->getEnrichment(), LearningCardType::CLOZE, 'custom cloze', 'audience', 'An audience waited.', 'An [...] waited.');
        $this->entityManager->persist($inactive);
        $this->entityManager->persist($cloze);
        $this->entityManager->flush();
        $before = $this->cardRows();
        ConfigurableVocabularyEnrichmentProvider::$result = $this->enrichmentResult('publiczność', 'new meaning');
        ($this->handler)($row);
        self::assertSame($before, $this->cardRows());
        $generation = $generator->generate($row);
        self::assertSame(2, $generation->created);
        self::assertSame(1, $generation->existing);
        self::assertFalse($inactive->isActive());
        self::assertSame(0, $generator->generate($row)->created);
    }

    public function testFailedEnrichmentSaveLeavesCardContentUntouched(): void
    {
        $row = $this->persistPublicationVocabulary('Save failure', 'audience', 'NOUN', 'audience', 'An audience waited.', 3);
        ConfigurableVocabularyEnrichmentProvider::$result = $this->enrichmentResult('publicum', 'old meaning');
        ($this->handler)($row);
        self::getContainer()->get(LearningCardGenerator::class)->generate($row);
        $cards = $this->entityManager->getRepository(LearningCard::class)->findBy(['publicationVocabulary' => $row]);
        $content = array_map(static fn (LearningCard $card): array => [$card->getFront(), $card->getBack()], $cards);
        $before = $this->cardRows();
        ConfigurableVocabularyEnrichmentProvider::$result = new VocabularyEnrichmentResult(
            translationPl: 'publiczność', definitionEn: 'new definition', meaningInContext: 'new meaning',
            simpleExample: 'An audience gathered.', cefrLevel: 'B2', provider: 'test',
            model: str_repeat('x', 129), // Exceeds the persisted model column length.
        );
        try {
            ($this->handler)($row);
            self::fail('Expected the enrichment save to fail.');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
            self::assertSame($before, $this->cardRows());
            self::assertSame($content, array_map(static fn (LearningCard $card): array => [$card->getFront(), $card->getBack()], $cards));
            self::assertSame('publicum', $this->entityManager->getConnection()->fetchOne('SELECT translation_pl FROM publication_vocabulary_enrichment'));
        }
    }

    #[DataProvider('rejectedEnrichments')]
    public function testRejectedEnrichmentDoesNotTouchCards(string $reason): void
    {
        $row = $this->persistPublicationVocabulary('Rejected', 'audience', 'NOUN', 'audience', 'An audience waited.', 3);
        ConfigurableVocabularyEnrichmentProvider::$result = $this->enrichmentResult('publicum', 'old meaning');
        ($this->handler)($row);
        $generator = self::getContainer()->get(LearningCardGenerator::class);
        $generator->generate($row);
        $before = $this->cardRows();
        $provider = $this->createMock(VocabularyEnrichmentProviderInterface::class);
        $provider->method('enrich')->willReturnCallback(function () use ($row, $reason): VocabularyEnrichmentResult {
            if ($reason === 'failed') {
                throw new VocabularyEnrichmentException('Provider unavailable.');
            }
            if ($reason === 'removed') {
                $row->softDelete(new \DateTimeImmutable());
            } else {
                $row->beginEnrichmentRequest();
            }
            $this->entityManager->flush();
            return $this->enrichmentResult('publiczność', 'new meaning');
        });
        $handler = new EnrichPublicationVocabularyHandler(
            self::getContainer()->get(EnrichmentRequestFactory::class),
            self::getContainer()->get(EnrichmentPersister::class),
            $provider, $this->entityManager, $generator,
        );
        try {
            $handler($row);
            self::fail('Expected enrichment rejection.');
        } catch (VocabularyEnrichmentException) {
            self::assertSame($before, $this->cardRows());
        }
    }

    public static function rejectedEnrichments(): iterable
    {
        yield 'provider failure' => ['failed'];
        yield 'superseded request' => ['superseded'];
        yield 'removed vocabulary' => ['removed'];
    }

    private function cardRows(): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM learning_card ORDER BY id');
    }

    private function persistPublicationVocabulary(
        string $title,
        string $lemma,
        string $partOfSpeech,
        string $originalForm,
        string $sentence,
        int $position,
    ): PublicationVocabulary {
        return $this->persistPublicationVocabularyWithItem(
            title: $title,
            item: new VocabularyItem('en', $lemma, $partOfSpeech),
            originalForm: $originalForm,
            sentence: $sentence,
            position: $position,
        );
    }

    private function persistPublicationVocabularyWithItem(
        string $title,
        VocabularyItem $item,
        string $originalForm,
        string $sentence,
        int $position,
    ): PublicationVocabulary {
        $publication = new Publication($title, PublicationType::ARTICLE, 'en', rawText: $sentence);
        $publication->markAnalyzed();
        $publicationVocabulary = new PublicationVocabulary($publication, $item, 1);

        $this->entityManager->persist($publication);
        $this->entityManager->persist($item);
        $this->entityManager->persist($publicationVocabulary);
        $this->entityManager->persist(new VocabularyOccurrence($publication, $item, $originalForm, $sentence, $position));
        $this->entityManager->flush();

        return $publicationVocabulary;
    }

    private function enrichmentResult(string $translation, string $meaning): VocabularyEnrichmentResult
    {
        return new VocabularyEnrichmentResult(
            translationPl: $translation,
            definitionEn: 'not willing or eager to do something',
            meaningInContext: $meaning,
            simpleExample: 'She was reluctant to speak.',
            cefrLevel: 'B2',
            provider: 'test',
            model: 'fake',
            promptVersion: 'word-enrichment-v1',
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
    }

    /**
     * @return list<string>
     */
    private function enrichmentTranslations(): array
    {
        return $this->entityManager->getConnection()->fetchFirstColumn('SELECT translation_pl FROM publication_vocabulary_enrichment ORDER BY translation_pl ASC');
    }
}

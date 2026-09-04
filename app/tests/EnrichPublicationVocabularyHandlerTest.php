<?php

declare(strict_types=1);

namespace App\Tests;

use App\Application\EnrichPublicationVocabularyHandler;
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

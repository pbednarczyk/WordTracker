<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
use App\Entity\VocabularyItem;
use App\Entity\VocabularyOccurrence;
use App\Enum\PublicationType;
use App\Enum\VocabularyStatus;
use App\Repository\PublicationVocabularyRepository;
use App\Repository\VocabularyItemRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DomainModelTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $this->entityManager = $entityManager;
        $this->resetDatabase();
    }

    public function testPublicationCanBeCreatedWithNullableAuthorAndAnalyzedAt(): void
    {
        $publication = new Publication(
            title: 'The Hobbit',
            type: PublicationType::BOOK,
            language: 'en',
        );

        $this->entityManager->persist($publication);
        $this->entityManager->flush();

        self::assertNotNull($publication->getId());
        self::assertSame('The Hobbit', $publication->getTitle());
        self::assertSame(PublicationType::BOOK, $publication->getType());
        self::assertSame('en', $publication->getLanguage());
        self::assertNull($publication->getAuthor());
        self::assertNull($publication->getAnalyzedAt());
        self::assertInstanceOf(\DateTimeImmutable::class, $publication->getCreatedAt());
    }

    public function testVocabularyItemStatusAndIdentityFinder(): void
    {
        $item = new VocabularyItem('en', 'corridor', 'NOUN');

        self::assertSame(VocabularyStatus::UNKNOWN, $item->getStatus());

        $item->markKnown();

        $this->entityManager->persist($item);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $repository = self::getContainer()->get(VocabularyItemRepository::class);
        self::assertInstanceOf(VocabularyItemRepository::class, $repository);

        $found = $repository->findOneByIdentity('en', 'corridor', 'NOUN');

        self::assertNotNull($found);
        self::assertSame(VocabularyStatus::KNOWN, $found->getStatus());
    }

    public function testVocabularyItemIdentityIsUnique(): void
    {
        $this->entityManager->persist(new VocabularyItem('en', 'run', 'VERB'));
        $this->entityManager->persist(new VocabularyItem('en', 'run', 'VERB'));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->entityManager->flush();
    }

    public function testVocabularyOccurrenceCanBePersistedAndIsRemovedWithPublication(): void
    {
        $publication = new Publication('Article', PublicationType::ARTICLE);
        $item = new VocabularyItem('en', 'liberty', 'NOUN');
        $occurrence = new VocabularyOccurrence(
            publication: $publication,
            vocabularyItem: $item,
            originalForm: 'Liberty',
            sentence: 'Liberty is a symbol.',
            position: 0,
        );

        $this->entityManager->persist($publication);
        $this->entityManager->persist($item);
        $this->entityManager->persist($occurrence);
        $this->entityManager->flush();

        $occurrenceId = $occurrence->getId();
        self::assertNotNull($occurrenceId);

        $this->entityManager->remove($publication);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertNull($this->entityManager->find(VocabularyOccurrence::class, $occurrenceId));
    }

    public function testPublicationVocabularyIsUniqueAndPublicationDeleteKeepsVocabularyItem(): void
    {
        $publication = new Publication('Comic', PublicationType::COMIC);
        $item = new VocabularyItem('en', 'run', 'VERB');
        $publicationVocabulary = new PublicationVocabulary($publication, $item, 4);

        $this->entityManager->persist($publication);
        $this->entityManager->persist($item);
        $this->entityManager->persist($publicationVocabulary);
        $this->entityManager->flush();

        $itemId = $item->getId();
        $publicationVocabularyId = $publicationVocabulary->getId();
        self::assertNotNull($itemId);
        self::assertNotNull($publicationVocabularyId);

        $this->entityManager->remove($publication);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertNull($this->entityManager->find(PublicationVocabulary::class, $publicationVocabularyId));
        self::assertNotNull($this->entityManager->find(VocabularyItem::class, $itemId));
    }

    public function testPublicationVocabularyIdentityIsUnique(): void
    {
        $publication = new Publication('Document', PublicationType::DOCUMENT);
        $item = new VocabularyItem('en', 'light', 'NOUN');

        $this->entityManager->persist($publication);
        $this->entityManager->persist($item);
        $this->entityManager->persist(new PublicationVocabulary($publication, $item, 1));
        $this->entityManager->persist(new PublicationVocabulary($publication, $item, 2));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->entityManager->flush();
    }

    public function testPublicationVocabularyCoverageStatsUseGlobalVocabularyStatus(): void
    {
        $publication = new Publication('Coverage', PublicationType::ARTICLE);
        $the = $this->vocabularyItem('the', 'DET', VocabularyStatus::KNOWN);
        $run = $this->vocabularyItem('run', 'VERB', VocabularyStatus::KNOWN);
        $reluctant = $this->vocabularyItem('reluctant', 'ADJ', VocabularyStatus::UNKNOWN);
        $hero = $this->vocabularyItem('hero', 'NOUN', VocabularyStatus::UNKNOWN);

        $this->entityManager->persist($publication);
        $this->entityManager->persist(new PublicationVocabulary($publication, $the, 10));
        $this->entityManager->persist(new PublicationVocabulary($publication, $run, 5));
        $this->entityManager->persist(new PublicationVocabulary($publication, $reluctant, 2));
        $this->entityManager->persist(new PublicationVocabulary($publication, $hero, 3));
        $this->entityManager->flush();

        $repository = self::getContainer()->get(PublicationVocabularyRepository::class);
        self::assertInstanceOf(PublicationVocabularyRepository::class, $repository);

        self::assertSame([
            'uniqueTotal' => 4,
            'uniqueKnown' => 2,
            'uniqueUnknown' => 2,
            'occurrencesTotal' => 20,
            'occurrencesKnown' => 15,
            'occurrencesUnknown' => 5,
        ], $repository->getCoverageStats($publication));
    }

    public function testVocabularyStatusIsGlobalAcrossPublications(): void
    {
        $publicationA = new Publication('Publication A', PublicationType::ARTICLE);
        $publicationB = new Publication('Publication B', PublicationType::ARTICLE);
        $run = new VocabularyItem('en', 'run', 'VERB');

        $this->entityManager->persist($publicationA);
        $this->entityManager->persist($publicationB);
        $this->entityManager->persist($run);
        $this->entityManager->persist(new PublicationVocabulary($publicationA, $run, 2));
        $this->entityManager->persist(new PublicationVocabulary($publicationB, $run, 5));
        $this->entityManager->flush();

        $run->markKnown();
        $this->entityManager->flush();

        $repository = self::getContainer()->get(PublicationVocabularyRepository::class);
        self::assertInstanceOf(PublicationVocabularyRepository::class, $repository);

        self::assertSame(VocabularyStatus::KNOWN, $repository->findForPublicationOrdered($publicationA)[0]->getVocabularyItem()->getStatus());
        self::assertSame(VocabularyStatus::KNOWN, $repository->findForPublicationOrdered($publicationB)[0]->getVocabularyItem()->getStatus());
        self::assertSame(1, $repository->getCoverageStats($publicationA)['uniqueKnown']);
        self::assertSame(1, $repository->getCoverageStats($publicationB)['uniqueKnown']);
        self::assertSame(2, $repository->getCoverageStats($publicationA)['occurrencesKnown']);
        self::assertSame(5, $repository->getCoverageStats($publicationB)['occurrencesKnown']);
    }

    private function vocabularyItem(string $lemma, string $partOfSpeech, VocabularyStatus $status): VocabularyItem
    {
        $item = new VocabularyItem('en', $lemma, $partOfSpeech);
        if ($status === VocabularyStatus::KNOWN) {
            $item->markKnown();
        }

        $this->entityManager->persist($item);

        return $item;
    }

    private function resetDatabase(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'TRUNCATE TABLE publication_vocabulary, vocabulary_occurrence, vocabulary_item, publication RESTART IDENTITY CASCADE',
        );
    }
}

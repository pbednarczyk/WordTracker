<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
use App\Entity\VocabularyItem;
use App\Entity\VocabularyOccurrence;
use App\Enum\PublicationType;
use App\Enum\VocabularyStatus;
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

    private function resetDatabase(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'TRUNCATE TABLE publication_vocabulary, vocabulary_occurrence, vocabulary_item, publication RESTART IDENTITY CASCADE',
        );
    }
}

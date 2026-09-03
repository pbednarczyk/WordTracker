<?php

declare(strict_types=1);

namespace App\Tests;

use App\Application\AnalyzePublicationHandler;
use App\Application\PublicationAnalysisException;
use App\Entity\Publication;
use App\Entity\VocabularyItem;
use App\Enum\PublicationType;
use App\Enum\VocabularyStatus;
use App\Nlp\AnalyzedToken;
use App\Nlp\TextAnalysis;
use App\Nlp\TextAnalyzerException;
use App\Nlp\TextAnalyzerInterface;
use App\Repository\VocabularyItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AnalyzePublicationHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private VocabularyItemRepository $vocabularyItemRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $repository = self::getContainer()->get(VocabularyItemRepository::class);
        self::assertInstanceOf(VocabularyItemRepository::class, $repository);
        $this->vocabularyItemRepository = $repository;

        $this->resetDatabase();
    }

    public function testPersistsVocabularyAnalysisAndSkipsNamedEntities(): void
    {
        $publication = $this->persistPublication('Reluctant Hero Ethics. Tony Stark visited Paris.');
        $handler = $this->handler(new StaticAnalyzer([
            $this->token('Reluctant', 'Reluctant', 'PROPN', 0),
            $this->token('Hero', 'Hero', 'PROPN', 10),
            $this->token('Ethics', 'Ethics', 'PROPN', 15),
            $this->token('Tony', 'Tony', 'PROPN', 23, 'PERSON'),
            $this->token('Stark', 'Stark', 'PROPN', 28, 'PERSON'),
            $this->token('visited', 'visit', 'VERB', 34),
            $this->token('Paris', 'Paris', 'PROPN', 42, 'GPE'),
        ]));

        $result = $handler($publication);
        $this->entityManager->clear();

        self::assertSame(7, $result->tokenCount);
        self::assertSame(7, $result->wordCount);
        self::assertSame(4, $result->vocabularyOccurrences);
        self::assertSame(4, $result->uniqueVocabularyItems);
        self::assertSame(3, $result->ignoredNamedEntities);
        self::assertSame(4, $this->countRows('vocabulary_occurrence'));
        self::assertSame(4, $this->countRows('publication_vocabulary'));
        self::assertSame(4, $this->countRows('vocabulary_item'));
        self::assertNotNull($this->vocabularyItemRepository->findOneByIdentity('en', 'Reluctant', 'PROPN'));
        self::assertNotNull($this->vocabularyItemRepository->findOneByIdentity('en', 'Hero', 'PROPN'));
        self::assertNotNull($this->vocabularyItemRepository->findOneByIdentity('en', 'Ethics', 'PROPN'));
        self::assertNotNull($this->vocabularyItemRepository->findOneByIdentity('en', 'visit', 'VERB'));
        self::assertNull($this->vocabularyItemRepository->findOneByIdentity('en', 'Tony', 'PROPN'));
        self::assertNull($this->vocabularyItemRepository->findOneByIdentity('en', 'Stark', 'PROPN'));
        self::assertNull($this->vocabularyItemRepository->findOneByIdentity('en', 'Paris', 'PROPN'));

        $reloadedPublication = $this->entityManager->find(Publication::class, $publication->getId());
        self::assertNotNull($reloadedPublication?->getAnalyzedAt());
    }

    public function testReanalysisReplacesPublicationRowsAndKeepsExistingVocabularyStatus(): void
    {
        $publication = $this->persistPublication('Running, then corridor.');
        $analyzer = new MutableAnalyzer([
            $this->token('Running', 'run', 'VERB', 0),
            $this->token('running', 'run', 'VERB', 10),
        ]);
        $handler = $this->handler($analyzer);

        $handler($publication);
        $run = $this->vocabularyItemRepository->findOneByIdentity('en', 'run', 'VERB');
        self::assertNotNull($run);
        $run->markKnown();
        $this->entityManager->flush();

        $analyzer->tokens = [
            $this->token('corridor', 'corridor', 'NOUN', 14),
        ];
        $result = $handler($publication);
        $this->entityManager->clear();

        self::assertSame(1, $result->vocabularyOccurrences);
        self::assertSame(1, $result->uniqueVocabularyItems);
        self::assertSame(1, $this->countRows('vocabulary_occurrence'));
        self::assertSame(1, $this->countRows('publication_vocabulary'));
        self::assertSame(2, $this->countRows('vocabulary_item'));

        $knownRun = $this->vocabularyItemRepository->findOneByIdentity('en', 'run', 'VERB');
        self::assertSame(VocabularyStatus::KNOWN, $knownRun?->getStatus());
        self::assertNull($this->findPublicationVocabulary($publication->getId(), 'run'));
        self::assertSame(1, $this->findPublicationVocabulary($publication->getId(), 'corridor'));
    }

    public function testReanalysisDoesNotResetStatusForVocabularyStillInPublication(): void
    {
        $publication = $this->persistPublication('Running again.');
        $analyzer = new MutableAnalyzer([
            $this->token('Running', 'run', 'VERB', 0),
        ]);
        $handler = $this->handler($analyzer);

        $handler($publication);
        $run = $this->vocabularyItemRepository->findOneByIdentity('en', 'run', 'VERB');
        self::assertNotNull($run);
        $run->markKnown();
        $this->entityManager->flush();

        $analyzer->tokens = [
            $this->token('Running', 'run', 'VERB', 0),
            $this->token('again', 'again', 'ADV', 8),
        ];

        $handler($publication);
        $this->entityManager->clear();

        $knownRun = $this->vocabularyItemRepository->findOneByIdentity('en', 'run', 'VERB');
        self::assertSame(VocabularyStatus::KNOWN, $knownRun?->getStatus());
        self::assertSame(1, $this->findPublicationVocabulary($publication->getId(), 'run'));
        self::assertSame(1, $this->findPublicationVocabulary($publication->getId(), 'again'));
    }

    public function testNlpFailureDoesNotPersistAnalysis(): void
    {
        $publication = $this->persistPublication('Nothing should be stored.');
        $publicationId = $publication->getId();
        $handler = $this->handler(new FailingAnalyzer());

        try {
            $handler($publication);
            self::fail('Expected NLP failure.');
        } catch (TextAnalyzerException $exception) {
            self::assertSame('NLP unavailable.', $exception->getMessage());
        }

        $this->entityManager->clear();

        self::assertSame(0, $this->countRows('vocabulary_occurrence'));
        self::assertSame(0, $this->countRows('publication_vocabulary'));
        self::assertSame(0, $this->countRows('vocabulary_item'));

        $reloadedPublication = $this->entityManager->find(Publication::class, $publicationId);
        self::assertNull($reloadedPublication?->getAnalyzedAt());
    }

    public function testPersistenceFailureRollsBackPreviousAnalysis(): void
    {
        $publication = $this->persistPublication('Old analysis should survive failed reanalysis.');
        $publicationId = $publication->getId();
        $analyzer = new MutableAnalyzer([
            $this->token('Running', 'run', 'VERB', 0),
        ]);
        $handler = $this->handler($analyzer);

        $handler($publication);
        $oldAnalyzedAt = $publication->getAnalyzedAt();
        self::assertNotNull($oldAnalyzedAt);

        $analyzer->tokens = [
            $this->token('corridor', 'corridor', 'NOUN', 14),
            $this->token('broken', '', 'ADJ', 23),
        ];

        try {
            $handler($publication);
            self::fail('Expected persistence validation failure.');
        } catch (PublicationAnalysisException $exception) {
            self::assertSame('NLP analysis contains a token with empty language, lemma, or POS.', $exception->getMessage());
        }

        $this->entityManager->clear();

        self::assertSame(1, $this->countRows('vocabulary_occurrence'));
        self::assertSame(1, $this->countRows('publication_vocabulary'));
        self::assertSame(1, $this->countRows('vocabulary_item'));
        self::assertSame(1, $this->findPublicationVocabulary($publicationId, 'run'));
        self::assertNull($this->findPublicationVocabulary($publicationId, 'corridor'));

        $reloadedPublication = $this->entityManager->find(Publication::class, $publicationId);
        self::assertSame(
            $oldAnalyzedAt->format('Y-m-d H:i:s'),
            $reloadedPublication?->getAnalyzedAt()?->format('Y-m-d H:i:s'),
        );
    }

    private function handler(TextAnalyzerInterface $analyzer): AnalyzePublicationHandler
    {
        return new AnalyzePublicationHandler(
            $analyzer,
            $this->entityManager,
            $this->vocabularyItemRepository,
        );
    }

    private function persistPublication(string $rawText): Publication
    {
        $publication = new Publication(
            title: 'Fixture',
            type: PublicationType::ARTICLE,
            language: 'en',
            rawText: $rawText,
        );

        $this->entityManager->persist($publication);
        $this->entityManager->flush();

        return $publication;
    }

    private function token(
        string $text,
        string $lemma,
        string $pos,
        int $position,
        ?string $entityType = null,
        bool $isProperNoun = false,
    ): AnalyzedToken {
        return new AnalyzedToken(
            text: $text,
            lemma: $lemma,
            pos: $pos,
            entityType: $entityType,
            sentence: 'The children were running down the corridor.',
            position: $position,
            isProperNoun: $isProperNoun,
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
    }

    private function findPublicationVocabulary(?int $publicationId, string $lemma): ?int
    {
        $occurrences = $this->entityManager->getConnection()->fetchOne(
            <<<'SQL'
                SELECT pv.occurrences
                FROM publication_vocabulary pv
                INNER JOIN vocabulary_item vi ON vi.id = pv.vocabulary_item_id
                WHERE pv.publication_id = :publication_id AND vi.lemma = :lemma
                SQL,
            [
                'publication_id' => $publicationId,
                'lemma' => $lemma,
            ],
        );

        return $occurrences === false ? null : (int) $occurrences;
    }

    private function resetDatabase(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'TRUNCATE TABLE publication_vocabulary, vocabulary_occurrence, vocabulary_item, publication RESTART IDENTITY CASCADE',
        );
    }
}

final class StaticAnalyzer implements TextAnalyzerInterface
{
    /**
     * @param list<AnalyzedToken> $tokens
     */
    public function __construct(private readonly array $tokens)
    {
    }

    public function analyze(string $text): TextAnalysis
    {
        return new TextAnalysis(
            language: 'en',
            tokenCount: count($this->tokens),
            wordCount: count($this->tokens),
            uniqueLemmaCount: count(array_unique(array_map(
                static fn (AnalyzedToken $token): string => $token->lemma,
                $this->tokens,
            ))),
            tokens: $this->tokens,
        );
    }
}

final class MutableAnalyzer implements TextAnalyzerInterface
{
    /**
     * @param list<AnalyzedToken> $tokens
     */
    public function __construct(public array $tokens)
    {
    }

    public function analyze(string $text): TextAnalysis
    {
        return (new StaticAnalyzer($this->tokens))->analyze($text);
    }
}

final class FailingAnalyzer implements TextAnalyzerInterface
{
    public function analyze(string $text): TextAnalysis
    {
        throw new TextAnalyzerException('NLP unavailable.');
    }
}

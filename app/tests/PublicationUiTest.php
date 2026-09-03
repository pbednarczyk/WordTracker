<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
use App\Entity\VocabularyItem;
use App\Enum\PublicationType;
use App\Enum\VocabularyStatus;
use App\Nlp\AnalyzedToken;
use App\Nlp\TextAnalysis;
use App\Nlp\TextAnalyzerInterface;
use App\Tests\Double\ConfigurableTextAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicationUiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->resetDatabase();
        ConfigurableTextAnalyzer::$analysis = null;
    }

    public function testPublicationListLoads(): void
    {
        $this->client->request('GET', '/publications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Publications');
    }

    public function testNewPublicationFormLoads(): void
    {
        $this->client->request('GET', '/publications/new');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Add publication');
        self::assertSelectorExists('textarea[name="publication_form[rawText]"]');
    }

    public function testCreatePublicationRedirectsToDetails(): void
    {
        $crawler = $this->client->request('GET', '/publications/new');

        $form = $crawler->selectButton('Create')->form([
            'publication_form[title]' => 'The arm of Liberty',
            'publication_form[author]' => 'Sample Author',
            'publication_form[type]' => 'ARTICLE',
            'publication_form[language]' => 'en',
            'publication_form[rawText]' => 'The children were running down the corridor.',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->client->followRedirect();

        self::assertSelectorTextContains('h1', 'The arm of Liberty');
        self::assertSame(1, $this->countRows('publication'));
    }

    public function testCreatePublicationRejectsBlankText(): void
    {
        $crawler = $this->client->request('GET', '/publications/new');

        $form = $crawler->selectButton('Create')->form([
            'publication_form[title]' => 'Blank text',
            'publication_form[type]' => 'ARTICLE',
            'publication_form[language]' => 'en',
            'publication_form[rawText]' => '',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Paste text to analyze.');
        self::assertSame(0, $this->countRows('publication'));
    }

    public function testPublicationDetailsDisplayTitle(): void
    {
        $publication = $this->persistPublication('Details sample');

        $this->client->request('GET', '/publications/'.$publication->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Details sample');
        self::assertSelectorTextContains('body', 'Not analyzed');
    }

    public function testAnalyzePublicationDisplaysVocabulary(): void
    {
        $publication = $this->persistPublication('The children were running down the corridor.');
        $this->clientWithAnalyzer(new UiStaticAnalyzer([
            $this->token('children', 'child', 'NOUN', 4),
            $this->token('running', 'run', 'VERB', 18),
            $this->token('corridor', 'corridor', 'NOUN', 35),
        ]));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId());
        $this->client->submit($crawler->selectButton('Analyze publication')->form());

        self::assertResponseRedirects('/publications/'.$publication->getId());
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'Publication analyzed successfully.');
        self::assertSelectorTextContains('body', 'Vocabulary');
        self::assertSelectorTextContains('body', 'child');
        self::assertSelectorTextContains('body', 'NOUN');
        self::assertSelectorTextContains('body', 'UNKNOWN');
        self::assertSame(3, $this->countRows('vocabulary_occurrence'));
        self::assertSame(3, $this->countRows('publication_vocabulary'));
    }

    public function testReAnalyzePublicationUsesSamePostAction(): void
    {
        $publication = $this->persistPublication('Running and corridor.');
        $this->clientWithAnalyzer(new UiStaticAnalyzer([
            $this->token('Running', 'run', 'VERB', 0),
        ]));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId());
        $this->client->submit($crawler->selectButton('Analyze publication')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('button', 'Re-analyze');
        self::assertSame(1, $this->countRows('vocabulary_occurrence'));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId());
        $this->client->submit($crawler->selectButton('Re-analyze')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'Publication analyzed successfully.');
        self::assertSame(1, $this->countRows('vocabulary_occurrence'));
        self::assertSame(1, $this->countRows('publication_vocabulary'));
    }

    public function testSingleVocabularyStatusCanBeMarkedKnownAndUnknown(): void
    {
        $publication = $this->persistAnalyzedPublication('Vocabulary actions');
        $item = $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 2);

        $this->client->request('POST', '/vocabulary/'.$item->getId().'/status', [
            '_token' => $this->singleStatusToken($publication, $item),
            'publicationId' => $publication->getId(),
            'status' => 'KNOWN',
        ]);
        self::assertResponseRedirects('/publications/'.$publication->getId());
        self::assertSame('KNOWN', $this->vocabularyStatus($item));

        $this->client->request('POST', '/vocabulary/'.$item->getId().'/status', [
            '_token' => $this->singleStatusToken($publication, $item),
            'publicationId' => $publication->getId(),
            'status' => 'UNKNOWN',
        ]);
        self::assertResponseRedirects('/publications/'.$publication->getId());
        self::assertSame('UNKNOWN', $this->vocabularyStatus($item));
    }

    public function testBulkVocabularyStatusCanBeMarkedKnown(): void
    {
        $publication = $this->persistAnalyzedPublication('Bulk actions');
        $first = $this->persistVocabularyRow($publication, 'the', 'DET', 10);
        $second = $this->persistVocabularyRow($publication, 'run', 'VERB', 5);
        $third = $this->persistVocabularyRow($publication, 'hero', 'NOUN', 3);

        $this->client->request('POST', '/vocabulary/bulk-status', [
            '_token' => $this->bulkStatusToken($publication),
            'publicationId' => $publication->getId(),
            'ids' => [$first->getId(), $second->getId(), $third->getId()],
            'status' => 'KNOWN',
        ]);

        self::assertResponseRedirects('/publications/'.$publication->getId());
        self::assertSame('KNOWN', $this->vocabularyStatus($first));
        self::assertSame('KNOWN', $this->vocabularyStatus($second));
        self::assertSame('KNOWN', $this->vocabularyStatus($third));
    }

    public function testBulkVocabularyStatusRequiresSelection(): void
    {
        $publication = $this->persistAnalyzedPublication('Empty bulk actions');
        $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 2);

        $this->client->request('POST', '/vocabulary/bulk-status', [
            '_token' => $this->bulkStatusToken($publication),
            'publicationId' => $publication->getId(),
            'status' => 'KNOWN',
        ]);

        self::assertResponseRedirects('/publications/'.$publication->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'No vocabulary items selected.');
    }

    public function testInvalidStatusDoesNotChangeVocabulary(): void
    {
        $publication = $this->persistAnalyzedPublication('Invalid status');
        $item = $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 2);

        $this->client->request('POST', '/vocabulary/'.$item->getId().'/status', [
            '_token' => $this->singleStatusToken($publication, $item),
            'publicationId' => $publication->getId(),
            'status' => 'LEARNING',
        ]);

        self::assertResponseRedirects('/publications/'.$publication->getId());
        self::assertSame('UNKNOWN', $this->vocabularyStatus($item));
    }

    public function testInvalidCsrfRejectsVocabularyStatusUpdate(): void
    {
        $publication = $this->persistAnalyzedPublication('Invalid CSRF');
        $item = $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 2);

        $this->client->request('POST', '/vocabulary/'.$item->getId().'/status', [
            '_token' => 'invalid',
            'publicationId' => $publication->getId(),
            'status' => 'KNOWN',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('UNKNOWN', $this->vocabularyStatus($item));
    }

    public function testVocabularyStatusFiltersAndSearchUseDatabaseResults(): void
    {
        $publication = $this->persistAnalyzedPublication('Filtered vocabulary');
        $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 2);
        $this->persistVocabularyRow($publication, 'hero', 'NOUN', 3, VocabularyStatus::KNOWN);
        $this->persistVocabularyRow($publication, 'running', 'VERB', 5);

        $this->client->request('GET', '/publications/'.$publication->getId().'?status=UNKNOWN');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('reluctant', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('running', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('<td>hero</td>', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/publications/'.$publication->getId().'?status=KNOWN');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('hero', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('<td>reluctant</td>', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/publications/'.$publication->getId().'?q=reluct');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('reluctant', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('<td>running</td>', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/publications/'.$publication->getId().'?status=UNKNOWN&q=run');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('running', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('<td>hero</td>', (string) $this->client->getResponse()->getContent());
    }

    public function testPublicationDetailsDisplayCoverageMetrics(): void
    {
        $publication = $this->persistAnalyzedPublication('Coverage UI');
        $this->persistVocabularyRow($publication, 'the', 'DET', 10, VocabularyStatus::KNOWN);
        $this->persistVocabularyRow($publication, 'run', 'VERB', 5, VocabularyStatus::KNOWN);
        $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 2);
        $this->persistVocabularyRow($publication, 'hero', 'NOUN', 3);

        $this->client->request('GET', '/publications/'.$publication->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Unique vocabulary');
        self::assertSelectorTextContains('body', 'Known');
        self::assertSelectorTextContains('body', 'Unknown');
        self::assertSelectorTextContains('body', 'Vocabulary coverage');
        self::assertSelectorTextContains('body', '50.0%');
        self::assertSelectorTextContains('body', 'Text coverage');
        self::assertSelectorTextContains('body', '75.0%');
    }

    private function clientWithAnalyzer(TextAnalyzerInterface $analyzer): void
    {
        ConfigurableTextAnalyzer::$analysis = $analyzer->analyze('test text');
    }

    private function persistPublication(string $title): Publication
    {
        $publication = new Publication(
            title: $title,
            type: PublicationType::ARTICLE,
            language: 'en',
            rawText: 'The children were running down the corridor.',
        );

        $this->entityManager->persist($publication);
        $this->entityManager->flush();

        return $publication;
    }

    private function persistAnalyzedPublication(string $title): Publication
    {
        $publication = $this->persistPublication($title);
        $publication->markAnalyzed();
        $this->entityManager->flush();

        return $publication;
    }

    private function persistVocabularyRow(
        Publication $publication,
        string $lemma,
        string $partOfSpeech,
        int $occurrences,
        VocabularyStatus $status = VocabularyStatus::UNKNOWN,
    ): VocabularyItem {
        $item = new VocabularyItem('en', $lemma, $partOfSpeech);
        if ($status === VocabularyStatus::KNOWN) {
            $item->markKnown();
        }

        $this->entityManager->persist($item);
        $this->entityManager->persist(new PublicationVocabulary($publication, $item, $occurrences));
        $this->entityManager->flush();

        return $item;
    }

    private function singleStatusToken(Publication $publication, VocabularyItem $item): string
    {
        $crawler = $this->client->request('GET', '/publications/'.$publication->getId());

        return (string) $crawler
            ->filter(sprintf('form[action="/vocabulary/%d/status"] input[name="_token"]', $item->getId()))
            ->attr('value');
    }

    private function bulkStatusToken(Publication $publication): string
    {
        $crawler = $this->client->request('GET', '/publications/'.$publication->getId());

        return (string) $crawler
            ->filter('form#bulk-status-form input[name="_token"]')
            ->attr('value');
    }

    private function vocabularyStatus(VocabularyItem $item): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne(
            'SELECT status FROM vocabulary_item WHERE id = :id',
            ['id' => $item->getId()],
        );
    }

    private function token(string $text, string $lemma, string $pos, int $position): AnalyzedToken
    {
        return new AnalyzedToken(
            text: $text,
            lemma: $lemma,
            pos: $pos,
            entityType: null,
            sentence: 'The children were running down the corridor.',
            position: $position,
            isProperNoun: false,
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
    }

    private function resetDatabase(): void
    {
        $this->entityManager->getConnection()->executeStatement(
            'TRUNCATE TABLE publication_vocabulary, vocabulary_occurrence, vocabulary_item, publication RESTART IDENTITY CASCADE',
        );
    }
}

final class UiStaticAnalyzer implements TextAnalyzerInterface
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

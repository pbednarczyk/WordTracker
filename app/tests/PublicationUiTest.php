<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Publication;
use App\Entity\LearningCard;
use App\Entity\LearningReview;
use App\Entity\PublicationVocabulary;
use App\Entity\PublicationVocabularyEnrichment;
use App\Entity\VocabularyItem;
use App\Entity\VocabularyOccurrence;
use App\Enum\LearningCardType;
use App\Enum\PublicationType;
use App\Enum\ReviewRating;
use App\Enum\VocabularyStatus;
use App\Enrichment\VocabularyEnrichmentException;
use App\Enrichment\VocabularyEnrichmentResult;
use App\Nlp\AnalyzedToken;
use App\Nlp\TextAnalysis;
use App\Nlp\TextAnalyzerInterface;
use App\Tests\Double\ConfigurableTextAnalyzer;
use App\Tests\Double\ConfigurableVocabularyEnrichmentProvider;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PublicationUiTest extends WebTestCase
{
    use DatabaseResetTrait;

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
        ConfigurableVocabularyEnrichmentProvider::reset();
    }

    public function testPublicationListLoads(): void
    {
        $this->client->request('GET', '/publications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Publications');
    }

    public function testPublicationListShowsVocabularyCoverageSummary(): void
    {
        $publication = $this->persistAnalyzedPublication('Coverage List');

        foreach ([14, 14, 14, 14, 13, 13, 13] as $index => $occurrences) {
            $this->persistVocabularyRow($publication, 'known-'.$index, 'NOUN', $occurrences, VocabularyStatus::KNOWN);
        }

        foreach ([2, 2, 1] as $index => $occurrences) {
            $this->persistVocabularyRow($publication, 'unknown-'.$index, 'NOUN', $occurrences);
        }

        $this->client->request('GET', '/publications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Coverage List');
        self::assertSelectorTextContains('body', 'Unknown: 3');
        self::assertSelectorTextContains('body', '70.0%');
        self::assertSelectorTextContains('body', '95.0%');
        self::assertStringContainsString('style="width: 70%', (string) $this->client->getResponse()->getContent());
    }

    public function testPublicationListShowsCompletedBadgeAtFullVocabularyCoverage(): void
    {
        $publication = $this->persistAnalyzedPublication('Completed Vocabulary');
        $this->persistVocabularyRow($publication, 'the', 'DET', 10, VocabularyStatus::KNOWN);
        $this->persistVocabularyRow($publication, 'hero', 'NOUN', 3, VocabularyStatus::KNOWN);

        $this->client->request('GET', '/publications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Completed Vocabulary');
        self::assertSelectorTextContains('body', '100.0%');
        self::assertSelectorTextContains('body', 'COMPLETED');
    }

    public function testPublicationListShowsNotAnalyzedWithoutMisleadingZeroCoverage(): void
    {
        $this->persistPublication('Unanalyzed List Item');

        $crawler = $this->client->request('GET', '/publications');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Unanalyzed List Item');
        self::assertSelectorTextContains('body', 'Not analyzed');
        self::assertStringNotContainsString('Unknown: 0', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('.progress__bar'));
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

    public function testFullPublicationTextPreservesLineBreaks(): void
    {
        $publication = $this->persistPublication('Line break text', "Line one.\n\nLine two.");

        $this->client->request('GET', '/publications/'.$publication->getId().'/text');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Line break text');
        self::assertStringContainsString("Line one.\n\nLine two.", (string) $this->client->getResponse()->getContent());
    }

    public function testFullPublicationTextDisplaysEmptyMessage(): void
    {
        $publication = $this->persistPublication('Missing raw text', null);

        $this->client->request('GET', '/publications/'.$publication->getId().'/text');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No publication text available.');
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

    public function testRemovePublicationVocabularyRejectsInvalidCsrf(): void
    {
        $publication = $this->persistAnalyzedPublication('Invalid remove CSRF');
        $item = $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 2);
        $publicationVocabulary = $this->publicationVocabularyFor($publication, $item);

        $this->client->request('POST', '/publication-vocabulary/'.$publicationVocabulary->getId().'/remove', [
            '_token' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->entityManager->getConnection()->fetchOne(
            'SELECT deleted_at FROM publication_vocabulary WHERE id = :id',
            ['id' => $publicationVocabulary->getId()],
        ));
    }

    public function testRemovePublicationVocabularySoftDeletesContextAndHidesItFromUserFacingViews(): void
    {
        $publication = $this->persistAnalyzedPublication('Soft delete source');
        $item = $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 2);
        $this->persistOccurrence($publication, $item, 'reluctant', 'The reluctant hero waited.', 4);
        $this->persistEnrichment($publication, $item, 'niechetny', 'hesitant in this context', 'The reluctant hero waited.');
        $this->entityManager->flush();
        $publicationVocabulary = $this->publicationVocabularyFor($publication, $item);

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId());
        $token = (string) $crawler
            ->filter(sprintf('form[action="/publication-vocabulary/%d/remove"] input[name="_token"]', $publicationVocabulary->getId()))
            ->attr('value');

        $this->client->request('POST', '/publication-vocabulary/'.$publicationVocabulary->getId().'/remove', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/publications/'.$publication->getId());
        self::assertSame(1, $this->countRows('publication_vocabulary'));
        self::assertSame(1, $this->countRows('vocabulary_item'));
        self::assertSame(1, $this->countRows('vocabulary_occurrence'));
        self::assertSame(1, $this->countRows('publication_vocabulary_enrichment'));
        self::assertNotNull($this->entityManager->getConnection()->fetchOne(
            'SELECT deleted_at FROM publication_vocabulary WHERE id = :id',
            ['id' => $publicationVocabulary->getId()],
        ));

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'No vocabulary items match the current filters.');

        $this->client->request('GET', '/vocabulary/'.$item->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'reluctant');
        self::assertSelectorTextContains('body', 'No publication-specific vocabulary entries available.');
        self::assertSelectorTextContains('body', 'No occurrence history available.');
        self::assertStringNotContainsString('niechetny', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/publications/'.$publication->getId().'/vocabulary/export.csv');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('reluctant', (string) $this->client->getResponse()->getContent());
    }

    public function testRemovingContextDoesNotAffectTheSameVocabularyItemInAnotherPublication(): void
    {
        $firstPublication = $this->persistAnalyzedPublication('First context');
        $secondPublication = $this->persistAnalyzedPublication('Second context');
        $item = $this->persistVocabularyRow($firstPublication, 'charge', 'NOUN', 1);
        $this->entityManager->persist(new PublicationVocabulary($secondPublication, $item, 1));
        $this->entityManager->flush();
        $removedContext = $this->publicationVocabularyFor($firstPublication, $item);

        $crawler = $this->client->request('GET', '/vocabulary/'.$item->getId());
        $token = (string) $crawler
            ->filter(sprintf('form[action="/publication-vocabulary/%d/remove"] input[name="_token"]', $removedContext->getId()))
            ->attr('value');
        $this->client->request('POST', '/publication-vocabulary/'.$removedContext->getId().'/remove', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/vocabulary/'.$item->getId());
        $this->client->followRedirect();
        self::assertStringNotContainsString('First context', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('body', 'Second context');
    }

    public function testRemovedContextPreservesLearningHistoryButExcludesCardsFromStudy(): void
    {
        $publication = $this->publicationWithGeneratedForwardCards(['alpha', 'beta']);
        $alpha = $this->entityManager->getRepository(VocabularyItem::class)->findOneBy(['lemma' => 'alpha']);
        $beta = $this->entityManager->getRepository(VocabularyItem::class)->findOneBy(['lemma' => 'beta']);
        self::assertInstanceOf(VocabularyItem::class, $alpha);
        self::assertInstanceOf(VocabularyItem::class, $beta);
        $this->recordReviewForWord($publication, 'alpha', 'Good');
        $betaContext = $this->publicationVocabularyFor($publication, $beta);

        $crawler = $this->client->request('GET', '/vocabulary/'.$beta->getId());
        $token = (string) $crawler
            ->filter(sprintf('form[action="/publication-vocabulary/%d/remove"] input[name="_token"]', $betaContext->getId()))
            ->attr('value');
        $this->client->request('POST', '/publication-vocabulary/'.$betaContext->getId().'/remove', [
            '_token' => $token,
        ]);

        self::assertSame(6, $this->countRows('learning_card'));
        self::assertSame(1, $this->countRows('learning_review'));

        $this->client->request('GET', '/learning/cards?publication='.$publication->getId().'&q=beta');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No learning cards match the current filters.');

        $this->client->request('GET', '/learning/study?start=1&publication='.$publication->getId().'&q=beta');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No due or new learning cards match the current filters.');
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

    public function testVocabularyFiltersSupportStatusEnrichedPosAndSearch(): void
    {
        $publication = $this->persistAnalyzedPublication('Advanced filters');
        $reluctant = $this->persistVocabularyRow($publication, 'reluctant', 'NOUN', 4);
        $hero = $this->persistVocabularyRow($publication, 'hero', 'NOUN', 3, VocabularyStatus::KNOWN);
        $running = $this->persistVocabularyRow($publication, 'running', 'VERB', 7, VocabularyStatus::KNOWN);
        $ready = $this->persistVocabularyRow($publication, 'ready', 'ADJ', 2);
        $this->persistEnrichment($publication, $running, 'biegnacy');
        $this->persistEnrichment($publication, $ready, 'gotowy');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?status=unknown');
        self::assertSame(['reluctant', 'ready'], $this->visibleVocabularyLemmas($crawler));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?status=known');
        self::assertSame(['running', 'hero'], $this->visibleVocabularyLemmas($crawler));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?enriched=yes');
        self::assertSame(['running', 'ready'], $this->visibleVocabularyLemmas($crawler));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?enriched=no');
        self::assertSame(['reluctant', 'hero'], $this->visibleVocabularyLemmas($crawler));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?pos=NOUN');
        self::assertSame(['reluctant', 'hero'], $this->visibleVocabularyLemmas($crawler));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?pos=VERB');
        self::assertSame(['running'], $this->visibleVocabularyLemmas($crawler));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?pos=ADJ');
        self::assertSame(['ready'], $this->visibleVocabularyLemmas($crawler));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?status=unknown&enriched=no&pos=NOUN&q=reluct');
        self::assertSame(['reluctant'], $this->visibleVocabularyLemmas($crawler));

        self::assertNotNull($reluctant->getId());
    }

    public function testVocabularySortingUsesWhitelistedDeterministicColumns(): void
    {
        $publication = $this->persistAnalyzedPublication('Sortable vocabulary');
        $alpha = $this->persistVocabularyRow($publication, 'alpha', 'NOUN', 2);
        $beta = $this->persistVocabularyRow($publication, 'beta', 'VERB', 2, VocabularyStatus::KNOWN);
        $gamma = $this->persistVocabularyRow($publication, 'gamma', 'ADJ', 5);
        $delta = $this->persistVocabularyRow($publication, 'delta', 'NOUN', 1, VocabularyStatus::KNOWN);
        $this->persistEnrichment($publication, $beta, 'beta');
        $this->persistEnrichment($publication, $gamma, 'gamma');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?sort=lemma&direction=asc');
        self::assertSame(['alpha', 'beta', 'delta', 'gamma'], $this->visibleVocabularyLemmas($crawler));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?sort=lemma&direction=desc');
        self::assertSame(['gamma', 'delta', 'beta', 'alpha'], $this->visibleVocabularyLemmas($crawler));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?sort=occurrences&direction=desc');
        self::assertSame(['gamma', 'alpha', 'beta', 'delta'], $this->visibleVocabularyLemmas($crawler));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?sort=occurrences&direction=asc');
        self::assertSame(['delta', 'alpha', 'beta', 'gamma'], $this->visibleVocabularyLemmas($crawler));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?sort=status&direction=asc');
        self::assertSame(['beta', 'delta', 'alpha', 'gamma'], $this->visibleVocabularyLemmas($crawler));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?sort=pos&direction=asc');
        self::assertSame(['gamma', 'alpha', 'delta', 'beta'], $this->visibleVocabularyLemmas($crawler));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?sort=enriched&direction=desc');
        self::assertSame(['beta', 'gamma', 'alpha', 'delta'], $this->visibleVocabularyLemmas($crawler));

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?sort=DROP%20TABLE&direction=banana');
        self::assertSame(['gamma', 'alpha', 'beta', 'delta'], $this->visibleVocabularyLemmas($crawler));
    }

    public function testVocabularyPaginationUsesDatabaseLimitsAndMetadata(): void
    {
        $publication = $this->persistAnalyzedPublication('Paginated vocabulary');
        for ($index = 1; $index <= 120; ++$index) {
            $this->persistVocabularyRow($publication, sprintf('word-%03d', $index), 'NOUN', 1, VocabularyStatus::UNKNOWN, false);
        }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?sort=lemma&direction=asc&perPage=50');
        self::assertSame(50, $crawler->filter('tbody tr')->count());
        self::assertSelectorTextContains('body', 'Showing 1-50 of 120 words');
        self::assertSelectorTextContains('body', '3');

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?sort=lemma&direction=asc&page=2&perPage=50');
        self::assertSame(50, $crawler->filter('tbody tr')->count());
        self::assertSelectorTextContains('body', 'Showing 51-100 of 120 words');
        self::assertSame('word-051', $this->visibleVocabularyLemmas($crawler)[0]);

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?sort=lemma&direction=asc&page=3&perPage=50');
        self::assertSame(20, $crawler->filter('tbody tr')->count());
        self::assertSelectorTextContains('body', 'Showing 101-120 of 120 words');

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?page=999999&perPage=99999');
        self::assertSame(20, $crawler->filter('tbody tr')->count());
        self::assertSelectorTextContains('body', 'Showing 101-120 of 120 words');
    }

    public function testVocabularyFilterAndPaginationCountsUseFilteredRows(): void
    {
        $publication = $this->persistAnalyzedPublication('Filtered pagination');
        for ($index = 1; $index <= 15; ++$index) {
            $this->persistVocabularyRow($publication, sprintf('match-%03d', $index), 'NOUN', 1);
        }
        for ($index = 1; $index <= 15; ++$index) {
            $item = $this->persistVocabularyRow($publication, sprintf('enriched-%03d', $index), 'NOUN', 1);
            $this->persistEnrichment($publication, $item, 'enriched');
        }
        for ($index = 1; $index <= 30; ++$index) {
            $this->persistVocabularyRow($publication, sprintf('known-%03d', $index), 'NOUN', 1, VocabularyStatus::KNOWN);
        }
        for ($index = 1; $index <= 60; ++$index) {
            $this->persistVocabularyRow($publication, sprintf('verb-%03d', $index), 'VERB', 1);
        }
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId().'?status=unknown&pos=NOUN&enriched=no&perPage=25&sort=lemma&direction=asc');

        self::assertSame(15, $crawler->filter('tbody tr')->count());
        self::assertSelectorTextContains('body', 'Showing 1-15 of 15 words');
        self::assertStringNotContainsString('page=2', (string) $this->client->getResponse()->getContent());
        self::assertSame('match-001', $this->visibleVocabularyLemmas($crawler)[0]);
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

    public function testVocabularyDetailsDisplayOccurrenceHistory(): void
    {
        $firstPublication = $this->persistAnalyzedPublication('Reluctant Hero Ethics');
        $secondPublication = $this->persistAnalyzedPublication('Hero Notes');
        $item = $this->persistVocabularyRow($firstPublication, 'reluctant', 'ADJ', 2);
        $this->entityManager->persist(new PublicationVocabulary($secondPublication, $item, 1));
        $this->persistOccurrence($firstPublication, $item, 'Reluctant', 'The reluctant hero waited.', 4);
        $this->persistOccurrence($firstPublication, $item, 'reluctant', 'A reluctant answer followed.', 2);
        $this->persistOccurrence($secondPublication, $item, 'reluctant', 'Another reluctant choice appeared.', 8);
        $this->entityManager->flush();

        $this->client->request('GET', '/vocabulary/'.$item->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'reluctant');
        self::assertSelectorTextContains('body', 'Total occurrences');
        self::assertSelectorTextContains('body', '3');
        self::assertSelectorTextContains('body', 'Reluctant Hero Ethics');
        self::assertSelectorTextContains('body', 'Hero Notes');
        self::assertSelectorTextContains('body', 'The reluctant hero waited.');
        self::assertSelectorTextContains('body', 'A reluctant answer followed.');
        self::assertSelectorTextContains('body', 'Another reluctant choice appeared.');
    }

    public function testVocabularyDetailsStatusActionRedirectsBackToDetails(): void
    {
        $publication = $this->persistAnalyzedPublication('Detail status');
        $item = $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 1);
        $crawler = $this->client->request('GET', '/vocabulary/'.$item->getId());

        $this->client->submit($crawler->selectButton('Mark KNOWN')->form());

        self::assertResponseRedirects('/vocabulary/'.$item->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'KNOWN');
        self::assertSame('KNOWN', $this->vocabularyStatus($item));
    }

    public function testVocabularyDetailsCanGenerateEnrichment(): void
    {
        $publication = $this->persistAnalyzedPublication('Reluctant Context');
        $item = $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 1);
        $this->persistOccurrence($publication, $item, 'reluctant', 'He was reluctant to enter the cave.', 7);
        $this->entityManager->flush();
        ConfigurableVocabularyEnrichmentProvider::$result = $this->enrichmentResult('niechetny', 'hesitant to enter the cave');

        $crawler = $this->client->request('GET', '/vocabulary/'.$item->getId());
        $this->client->submit($crawler->selectButton('Generate enrichment')->form());

        self::assertResponseRedirects('/vocabulary/'.$item->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'AI Enrichment');
        self::assertSelectorTextContains('body', 'niechetny');
        self::assertSelectorTextContains('body', 'not willing or eager to do something');
        self::assertSelectorTextContains('body', 'hesitant to enter the cave');
        self::assertSelectorTextContains('body', 'She was reluctant to speak.');
        self::assertSelectorTextContains('body', 'B2');
        self::assertSelectorTextContains('body', 'He was reluctant to enter the cave.');
        self::assertSame('reluctant', ConfigurableVocabularyEnrichmentProvider::$requests[0]->lemma);
    }

    public function testVocabularyDetailsProviderFailureShowsErrorAndKeepsExistingEnrichment(): void
    {
        $publication = $this->persistAnalyzedPublication('Failure Context');
        $item = $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 1);
        $this->persistOccurrence($publication, $item, 'reluctant', 'He was reluctant to enter the cave.', 7);
        $this->persistEnrichment($publication, $item, 'existing translation');
        $this->entityManager->flush();
        ConfigurableVocabularyEnrichmentProvider::$exception = new VocabularyEnrichmentException('Provider unavailable.');

        $crawler = $this->client->request('GET', '/vocabulary/'.$item->getId());
        $this->client->submit($crawler->selectButton('Regenerate')->form());

        self::assertResponseRedirects('/vocabulary/'.$item->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Provider unavailable.');
        self::assertSelectorTextContains('body', 'existing translation');
        self::assertSame('existing translation', $this->entityManager->getConnection()->fetchOne('SELECT translation_pl FROM publication_vocabulary_enrichment'));
    }

    public function testBulkVocabularyEnrichmentCreatesSuccessfulRowsAndReportsFailures(): void
    {
        $publication = $this->persistAnalyzedPublication('Bulk Enrichment');
        $reluctant = $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 1);
        $hero = $this->persistVocabularyRow($publication, 'hero', 'NOUN', 1);
        $missingContext = $this->persistVocabularyRow($publication, 'missing', 'NOUN', 1);
        $this->persistOccurrence($publication, $reluctant, 'reluctant', 'He was reluctant to enter the cave.', 7);
        $this->persistOccurrence($publication, $hero, 'hero', 'The hero entered the cave.', 4);
        $this->entityManager->flush();
        ConfigurableVocabularyEnrichmentProvider::$result = $this->enrichmentResult('generated', 'generated meaning');

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId());
        $token = (string) $crawler->filter('form#bulk-status-form input[name="enrichmentToken"]')->attr('value');
        $this->client->request('POST', '/vocabulary/bulk-enrichment', [
            'enrichmentToken' => $token,
            'publicationId' => $publication->getId(),
            'ids' => [$reluctant->getId(), $hero->getId(), $missingContext->getId()],
        ]);

        self::assertResponseRedirects('/publications/'.$publication->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '2 enrichments generated.');
        self::assertSelectorTextContains('body', 'Some enrichments failed');
        self::assertSame(2, $this->countRows('publication_vocabulary_enrichment'));
    }

    public function testGenerateLearningCardsCreatesDefaultTypesAndIsIdempotent(): void
    {
        $publication = $this->persistAnalyzedPublication('Learning Cards Source');
        $item = $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 1);
        $this->persistOccurrence($publication, $item, 'reluctant', 'She was reluctant to speak.', 1);
        $this->persistEnrichment($publication, $item, 'niechetny / wahajacy sie', 'hesitant or unwilling to speak', 'She was reluctant to speak.');
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/vocabulary/'.$item->getId());
        $this->client->submit($crawler->selectButton('Generate learning cards')->form());
        self::assertResponseRedirects('/vocabulary/'.$item->getId());
        self::assertSame(3, $this->countRows('learning_card'));

        $cards = $this->learningCards();
        self::assertSame('reluctant', $cards[LearningCardType::FORWARD->value]->getFront());
        self::assertSame('niechetny / wahajacy sie', $cards[LearningCardType::FORWARD->value]->getBack());
        self::assertStringContainsString('Recall the target English word', $cards[LearningCardType::REVERSE->value]->getFront());
        self::assertStringContainsString('niechetny / wahajacy sie', $cards[LearningCardType::REVERSE->value]->getFront());
        self::assertStringNotContainsString('reluctant', $cards[LearningCardType::REVERSE->value]->getFront());
        self::assertSame('reluctant', $cards[LearningCardType::REVERSE->value]->getBack());
        self::assertArrayNotHasKey(LearningCardType::CLOZE->value, $cards);
        self::assertStringContainsString('She was reluctant to speak.', (string) $cards[LearningCardType::CONTEXT_MEANING->value]->getContextSentence());
        self::assertStringContainsString('What does "reluctant" mean in this context?', $cards[LearningCardType::CONTEXT_MEANING->value]->getFront());
        self::assertSame('hesitant or unwilling to speak', $cards[LearningCardType::CONTEXT_MEANING->value]->getBack());

        $crawler = $this->client->request('GET', '/vocabulary/'.$item->getId());
        $this->client->submit($crawler->selectButton('Generate learning cards')->form());
        self::assertSame(3, $this->countRows('learning_card'));
    }

    public function testOrdinaryLearningCardGenerationDoesNotCreateAutomaticCloze(): void
    {
        $publication = $this->persistAnalyzedPublication('Cloze Source');
        $art = $this->persistVocabularyRow($publication, 'art', 'NOUN', 1);
        $this->persistOccurrence($publication, $art, 'art', 'A partial failure followed.', 1);
        $this->persistEnrichment($publication, $art, 'sztuka', 'creative work', 'A partial failure followed.');

        $run = $this->persistVocabularyRow($publication, 'run', 'VERB', 1);
        $this->persistOccurrence($publication, $run, 'Running', 'Running, he reached the door.', 2);
        $this->persistEnrichment($publication, $run, 'biec', 'moving quickly', 'Running, he reached the door.');

        $had = $this->persistVocabularyRow($publication, 'have', 'VERB', 1);
        $this->persistOccurrence($publication, $had, 'had', 'He had had enough.', 3);
        $this->persistEnrichment($publication, $had, 'miec', 'possessing or experiencing', 'He had had enough.');
        $this->entityManager->flush();

        foreach ([$art, $run, $had] as $item) {
            $crawler = $this->client->request('GET', '/vocabulary/'.$item->getId());
            $this->client->submit($crawler->selectButton('Generate learning cards')->form());
        }

        self::assertSame(9, $this->countRows('learning_card'));
        $clozeRows = $this->entityManager->getConnection()->fetchFirstColumn('SELECT cloze_sentence FROM learning_card WHERE type = :type ORDER BY cloze_sentence ASC', [
            'type' => LearningCardType::CLOZE->value,
        ]);
        self::assertSame([], $clozeRows);
    }

    public function testExistingClozeCardIsPreservedWhenGeneratingDefaultCards(): void
    {
        $publication = $this->persistAnalyzedPublication('Existing Cloze Source');
        $item = $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 1);
        $this->persistOccurrence($publication, $item, 'reluctant', 'She was reluctant to speak.', 1);
        $this->persistEnrichment($publication, $item, 'niechetny', 'hesitant or unwilling to speak', 'She was reluctant to speak.');
        $this->entityManager->flush();

        $publicationVocabulary = $this->entityManager->getRepository(PublicationVocabulary::class)->findOneBy([
            'publication' => $publication,
            'vocabularyItem' => $item,
        ]);
        self::assertInstanceOf(PublicationVocabulary::class, $publicationVocabulary);
        self::assertNotNull($publicationVocabulary->getEnrichment());
        $this->entityManager->persist(new LearningCard(
            vocabularyItem: $item,
            publicationVocabulary: $publicationVocabulary,
            publicationVocabularyEnrichment: $publicationVocabulary->getEnrichment(),
            type: LearningCardType::CLOZE,
            front: 'She was _____ to speak.',
            back: 'reluctant',
            contextSentence: 'She was reluctant to speak.',
            clozeSentence: 'She was _____ to speak.',
        ));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/vocabulary/'.$item->getId());
        $this->client->submit($crawler->selectButton('Generate learning cards')->form());

        self::assertSame(4, $this->countRows('learning_card'));
        self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM learning_card WHERE type = :type', [
            'type' => LearningCardType::CLOZE->value,
        ]));
    }

    public function testLearningCardsRemainContextSpecificForMultiplePublicationMeanings(): void
    {
        $servicePublication = $this->persistAnalyzedPublication('Service billing');
        $criminalPublication = $this->persistAnalyzedPublication('Criminal law');
        $charge = new VocabularyItem('en', 'charge', 'NOUN');
        $this->entityManager->persist($charge);
        $this->entityManager->persist(new PublicationVocabulary($servicePublication, $charge, 1));
        $this->entityManager->persist(new PublicationVocabulary($criminalPublication, $charge, 1));
        $this->entityManager->flush();

        $this->persistOccurrence($servicePublication, $charge, 'charge', 'The service charge was high.', 1);
        $this->persistOccurrence($criminalPublication, $charge, 'charge', 'The criminal charge was serious.', 1);
        $this->persistEnrichment($servicePublication, $charge, 'oplata', 'fee in this context', 'The service charge was high.');
        $this->persistEnrichment($criminalPublication, $charge, 'zarzut', 'accusation in this context', 'The criminal charge was serious.');
        $this->entityManager->flush();

        foreach ($this->entityManager->getRepository(PublicationVocabulary::class)->findBy(['vocabularyItem' => $charge]) as $publicationVocabulary) {
            $this->client->request('POST', '/publication-vocabulary/'.$publicationVocabulary->getId().'/learning-cards/generate', [
                '_token' => $this->learningCardsToken($publicationVocabulary),
            ]);
        }

        self::assertSame(6, $this->countRows('learning_card'));
        $rows = $this->entityManager->getConnection()->fetchAllAssociative('
            SELECT p.title, lc.back, lc.context_sentence
            FROM learning_card lc
            INNER JOIN publication_vocabulary pv ON pv.id = lc.publication_vocabulary_id
            INNER JOIN publication p ON p.id = pv.publication_id
            WHERE lc.type = :type
            ORDER BY p.title ASC
        ', ['type' => LearningCardType::FORWARD->value]);

        self::assertSame('zarzut', $rows[0]['back']);
        self::assertSame('The criminal charge was serious.', $rows[0]['context_sentence']);
        self::assertSame('oplata', $rows[1]['back']);
        self::assertSame('The service charge was high.', $rows[1]['context_sentence']);
    }

    public function testBulkLearningCardGenerationSkipsRowsWithoutEnrichment(): void
    {
        $publication = $this->persistAnalyzedPublication('Bulk cards');
        $items = [];
        for ($index = 1; $index <= 5; ++$index) {
            $item = $this->persistVocabularyRow($publication, sprintf('word%d', $index), 'NOUN', 1);
            $this->persistOccurrence($publication, $item, sprintf('word%d', $index), sprintf('The word%d appeared.', $index), $index);
            $this->persistEnrichment($publication, $item, sprintf('translation%d', $index), sprintf('meaning%d', $index), sprintf('The word%d appeared.', $index));
            $items[] = $item;
        }
        $missing = $this->persistVocabularyRow($publication, 'missing', 'NOUN', 1);
        $items[] = $missing;
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/publications/'.$publication->getId());
        $token = (string) $crawler->filter('form#bulk-status-form input[name="learningCardsToken"]')->attr('value');
        $this->client->request('POST', '/publications/'.$publication->getId().'/learning-cards/generate-selected', [
            'learningCardsToken' => $token,
            'publicationId' => $publication->getId(),
            'ids' => array_map(static fn (VocabularyItem $item): ?int => $item->getId(), $items),
        ]);

        self::assertResponseRedirects('/publications/'.$publication->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '15 learning cards generated.');
        self::assertSelectorTextContains('body', 'Skipped without enrichment: 1.');
        self::assertSame(15, $this->countRows('learning_card'));
    }

    public function testLearningCardsListFiltersPaginationAndActivation(): void
    {
        $publication = $this->persistAnalyzedPublication('Learning list');
        for ($index = 1; $index <= 55; ++$index) {
            $item = $this->persistVocabularyRow($publication, sprintf('term%03d', $index), 'NOUN', 1);
            $this->persistOccurrence($publication, $item, sprintf('term%03d', $index), sprintf('The term%03d appeared.', $index), $index);
            $this->persistEnrichment($publication, $item, sprintf('translation%03d', $index), sprintf('meaning%03d', $index), sprintf('The term%03d appeared.', $index));
        }
        $this->entityManager->flush();

        foreach ($this->entityManager->getRepository(PublicationVocabulary::class)->findBy(['publication' => $publication]) as $publicationVocabulary) {
            $this->client->request('POST', '/publication-vocabulary/'.$publicationVocabulary->getId().'/learning-cards/generate', [
                '_token' => $this->learningCardsToken($publicationVocabulary),
            ]);
        }

        $crawler = $this->client->request('GET', '/learning/cards?type=FORWARD&publication='.$publication->getId().'&status=unknown&q=term&sort=lemma&direction=asc&perPage=50');
        self::assertResponseIsSuccessful();
        self::assertSame(50, $crawler->filter('tbody tr')->count());
        self::assertSelectorTextContains('body', 'Showing 1-50 of 55 cards');

        $crawler = $this->client->request('GET', '/learning/cards?type=FORWARD&publication='.$publication->getId().'&status=unknown&q=term&sort=lemma&direction=asc&page=2&perPage=50');
        self::assertSame(5, $crawler->filter('tbody tr')->count());

        $this->client->submit($crawler->filter('tbody tr')->first()->selectButton('Deactivate')->form());
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Learning card deactivated.');
    }

    public function testStudyModeShowsFrontRevealBackAndRecordsReviewWithoutChangingStatus(): void
    {
        $publication = $this->publicationWithGeneratedForwardCards(['alpha', 'beta']);
        $first = $this->entityManager->getRepository(VocabularyItem::class)->findOneBy(['lemma' => 'alpha']);
        self::assertInstanceOf(VocabularyItem::class, $first);

        $crawler = $this->client->request('GET', '/learning/study?start=1&publication='.$publication->getId().'&type=FORWARD&status=unknown&q=alpha');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'alpha');
        self::assertStringNotContainsString('translation alpha', (string) $this->client->getResponse()->getContent());

        $this->client->submit($crawler->selectButton('Reveal answer')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'translation alpha');
        self::assertSelectorNotExists('form[action="/learning/study/next"]');
        self::assertSelectorExists('form[action="/learning/study/review"]');

        $crawler = $this->client->getCrawler();
        $this->client->submit($crawler->selectButton('Good')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Session complete');
        self::assertSame('UNKNOWN', $this->vocabularyStatus($first));
        self::assertSame(1, $this->countRows('learning_review'));

        $review = $this->entityManager->getRepository(LearningReview::class)->findOneBy([]);
        self::assertInstanceOf(LearningReview::class, $review);
        self::assertSame(ReviewRating::GOOD, $review->getRating());
        self::assertNotNull($review->getLearningCard());
        self::assertSame('alpha', $review->getLearningCard()->getVocabularyItem()->getLemma());
        self::assertGreaterThanOrEqual(0, $review->getResponseTimeMs());
    }

    public function testReverseStudyCardDoesNotLeakTargetBeforeReveal(): void
    {
        $publication = $this->persistAnalyzedPublication('Reverse source');
        $item = $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 1);
        $this->persistOccurrence($publication, $item, 'reluctant', 'She was reluctant to speak.', 1);
        $this->persistEnrichment($publication, $item, 'niechetny', 'hesitant or unwilling to speak', 'She was reluctant to speak.');
        $this->entityManager->flush();

        $publicationVocabulary = $this->entityManager->getRepository(PublicationVocabulary::class)->findOneBy([
            'publication' => $publication,
            'vocabularyItem' => $item,
        ]);
        self::assertInstanceOf(PublicationVocabulary::class, $publicationVocabulary);
        $this->client->request('POST', '/publication-vocabulary/'.$publicationVocabulary->getId().'/learning-cards/generate', [
            '_token' => $this->learningCardsToken($publicationVocabulary),
        ]);

        $crawler = $this->client->request('GET', '/learning/study?start=1&publication='.$publication->getId().'&type=REVERSE&status=unknown');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Recall the target English word.');
        self::assertSelectorTextContains('body', 'niechetny');
        self::assertStringNotContainsString('reluctant', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('She was reluctant to speak.', (string) $this->client->getResponse()->getContent());

        $this->client->submit($crawler->selectButton('Reveal answer')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'reluctant');
        self::assertSelectorTextContains('body', 'She was reluctant to speak.');
    }

    public function testStudyReviewPersistsEveryRatingEnum(): void
    {
        $publication = $this->persistAnalyzedPublication('Every rating source');
        foreach (['again', 'hard', 'good', 'easy'] as $word) {
            $item = $this->persistVocabularyRow($publication, $word, 'NOUN', 1);
            $this->persistOccurrence($publication, $item, $word, sprintf('The %s appeared.', $word), 1);
            $this->persistEnrichment($publication, $item, 'translation '.$word, 'meaning '.$word, sprintf('The %s appeared.', $word));
        }
        $this->entityManager->flush();
        foreach ($this->entityManager->getRepository(PublicationVocabulary::class)->findBy(['publication' => $publication]) as $publicationVocabulary) {
            $this->client->request('POST', '/publication-vocabulary/'.$publicationVocabulary->getId().'/learning-cards/generate', [
                '_token' => $this->learningCardsToken($publicationVocabulary),
            ]);
        }

        foreach (ReviewRating::cases() as $rating) {
            $word = strtolower($rating->value);
            $crawler = $this->client->request('GET', '/learning/study?start=1&publication='.$publication->getId().'&type=FORWARD&q='.$word);
            self::assertResponseIsSuccessful();
            $this->client->submit($crawler->selectButton('Reveal answer')->form());
            $this->client->followRedirect();
            $this->client->submit($this->client->getCrawler()->selectButton($rating->label())->form());
            $this->client->followRedirect();
        }

        $ratings = $this->entityManager->getConnection()->fetchFirstColumn('SELECT rating FROM learning_review ORDER BY rating ASC');
        self::assertSame(['AGAIN', 'EASY', 'GOOD', 'HARD'], $ratings);
    }

    public function testStudyReviewCannotBePostedBeforeReveal(): void
    {
        $publication = $this->publicationWithGeneratedForwardCards(['alpha']);
        $crawler = $this->client->request('GET', '/learning/study?start=1&publication='.$publication->getId().'&type=FORWARD&q=alpha');
        $hidden = $this->studyHiddenValues($crawler);

        $this->client->request('POST', '/learning/study/review', [
            '_token' => $hidden['_token'],
            'studySessionId' => $hidden['studySessionId'],
            'studyPosition' => $hidden['studyPosition'],
            'cardId' => $hidden['cardId'],
            'rating' => 'GOOD',
        ]);

        self::assertResponseRedirects('/learning/study');
        self::assertSame(0, $this->countRows('learning_review'));
    }

    public function testStudyReviewRejectsInvalidRating(): void
    {
        $publication = $this->publicationWithGeneratedForwardCards(['alpha']);
        $crawler = $this->client->request('GET', '/learning/study?start=1&publication='.$publication->getId().'&type=FORWARD&q=alpha');
        $this->client->submit($crawler->selectButton('Reveal answer')->form());
        $this->client->followRedirect();
        $hidden = $this->studyHiddenValues($this->client->getCrawler());

        $this->client->request('POST', '/learning/study/review', [
            '_token' => $hidden['_token'],
            'studySessionId' => $hidden['studySessionId'],
            'studyPosition' => $hidden['studyPosition'],
            'cardId' => $hidden['cardId'],
            'rating' => 'SUPER_MEGA_EASY',
        ]);

        self::assertResponseRedirects('/learning/study');
        self::assertSame(0, $this->countRows('learning_review'));
    }

    public function testStudyReviewRejectsWrongCardId(): void
    {
        $publication = $this->publicationWithGeneratedForwardCards(['alpha', 'beta']);
        $crawler = $this->client->request('GET', '/learning/study?start=1&publication='.$publication->getId().'&type=FORWARD&q=alpha');
        $this->client->submit($crawler->selectButton('Reveal answer')->form());
        $this->client->followRedirect();
        $hidden = $this->studyHiddenValues($this->client->getCrawler());
        $wrongCardId = (int) $this->entityManager->getConnection()->fetchOne('SELECT lc.id FROM learning_card lc JOIN vocabulary_item vi ON vi.id = lc.vocabulary_item_id WHERE vi.lemma = :lemma AND lc.type = :type', [
            'lemma' => 'beta',
            'type' => LearningCardType::FORWARD->value,
        ]);

        $this->client->request('POST', '/learning/study/review', [
            '_token' => $hidden['_token'],
            'studySessionId' => $hidden['studySessionId'],
            'studyPosition' => $hidden['studyPosition'],
            'cardId' => $wrongCardId,
            'rating' => 'GOOD',
        ]);

        self::assertResponseRedirects('/learning/study');
        self::assertSame(0, $this->countRows('learning_review'));
    }

    public function testStudyReviewRejectsStaleStudySession(): void
    {
        $publication = $this->publicationWithGeneratedForwardCards(['alpha', 'beta']);
        $crawler = $this->client->request('GET', '/learning/study?start=1&publication='.$publication->getId().'&type=FORWARD&q=alpha');
        $this->client->submit($crawler->selectButton('Reveal answer')->form());
        $this->client->followRedirect();
        $staleHidden = $this->studyHiddenValues($this->client->getCrawler());

        $this->client->request('GET', '/learning/study?start=1&publication='.$publication->getId().'&type=FORWARD&q=beta');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/learning/study/review', [
            '_token' => $staleHidden['_token'],
            'studySessionId' => $staleHidden['studySessionId'],
            'studyPosition' => $staleHidden['studyPosition'],
            'cardId' => $staleHidden['cardId'],
            'rating' => 'GOOD',
        ]);

        self::assertResponseRedirects('/learning/study');
        self::assertSame(0, $this->countRows('learning_review'));
    }

    public function testDuplicateStudyReviewPostCreatesSingleReview(): void
    {
        $publication = $this->publicationWithGeneratedForwardCards(['alpha']);
        $crawler = $this->client->request('GET', '/learning/study?start=1&publication='.$publication->getId().'&type=FORWARD&q=alpha');
        $this->client->submit($crawler->selectButton('Reveal answer')->form());
        $this->client->followRedirect();
        $hidden = $this->studyHiddenValues($this->client->getCrawler());
        $payload = [
            '_token' => $hidden['_token'],
            'studySessionId' => $hidden['studySessionId'],
            'studyPosition' => $hidden['studyPosition'],
            'cardId' => $hidden['cardId'],
            'rating' => 'GOOD',
        ];

        $this->client->request('POST', '/learning/study/review', $payload);
        self::assertResponseRedirects('/learning/study');
        $this->client->request('POST', '/learning/study/review', $payload);

        self::assertResponseRedirects('/learning/study');
        self::assertSame(1, $this->countRows('learning_review'));
    }

    public function testStudyProgressAdvancesAfterRatingNotReveal(): void
    {
        $publication = $this->publicationWithGeneratedForwardCards(['alpha', 'beta']);
        $crawler = $this->client->request('GET', '/learning/study?start=1&publication='.$publication->getId().'&type=FORWARD');
        self::assertSelectorTextContains('body', 'Card 1 of 2');

        $this->client->submit($crawler->selectButton('Reveal answer')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Card 1 of 2');

        $this->client->submit($this->client->getCrawler()->selectButton('Good')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Card 2 of 2');
    }

    public function testStudyCompletionSummaryUsesPersistedReviews(): void
    {
        $publication = $this->publicationWithGeneratedForwardCards(['alpha', 'beta', 'gamma']);
        $this->client->request('GET', '/learning/study?start=1&publication='.$publication->getId().'&type=FORWARD');

        foreach (['Good', 'Again', 'Easy'] as $ratingLabel) {
            $crawler = $this->client->getCrawler();
            $this->client->submit($crawler->selectButton('Reveal answer')->form());
            $this->client->followRedirect();
            $this->client->submit($this->client->getCrawler()->selectButton($ratingLabel)->form());
            $this->client->followRedirect();
        }

        self::assertSelectorTextContains('body', 'Session complete');
        self::assertSelectorTextContains('body', 'Reviewed');
        self::assertSelectorTextContains('body', '3');
        self::assertSelectorTextContains('body', 'Again');
        self::assertSelectorTextContains('body', 'Good');
        self::assertSelectorTextContains('body', 'Easy');
        self::assertSame(3, $this->countRows('learning_review'));
    }

    public function testRefreshingAfterRatingDoesNotCreateAnotherReview(): void
    {
        $publication = $this->publicationWithGeneratedForwardCards(['alpha']);
        $crawler = $this->client->request('GET', '/learning/study?start=1&publication='.$publication->getId().'&type=FORWARD&q=alpha');
        $this->client->submit($crawler->selectButton('Reveal answer')->form());
        $this->client->followRedirect();
        $this->client->submit($this->client->getCrawler()->selectButton('Good')->form());
        $this->client->followRedirect();

        $this->client->request('GET', '/learning/study');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->countRows('learning_review'));
    }

    public function testReviewHistoryPageFiltersAndPaginates(): void
    {
        $publication = $this->publicationWithGeneratedForwardCards(['alpha', 'beta']);
        $this->recordReviewForWord($publication, 'alpha', 'Good');
        $this->recordReviewForWord($publication, 'beta', 'Again');

        $this->client->request('GET', '/learning/reviews?rating=GOOD&type=FORWARD&q=alpha&publication='.$publication->getId().'&perPage=25');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Review History');
        self::assertSelectorTextContains('body', 'alpha');
        self::assertSelectorTextContains('body', 'GOOD');
        self::assertStringNotContainsString('beta', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextContains('body', 'Showing 1-1 of 1 reviews');
    }

    /**
     * @param list<string> $words
     */
    private function publicationWithGeneratedForwardCards(array $words): Publication
    {
        $publication = $this->persistAnalyzedPublication('Review source');
        foreach ($words as $index => $word) {
            $item = $this->persistVocabularyRow($publication, $word, 'NOUN', 1);
            $this->persistOccurrence($publication, $item, $word, sprintf('The %s appeared.', $word), $index + 1);
            $this->persistEnrichment($publication, $item, 'translation '.$word, 'meaning '.$word, sprintf('The %s appeared.', $word));
        }
        $this->entityManager->flush();

        foreach ($this->entityManager->getRepository(PublicationVocabulary::class)->findBy(['publication' => $publication]) as $publicationVocabulary) {
            self::assertInstanceOf(PublicationVocabulary::class, $publicationVocabulary);
            $this->client->request('POST', '/publication-vocabulary/'.$publicationVocabulary->getId().'/learning-cards/generate', [
                '_token' => $this->learningCardsToken($publicationVocabulary),
            ]);
        }

        return $publication;
    }

    /**
     * @return array{_token: string, studySessionId: string, studyPosition: string, cardId: string}
     */
    private function studyHiddenValues(Crawler $crawler): array
    {
        $form = $crawler->filter('form[action="/learning/study/review"], form[action="/learning/study/reveal"]')->first();

        return [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
            'studySessionId' => (string) $form->filter('input[name="studySessionId"]')->attr('value'),
            'studyPosition' => (string) $form->filter('input[name="studyPosition"]')->attr('value'),
            'cardId' => (string) $form->filter('input[name="cardId"]')->attr('value'),
        ];
    }

    private function recordReviewForWord(Publication $publication, string $word, string $ratingLabel): void
    {
        $crawler = $this->client->request('GET', '/learning/study?start=1&publication='.$publication->getId().'&type=FORWARD&q='.$word);
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Reveal answer')->form());
        $this->client->followRedirect();
        $this->client->submit($this->client->getCrawler()->selectButton($ratingLabel)->form());
        $this->client->followRedirect();
    }

    public function testPublicationVocabularyCanBeExportedAsCsv(): void
    {
        $publication = $this->persistAnalyzedPublication('CSV Export');
        $reluctant = $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 2);
        $hero = $this->persistVocabularyRow($publication, 'hero', 'NOUN', 1, VocabularyStatus::KNOWN);
        $this->persistOccurrence($publication, $reluctant, 'reluctant', 'The reluctant hero waited.', 4);
        $this->persistOccurrence($publication, $hero, 'hero', 'The reluctant hero waited.', 18);
        $this->persistEnrichment($publication, $reluctant, 'niechetny');
        $this->entityManager->flush();

        $this->client->request('GET', '/publications/'.$publication->getId().'/vocabulary/export.csv');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $csv = (string) $this->client->getResponse()->getContent();
        $rows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", trim($csv))));
        self::assertSame([
            'lemma',
            'part_of_speech',
            'status',
            'occurrences',
            'language',
            'translation_pl',
            'definition_en',
            'meaning_in_context',
            'simple_example',
            'cefr_level',
            'first_context_sentence',
        ], $rows[0]);
        self::assertSame(['reluctant', 'ADJ', 'UNKNOWN', '2', 'en'], array_slice($rows[1], 0, 5));
        self::assertSame('niechetny', $rows[1][5]);
        self::assertSame('not willing or eager to do something', $rows[1][6]);
        self::assertSame('existing contextual meaning', $rows[1][7]);
        self::assertSame('She was reluctant to speak.', $rows[1][8]);
        self::assertSame('B2', $rows[1][9]);
        self::assertSame('The reluctant hero waited.', $rows[1][10]);
        self::assertSame(['hero', 'NOUN', 'KNOWN', '1', 'en'], array_slice($rows[2], 0, 5));
        self::assertSame('', $rows[2][5]);
        self::assertSame('The reluctant hero waited.', $rows[2][10]);
    }

    public function testPublicationVocabularyCsvExportRespectsStatusFilter(): void
    {
        $publication = $this->persistAnalyzedPublication('Filtered CSV Export');
        $reluctant = $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 2);
        $run = $this->persistVocabularyRow($publication, 'run', 'VERB', 1, VocabularyStatus::KNOWN);
        $the = $this->persistVocabularyRow($publication, 'the', 'DET', 3, VocabularyStatus::KNOWN);
        $this->persistOccurrence($publication, $reluctant, 'reluctant', 'The reluctant hero waited.', 4);
        $this->persistOccurrence($publication, $run, 'running', 'The hero was running.', 13);
        $this->persistOccurrence($publication, $the, 'The', 'The reluctant hero waited.', 0);
        $this->entityManager->flush();

        $this->client->request('GET', '/publications/'.$publication->getId().'/vocabulary/export.csv?status=UNKNOWN');

        self::assertResponseIsSuccessful();
        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('reluctant,ADJ,UNKNOWN,2,en', $csv);
        self::assertStringNotContainsString('run,VERB,KNOWN', $csv);
        self::assertStringNotContainsString('the,DET,KNOWN', $csv);
    }

    public function testPublicationVocabularyExportsRespectFiltersSortAndIgnorePagination(): void
    {
        $publication = $this->persistAnalyzedPublication('Filtered paginated export');
        for ($index = 1; $index <= 60; ++$index) {
            $item = $this->persistVocabularyRow($publication, sprintf('match-%03d', $index), 'ADJ', $index);
            $this->persistEnrichment($publication, $item, 'match');
        }
        for ($index = 1; $index <= 10; ++$index) {
            $this->persistVocabularyRow($publication, sprintf('other-%03d', $index), 'NOUN', $index);
        }
        $this->entityManager->flush();

        $url = '/publications/'.$publication->getId().'/vocabulary/export.csv?status=unknown&enriched=yes&pos=ADJ&sort=occurrences&direction=desc&page=2&perPage=25';
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $rows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", trim((string) $this->client->getResponse()->getContent()))));
        self::assertCount(61, $rows);
        self::assertSame(['match-060', 'ADJ', 'UNKNOWN', '60', 'en'], array_slice($rows[1], 0, 5));
        self::assertSame(['match-001', 'ADJ', 'UNKNOWN', '1', 'en'], array_slice($rows[60], 0, 5));

        $this->client->request('GET', '/publications/'.$publication->getId().'/vocabulary/export.xlsx?status=unknown&enriched=yes&pos=ADJ&sort=occurrences&direction=desc&page=2&perPage=25');
        self::assertResponseIsSuccessful();

        $path = tempnam(sys_get_temp_dir(), 'wordtracker-test-xlsx-');
        self::assertIsString($path);
        file_put_contents($path, (string) $this->client->getResponse()->getContent());

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            self::assertSame('match-060', $sheet->getCell('A2')->getValue());
            self::assertSame('match-001', $sheet->getCell('A61')->getValue());
        } finally {
            @unlink($path);
        }
    }

    public function testPublicationVocabularyCanBeExportedAsXlsx(): void
    {
        $publication = $this->persistAnalyzedPublication('XLSX Export');
        $reluctant = $this->persistVocabularyRow($publication, 'reluctant', 'ADJ', 2);
        $hero = $this->persistVocabularyRow($publication, 'hero', 'NOUN', 1, VocabularyStatus::KNOWN);
        $this->persistOccurrence($publication, $reluctant, 'reluctant', 'The reluctant hero waited.', 4);
        $this->persistOccurrence($publication, $hero, 'hero', 'The reluctant hero waited.', 18);
        $this->persistEnrichment($publication, $reluctant, 'niechetny');
        $this->entityManager->flush();

        $this->client->request('GET', '/publications/'.$publication->getId().'/vocabulary/export.xlsx');

        self::assertResponseIsSuccessful();
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('.xlsx', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'wordtracker-test-xlsx-');
        self::assertIsString($path);
        file_put_contents($path, (string) $this->client->getResponse()->getContent());

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            self::assertSame('Lemma', $sheet->getCell('A1')->getValue());
            self::assertSame('Polish Translation', $sheet->getCell('F1')->getValue());
            self::assertSame('Context', $sheet->getCell('K1')->getValue());
            self::assertSame('reluctant', $sheet->getCell('A2')->getValue());
            self::assertSame('ADJ', $sheet->getCell('B2')->getValue());
            self::assertSame('UNKNOWN', $sheet->getCell('C2')->getValue());
            self::assertSame(2, $sheet->getCell('D2')->getValue());
            self::assertSame('en', $sheet->getCell('E2')->getValue());
            self::assertSame('niechetny', $sheet->getCell('F2')->getValue());
            self::assertSame('not willing or eager to do something', $sheet->getCell('G2')->getValue());
            self::assertSame('existing contextual meaning', $sheet->getCell('H2')->getValue());
            self::assertSame('She was reluctant to speak.', $sheet->getCell('I2')->getValue());
            self::assertSame('B2', $sheet->getCell('J2')->getValue());
            self::assertSame('The reluctant hero waited.', $sheet->getCell('K2')->getValue());
            self::assertSame('hero', $sheet->getCell('A3')->getValue());
            self::assertSame('KNOWN', $sheet->getCell('C3')->getValue());
            self::assertNull($sheet->getCell('F3')->getValue());
        } finally {
            @unlink($path);
        }
    }

    private function clientWithAnalyzer(TextAnalyzerInterface $analyzer): void
    {
        ConfigurableTextAnalyzer::$analysis = $analyzer->analyze('test text');
    }

    private function persistPublication(string $title, ?string $rawText = 'The children were running down the corridor.'): Publication
    {
        $publication = new Publication(
            title: $title,
            type: PublicationType::ARTICLE,
            language: 'en',
            rawText: $rawText,
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
        bool $flush = true,
    ): VocabularyItem {
        $item = new VocabularyItem('en', $lemma, $partOfSpeech);
        if ($status === VocabularyStatus::KNOWN) {
            $item->markKnown();
        }

        $this->entityManager->persist($item);
        $this->entityManager->persist(new PublicationVocabulary($publication, $item, $occurrences));
        if ($flush) {
            $this->entityManager->flush();
        }

        return $item;
    }

    private function persistOccurrence(
        Publication $publication,
        VocabularyItem $item,
        string $originalForm,
        string $sentence,
        int $position,
    ): void {
        $this->entityManager->persist(new VocabularyOccurrence($publication, $item, $originalForm, $sentence, $position));
    }

    private function persistEnrichment(
        Publication $publication,
        VocabularyItem $item,
        string $translation,
        string $meaning = 'existing contextual meaning',
        string $sourceSentence = 'He was reluctant to enter the cave.',
    ): void
    {
        $publicationVocabulary = $this->entityManager->getRepository(PublicationVocabulary::class)->findOneBy([
            'publication' => $publication,
            'vocabularyItem' => $item,
        ]);
        self::assertInstanceOf(PublicationVocabulary::class, $publicationVocabulary);

        $this->entityManager->persist(new PublicationVocabularyEnrichment(
            publicationVocabulary: $publicationVocabulary,
            translationPl: $translation,
            definitionEn: 'not willing or eager to do something',
            meaningInContext: $meaning,
            simpleExample: 'She was reluctant to speak.',
            cefrLevel: 'B2',
            sourceSentence: $sourceSentence,
            provider: 'test',
            model: 'fake',
            promptVersion: 'word-enrichment-v1',
        ));
    }

    private function publicationVocabularyFor(Publication $publication, VocabularyItem $item): PublicationVocabulary
    {
        $publicationVocabulary = $this->entityManager->getRepository(PublicationVocabulary::class)->findOneBy([
            'publication' => $publication,
            'vocabularyItem' => $item,
        ]);
        self::assertInstanceOf(PublicationVocabulary::class, $publicationVocabulary);

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

    private function learningCardsToken(PublicationVocabulary $publicationVocabulary): string
    {
        $crawler = $this->client->request('GET', '/vocabulary/'.$publicationVocabulary->getVocabularyItem()->getId());

        return (string) $crawler
            ->filter(sprintf('form[action="/publication-vocabulary/%d/learning-cards/generate"] input[name="_token"]', $publicationVocabulary->getId()))
            ->attr('value');
    }

    private function vocabularyStatus(VocabularyItem $item): string
    {
        return (string) $this->entityManager->getConnection()->fetchOne(
            'SELECT status FROM vocabulary_item WHERE id = :id',
            ['id' => $item->getId()],
        );
    }

    /**
     * @return array<string, LearningCard>
     */
    private function learningCards(): array
    {
        $cards = $this->entityManager->getRepository(LearningCard::class)->findBy([], ['type' => 'ASC']);
        $byType = [];
        foreach ($cards as $card) {
            self::assertInstanceOf(LearningCard::class, $card);
            $byType[$card->getType()->value] = $card;
        }

        return $byType;
    }

    /**
     * @return list<string>
     */
    private function visibleVocabularyLemmas(Crawler $crawler): array
    {
        return $crawler->filter('tbody tr td:nth-child(2) a')->each(static fn (Crawler $node): string => trim($node->text()));
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

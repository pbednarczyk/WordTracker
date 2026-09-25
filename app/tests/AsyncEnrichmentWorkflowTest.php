<?php

declare(strict_types=1);

namespace App\Tests;

use App\Application\AsyncEnrichmentWorkflow;
use App\Application\LearningCardGenerator;
use App\Entity\LearningCard;
use App\Entity\LearningReview;
use App\Enum\LearningCardType;
use App\Enum\ReviewRating;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Application\EnrichPublicationVocabularyHandler;
use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
use App\Entity\PublicationVocabularyEnrichmentJob;
use App\Entity\VocabularyItem;
use App\Entity\VocabularyOccurrence;
use App\Enum\PublicationType;
use App\Enum\EnrichmentJobStatus;
use App\Enum\EnrichmentJobStage;
use App\Enrichment\VocabularyEnrichmentResult;
use App\Tests\Double\ConfigurableAsyncEnrichmentGateway as Gateway;
use App\Tests\Double\ConfigurableVocabularyEnrichmentProvider as SyncProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AsyncEnrichmentWorkflowTest extends WebTestCase
{
    use DatabaseResetTrait;
    private EntityManagerInterface $entityManager;
    private AsyncEnrichmentWorkflow $workflow;
    private $client;

    protected function setUp(): void
    {
        $_ENV['ASYNC_ENRICHMENT_ENABLED'] = $_SERVER['ASYNC_ENRICHMENT_ENABLED'] = 'true';
        $_ENV['ENRICHMENT_INTERNAL_TOKEN'] = $_SERVER['ENRICHMENT_INTERNAL_TOKEN'] = 'test-internal-token';
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->workflow = self::getContainer()->get(AsyncEnrichmentWorkflow::class);
        $this->resetDatabase();
        Gateway::reset();
        SyncProvider::reset();
    }

    protected function tearDown(): void
    {
        unset($_ENV['ASYNC_ENRICHMENT_ENABLED'], $_SERVER['ASYNC_ENRICHMENT_ENABLED']);
        unset($_ENV['ENRICHMENT_INTERNAL_TOKEN'], $_SERVER['ENRICHMENT_INTERNAL_TOKEN']);
        parent::tearDown();
    }

    private function context(?VocabularyItem $item = null, string $sentence = 'His willingness to help impressed everyone.'): PublicationVocabulary
    {
        $publication = new Publication('Async test', PublicationType::ARTICLE, 'en', rawText: $sentence);
        $publication->markAnalyzed();
        $item ??= new VocabularyItem('en', 'willingness', 'NOUN');
        $context = new PublicationVocabulary($publication, $item, 1);
        foreach ([$publication, $item, $context, new VocabularyOccurrence($publication, $item, $item->getLemma(), $sentence, 0)] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        return $context;
    }

    private function resultEnvelope(PublicationVocabularyEnrichmentJob $job): array
    {
        return ['schema_version' => 1, 'type' => 'llm.generate.result', 'job_id' => $job->getActiveLlmJobId(),
            'model' => 'qwen3:14b', 'status' => 'completed', 'response' => '{}', 'error' => null,
            'worker' => 'test-worker', 'completed_at' => '2026-09-23T00:00:00Z'];
    }

    #[DataProvider('cardActivationStates')]
    public function testAsyncRegenerationSynchronizesExistingCardsWithoutChangingStudyState(bool $inactiveReverse): void
    {
        $context = $this->context(new VocabularyItem('en', 'audience', 'NOUN'), 'An audience waited.');
        $this->completeEnrichment($context, 'publicum', 'old meaning');
        $generator = self::getContainer()->get(LearningCardGenerator::class);
        self::assertSame(3, $generator->generate($context)->created);
        $cards = $this->entityManager->getRepository(LearningCard::class)->findBy(['publicationVocabulary' => $context]);
        foreach ($cards as $position => $card) {
            $card->applyFsrsState(2, 4.5, 6.7, 3, 8, 12, 2, 1,
                new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-09-23'));
            if ($inactiveReverse && $card->getType() === LearningCardType::REVERSE) {
                $card->deactivate();
            }
            $this->entityManager->persist(new LearningReview($card, ReviewRating::GOOD,
                new \DateTimeImmutable('2026-09-23'), 1200, 'async-sync-test', $position));
        }
        $this->entityManager->flush();
        $other = $this->context($context->getVocabularyItem(), 'She requested an audience with the king.');
        $this->completeEnrichment($other, 'audiencja', 'a formal meeting');
        $generator->generate($other);
        $before = $this->cardRows();
        $reviews = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM learning_review ORDER BY id');
        $newSentence = 'The audience applauded loudly.';
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE vocabulary_occurrence SET sentence = ? WHERE publication_id = ?',
            [$newSentence, $context->getPublication()->getId()],
        );
        $contextId = $context->getId();
        $this->entityManager->clear();
        $context = $this->entityManager->find(PublicationVocabulary::class, $contextId);
        $job = $this->workflow->submit($context);
        self::assertSame($newSentence, $job->getRequestSnapshot()['context_sentence']);
        self::assertSame($before, $this->cardRows(), 'Submission alone must not refresh cards.');
        $result = $this->resultEnvelope($job);
        $result['response'] = json_encode(array_replace(Gateway::content(), [
            'translation_pl' => 'publiczność', 'meaning_in_context' => 'people watching a performance',
        ]), JSON_THROW_ON_ERROR);
        Gateway::$onEvaluate = static fn (array $request): array => [
            'outcome' => 'COMPLETED',
            'enrichment' => json_decode($request['result']['response'], true, 512, JSON_THROW_ON_ERROR),
        ];
        $jobId = $job->getId();
        // The callback runs in a separate request, with no managed fixture entities.
        $this->entityManager->clear();
        self::assertSame('applied', $this->workflow->accept($result));
        $job = $this->entityManager->find(PublicationVocabularyEnrichmentJob::class, $jobId);
        self::assertSame(EnrichmentJobStatus::COMPLETED, $job->getStatus());
        self::assertSame('publiczność', $job->getPublicationVocabulary()->getEnrichment()->getTranslationPl());
        $after = $this->cardRows();
        self::assertCount(6, $after);
        foreach ($after as $index => $card) {
            if ((int) $card['publication_vocabulary_id'] !== $contextId || !$card['is_active']) {
                self::assertSame($before[$index], $card, 'Inactive cards and other contexts must remain untouched.');
                continue;
            }
            $expected = match ($card['type']) {
                'FORWARD' => ['audience', 'publiczność'],
                'REVERSE' => ["Recall the target English word:\n\npubliczność", 'audience'],
                'CONTEXT_MEANING' => ["What does \"audience\" mean in this context?\n\n\"$newSentence\"", 'people watching a performance'],
            };
            self::assertSame($expected, [$card['front'], $card['back']]);
            self::assertSame($newSentence, $card['context_sentence']);
            $previous = $before[$index];
            foreach (['front', 'back', 'context_sentence', 'updated_at'] as $field) {
                unset($card[$field], $previous[$field]);
            }
            // Includes IDs, associations, activation, creation time and every FSRS column.
            self::assertSame($previous, $card);
        }
        self::assertSame($reviews, $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM learning_review ORDER BY id'));
        self::assertSame('ignored', $this->workflow->accept($result));
        self::assertSame($after, $this->cardRows(), 'Duplicate callbacks must not alter cards.');
        self::assertSame(0, $generator->generate($job->getPublicationVocabulary())->created);
        self::assertSame($after, $this->cardRows());
    }

    public static function cardActivationStates(): iterable
    {
        yield 'all active' => [false];
        yield 'inactive reverse' => [true];
    }

    #[DataProvider('unacceptedResults')]
    public function testUnacceptedAsyncResultsDoNotSynchronizeCards(string $reason): void
    {
        $context = $this->context(new VocabularyItem('en', 'audience', 'NOUN'), 'An audience waited.');
        $this->completeEnrichment($context, 'publicum', 'old meaning');
        self::getContainer()->get(LearningCardGenerator::class)->generate($context);
        $card = $this->entityManager->getRepository(LearningCard::class)->findOneBy(['publicationVocabulary' => $context]);
        // Deliberately stale content makes an erroneous synchronization observable
        // even if it uses the unchanged, previously persisted enrichment.
        $card->refreshContent('earlier front', 'earlier back', 'earlier context');
        $this->entityManager->flush();
        $before = $this->cardRows();
        $job = $this->workflow->submit($context);
        $result = $this->resultEnvelope($job);
        switch ($reason) {
            case 'WORKER_FAILED':
                $result['status'] = 'failed';
                $result['response'] = null;
                $result['error'] = ['code' => 'BACKEND_TIMEOUT', 'message' => 'timeout'];
                break;
            case 'MODEL_MISMATCH':
                $result['model'] = 'other-model';
                break;
            case 'INVALID_ENRICHMENT':
                Gateway::$onEvaluate = static fn () => ['outcome' => 'FAILED', 'failure' => 'INVALID_ENRICHMENT'];
                break;
            case 'REPAIR':
                Gateway::$onEvaluate = static fn () => ['outcome' => 'REPAIR', 'candidate' => Gateway::content(), 'job' => Gateway::message()];
                break;
            case 'SUPERSEDED':
                $this->workflow->submit($context);
                break;
            case 'VOCABULARY_REMOVED':
                $context->softDelete(new \DateTimeImmutable());
                $this->entityManager->flush();
                break;
        }
        $this->workflow->accept($result);
        if ($reason === 'REPAIR') {
            self::assertSame(EnrichmentJobStage::REPAIR, $job->getStage());
            self::assertSame(EnrichmentJobStatus::PROCESSING, $job->getStatus());
        } else {
            self::assertSame($reason, $job->getFailure());
        }
        self::assertSame('publicum', $context->getEnrichment()->getTranslationPl());
        self::assertSame($before, $this->cardRows());
    }

    public static function unacceptedResults(): iterable
    {
        foreach (['WORKER_FAILED', 'REPAIR', 'SUPERSEDED', 'VOCABULARY_REMOVED', 'INVALID_ENRICHMENT', 'MODEL_MISMATCH'] as $reason) {
            yield $reason => [$reason];
        }
    }

    private function completeEnrichment(PublicationVocabulary $context, string $translation, string $meaning): void
    {
        Gateway::$onEvaluate = static fn () => ['outcome' => 'COMPLETED', 'enrichment' => array_replace(
            Gateway::content(), ['translation_pl' => $translation, 'meaning_in_context' => $meaning],
        )];
        $job = $this->workflow->submit($context);
        self::assertSame('applied', $this->workflow->accept($this->resultEnvelope($job)));
        Gateway::$onEvaluate = null;
    }

    private function cardRows(): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM learning_card ORDER BY id');
    }

    public function testJobAndUuidAreDurableBeforePublishing(): void
    {
        Gateway::$onPrepare = function (array $request): void {
            self::assertFalse($this->entityManager->getConnection()->isTransactionActive());
            self::assertSame(1, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM publication_vocabulary_enrichment_job'));
            self::assertSame('willingness', $request['lemma']);
        };
        Gateway::$onPublish = function (array $message): void {
            self::assertFalse($this->entityManager->getConnection()->isTransactionActive());
            $row = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM publication_vocabulary_enrichment_job');
            self::assertSame($message['job_id'], $row['active_llm_job_id']);
            self::assertSame('QUEUED', $row['status']);
        };
        $job = $this->workflow->submit($this->context());
        self::assertSame(EnrichmentJobStatus::PROCESSING, $job->getStatus());
        self::assertSame('word-enrichment-v4', $job->getPromptVersion());
        self::assertCount(1, Gateway::$published);
    }

    public function testCompletionAfterEntityManagerClearAndDuplicateIsHarmless(): void
    {
        $job = $this->workflow->submit($this->context());
        $result = $this->resultEnvelope($job);
        $id = $job->getId();
        $this->entityManager->clear();
        self::assertSame('applied', $this->workflow->accept($result));
        self::assertSame('ignored', $this->workflow->accept($result));
        $job = $this->entityManager->find(PublicationVocabularyEnrichmentJob::class, $id);
        self::assertSame(EnrichmentJobStatus::COMPLETED, $job->getStatus());
        self::assertSame('gotowość', $job->getPublicationVocabulary()->getEnrichment()->getTranslationPl());
        self::assertCount(1, Gateway::$evaluated);
    }

    public function testUnknownAndSupersededResultsCannotOverwrite(): void
    {
        $context = $this->context();
        $old = $this->workflow->submit($context);
        $result = $this->resultEnvelope($old);
        $new = $this->workflow->submit($context);
        $this->workflow->accept($this->resultEnvelope($new));
        self::assertSame('ignored', $this->workflow->accept($result));
        $result['job_id'] = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
        self::assertSame('ignored', $this->workflow->accept($result));
        self::assertSame(EnrichmentJobStatus::FAILED, $old->getStatus());
        self::assertSame('SUPERSEDED', $old->getFailure());
        self::assertCount(1, Gateway::$evaluated);
    }

    public function testRemovedVocabularyDoesNotReceiveResult(): void
    {
        $context = $this->context();
        $job = $this->workflow->submit($context);
        $context->softDelete(new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->workflow->accept($this->resultEnvelope($job));
        self::assertSame('VOCABULARY_REMOVED', $job->getFailure());
        self::assertNull($context->getEnrichment());
        self::assertCount(0, Gateway::$evaluated);
    }

    public function testRepairStateCommittedBeforePublishAndSurvivesRestart(): void
    {
        $job = $this->workflow->submit($this->context());
        $generate = $this->resultEnvelope($job);
        Gateway::$onEvaluate = fn () => ['outcome' => 'REPAIR', 'candidate' => Gateway::content(), 'job' => Gateway::message()];
        Gateway::$onPublish = function (array $message): void {
            $row = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM publication_vocabulary_enrichment_job');
            self::assertSame('REPAIR', $row['stage']);
            self::assertSame($message['job_id'], $row['active_llm_job_id']);
            self::assertNotNull($row['candidate']);
            self::assertFalse($this->entityManager->getConnection()->isTransactionActive());
        };
        $this->workflow->accept($generate);
        self::assertSame(EnrichmentJobStage::REPAIR, $job->getStage());
        self::assertNotSame($generate['job_id'], $job->getActiveLlmJobId());
        self::assertSame('ignored', $this->workflow->accept($generate));
        $repair = $this->resultEnvelope($job);
        $id = $job->getId();
        $this->entityManager->clear();
        Gateway::$onEvaluate = null;
        $this->workflow->accept($repair);
        self::assertSame('REPAIR', Gateway::$evaluated[1]['stage']);
        self::assertNotNull(Gateway::$evaluated[1]['candidate']);
        self::assertSame(EnrichmentJobStatus::COMPLETED, $this->entityManager->find(PublicationVocabularyEnrichmentJob::class, $id)->getStatus());
    }

    public function testWorkerFailurePreservesExistingEnrichment(): void
    {
        $context = $this->context();
        $successful = $this->workflow->submit($context);
        $this->workflow->accept($this->resultEnvelope($successful));
        $existingId = $context->getEnrichment()->getId();
        $job = $this->workflow->submit($context);
        $result = $this->resultEnvelope($job);
        $result['status'] = 'failed'; $result['response'] = null;
        $result['error'] = ['code' => 'BACKEND_TIMEOUT', 'message' => 'private detail'];
        $this->workflow->accept($result);
        self::assertSame('WORKER_FAILED', $job->getFailure());
        self::assertSame($existingId, $context->getEnrichment()->getId());
        self::assertSame('gotowość', $context->getEnrichment()->getTranslationPl());
    }

    public function testInvalidGeneratedContentFails(): void
    {
        $job = $this->workflow->submit($this->context());
        Gateway::$onEvaluate = fn () => ['outcome' => 'FAILED', 'failure' => 'INVALID_ENRICHMENT'];
        $this->workflow->accept($this->resultEnvelope($job));
        self::assertSame('INVALID_ENRICHMENT', $job->getFailure());
        self::assertNull($job->getPublicationVocabulary()->getEnrichment());
    }

    public function testInitialAndRepairPublishFailuresAreDurable(): void
    {
        Gateway::$failPublish = true;
        $context = $this->context();
        $failed = $this->workflow->submit($context);
        self::assertSame('PUBLICATION_UNCONFIRMED', $failed->getFailure());
        Gateway::$failPublish = false;
        $job = $this->workflow->submit($context);
        Gateway::$onEvaluate = fn () => ['outcome' => 'REPAIR', 'candidate' => Gateway::content(), 'job' => Gateway::message()];
        Gateway::$failPublish = true;
        self::assertSame('applied', $this->workflow->accept($this->resultEnvelope($job)));
        self::assertSame('PUBLICATION_UNCONFIRMED', $job->getFailure());
        self::assertSame(EnrichmentJobStage::REPAIR, $job->getStage());
        $this->entityManager->refresh($job);
        self::assertSame(EnrichmentJobStatus::FAILED, $job->getStatus());
    }

    public function testPreparationFailureStillHasApplicationJob(): void
    {
        Gateway::$onPrepare = fn () => throw new \RuntimeException('NLP unavailable');
        $job = $this->workflow->submit($this->context());
        self::assertNotNull($job->getId());
        self::assertSame('PREPARATION_FAILED', $job->getFailure());
        self::assertCount(0, Gateway::$published);
    }

    public function testSynchronousRequestSupersedesPendingAsyncWork(): void
    {
        $context = $this->context();
        $job = $this->workflow->submit($context);
        SyncProvider::$result = new VocabularyEnrichmentResult('nowe', 'new', 'new meaning', 'Her willingness helped.');
        (self::getContainer()->get(EnrichPublicationVocabularyHandler::class))($context);
        $this->workflow->accept($this->resultEnvelope($job));
        self::assertSame('SUPERSEDED', $job->getFailure());
        self::assertSame('nowe', $context->getEnrichment()->getTranslationPl());
    }

    public function testModelMismatchFailsWithoutSemanticProcessing(): void
    {
        $job = $this->workflow->submit($this->context());
        $result = $this->resultEnvelope($job); $result['model'] = 'wrong';
        $this->workflow->accept($result);
        self::assertSame('MODEL_MISMATCH', $job->getFailure());
        self::assertCount(0, Gateway::$evaluated);
    }

    public function testInternalEndpointAuthenticationCorrelationAndDuplicate(): void
    {
        $job = $this->workflow->submit($this->context());
        $result = $this->resultEnvelope($job);
        $this->client->jsonRequest('POST', '/internal/enrichment/results', $result);
        self::assertResponseStatusCodeSame(401);
        $headers = ['HTTP_X_ENRICHMENT_TOKEN' => 'test-internal-token', 'HTTP_X_LLM_CORRELATION_ID' => 'wrong'];
        $this->client->jsonRequest('POST', '/internal/enrichment/results', $result, $headers);
        self::assertResponseStatusCodeSame(400);
        $headers['HTTP_X_LLM_CORRELATION_ID'] = $result['job_id'];
        $this->client->jsonRequest('POST', '/internal/enrichment/results', $result, $headers);
        self::assertResponseIsSuccessful();
        self::assertSame(['disposition' => 'applied'], json_decode($this->client->getResponse()->getContent(), true));
        $this->client->jsonRequest('POST', '/internal/enrichment/results', $result, $headers);
        self::assertSame(['disposition' => 'ignored'], json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testSingleItemUiUsesAsyncAndRefreshShowsState(): void
    {
        $context = $this->context();
        $crawler = $this->client->request('GET', '/vocabulary/'.$context->getVocabularyItem()->getId());
        $form = $crawler->selectButton('Generate enrichment')->form();
        $this->client->submit($form);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-enrichment-status]', 'PROCESSING');
        self::assertCount(0, SyncProvider::$requests);
        self::assertCount(1, Gateway::$published);
        $job = $this->entityManager->getRepository(PublicationVocabularyEnrichmentJob::class)->findOneBy([]);
        $this->workflow->accept($this->resultEnvelope($job));
        $this->client->request('GET', '/vocabulary/'.$context->getVocabularyItem()->getId());
        self::assertSelectorTextContains('[data-enrichment-status]', 'COMPLETED');
        self::assertSelectorTextContains('body', 'gotowość');
    }

    public function testFastResultBeforePublishReturnsIsNotResetToProcessing(): void
    {
        Gateway::$onPublish = function (array $message): void {
            $job = $this->entityManager->getRepository(PublicationVocabularyEnrichmentJob::class)->findOneBy(['activeLlmJobId' => $message['job_id']]);
            $this->workflow->accept($this->resultEnvelope($job));
        };
        $job = $this->workflow->submit($this->context());
        self::assertSame(EnrichmentJobStatus::COMPLETED, $job->getStatus());
    }

    public function testApplicationOutageReturns503AndLeavesResultUnapplied(): void
    {
        $job = $this->workflow->submit($this->context());
        $result = $this->resultEnvelope($job);
        Gateway::$onEvaluate = fn () => throw new \RuntimeException('NLP unavailable');
        $this->client->jsonRequest('POST', '/internal/enrichment/results', $result, [
            'HTTP_X_ENRICHMENT_TOKEN' => 'test-internal-token', 'HTTP_X_LLM_CORRELATION_ID' => $result['job_id'],
        ]);
        self::assertResponseStatusCodeSame(503);
        self::assertSame('PROCESSING', $this->entityManager->getConnection()->fetchOne('SELECT status FROM publication_vocabulary_enrichment_job WHERE id = ?', [$job->getId()]));
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM publication_vocabulary_enrichment'));
    }

    public function testFailedUiPreservesSuccessfulEnrichment(): void
    {
        $context = $this->context();
        $first = $this->workflow->submit($context);
        $this->workflow->accept($this->resultEnvelope($first));
        Gateway::$failPublish = true;
        $this->workflow->submit($context);
        $this->client->request('GET', '/vocabulary/'.$context->getVocabularyItem()->getId());
        self::assertSelectorTextContains('[data-enrichment-status]', 'FAILED');
        self::assertSelectorTextContains('body', 'gotowość');
    }

    public function testRetiredFlagCannotRestoreSynchronousUi(): void
    {
        $_ENV['ASYNC_ENRICHMENT_ENABLED'] = $_SERVER['ASYNC_ENRICHMENT_ENABLED'] = 'false';
        $this->testSingleItemUiUsesAsyncAndRefreshShowsState();
    }

    public function testBulkSelectionLimitAndCsrfArePreserved(): void
    {
        $context = $this->context();
        $crawler = $this->client->request('GET', '/publications/'.$context->getPublication()->getId());
        $token = $crawler->filter('form#bulk-status-form input[name="enrichmentToken"]')->attr('value');
        $parameters = ['publicationId' => $context->getPublication()->getId(), 'ids' => range(1, 101), 'enrichmentToken' => $token];
        $this->client->request('POST', '/vocabulary/bulk-enrichment', $parameters);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Bulk enrichment is limited to 100');
        self::assertCount(0, Gateway::$published);
        self::assertCount(0, SyncProvider::$requests);
        $parameters['enrichmentToken'] = 'invalid';
        $this->client->request('POST', '/vocabulary/bulk-enrichment', $parameters);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM publication_vocabulary_enrichment_job'));
    }

    public function testBulkUsesExistingAsyncWorkflow(): void
    {
        $context = $this->context();
        SyncProvider::$result = new VocabularyEnrichmentResult('gotowość', 'ready', 'readiness', 'Her willingness helped.');
        $crawler = $this->client->request('GET', '/publications/'.$context->getPublication()->getId());
        $token = $crawler->filter('form#bulk-status-form input[name="enrichmentToken"]')->attr('value');
        $this->client->request('POST', '/vocabulary/bulk-enrichment', ['publicationId' => $context->getPublication()->getId(),
            'ids' => [$context->getVocabularyItem()->getId()], 'enrichmentToken' => $token]);
        self::assertResponseRedirects();
        self::assertCount(0, SyncProvider::$requests);
        self::assertCount(1, Gateway::$published);
    }
}

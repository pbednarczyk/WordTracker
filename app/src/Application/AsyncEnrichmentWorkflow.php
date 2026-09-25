<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\PublicationVocabulary;
use App\Entity\PublicationVocabularyEnrichmentJob;
use App\Enum\EnrichmentJobStage;
use App\Enrichment\AsyncEnrichmentGatewayInterface;
use App\Enrichment\EnrichmentPersister;
use App\Enrichment\EnrichmentRequestFactory;
use App\Enrichment\VocabularyEnrichmentResult;
use App\Repository\PublicationVocabularyEnrichmentJobRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AsyncEnrichmentWorkflow
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PublicationVocabularyEnrichmentJobRepository $jobs,
        private EnrichmentRequestFactory $requests,
        private EnrichmentPersister $persister,
        private AsyncEnrichmentGatewayInterface $gateway,
        private LearningCardGenerator $learningCardGenerator,
    ) {}

    public function submit(PublicationVocabulary $context): PublicationVocabularyEnrichmentJob
    {
        // Eligibility errors occur before recording an application job.
        $this->requests->create($context);
        $job = $this->entityManager->wrapInTransaction(function () use ($context): PublicationVocabularyEnrichmentJob {
            $this->entityManager->lock($context, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($context);
            $snapshot = $this->requests->create($context)->toArray();
            $previous = $this->jobs->latest($context);
            if ($previous?->isPending()) {
                $previous->fail('SUPERSEDED');
            }
            $job = new PublicationVocabularyEnrichmentJob($context, $context->beginEnrichmentRequest(), $snapshot);
            $this->entityManager->persist($job);
            return $job;
        });
        // The application request is durable even if preparation/publication fails.
        try {
            $prepared = $this->gateway->prepare($job->getRequestSnapshot());
            $this->assertPrepared($prepared);
        } catch (\Throwable) {
            $this->fail($job, 'PREPARATION_FAILED');
            return $job;
        }
        $ready = $this->locked($job, function () use ($job, $prepared): bool {
            if (!$this->current($job)) { return false; }
            $job->prepare($prepared);
            return true;
        });
        if ($ready) {
            $this->publish($job, $prepared['job']);
        }
        return $job;
    }

    /** Returns only after a durable disposition, including repair publication handling. */
    public function accept(array $result): string
    {
        $job = $this->jobs->findActiveUuid($result['job_id']);
        if ($job === null) { return 'ignored'; }

        $disposition = 'ignored';
        $repair = $this->locked($job, function () use ($job, $result, &$disposition): ?array {
            // Refresh under the context lock: another delivery may have advanced REPAIR.
            if ($job->getActiveLlmJobId() !== $result['job_id'] || !$job->isPending()) { return null; }
            $disposition = 'applied';
            if (!$this->current($job)) { return null; }
            if ($result['model'] !== $job->getModel()) {
                $job->fail('MODEL_MISMATCH');
                return null;
            }
            if ($result['status'] === 'failed') {
                $job->fail('WORKER_FAILED');
                return null;
            }
            // A service outage throws and rolls back: the consumer must NOT ACK.
            $evaluation = $this->gateway->evaluate([
                'request' => $job->getRequestSnapshot(), 'stage' => $job->getStage()->value,
                'model' => $job->getModel(), 'provider' => $job->getProvider(),
                'prompt_version' => $job->getPromptVersion(), 'candidate' => $job->getCandidate(),
                'result' => $result,
            ]);
            if (($evaluation['outcome'] ?? null) === 'FAILED') {
                $allowed = ['PROMPT_VERSION_MISMATCH', 'MODEL_MISMATCH', 'WORKER_FAILED', 'INVALID_ENRICHMENT'];
                $job->fail(in_array($evaluation['failure'] ?? null, $allowed, true) ? $evaluation['failure'] : 'INVALID_ENRICHMENT');
                return null;
            }
            if (($evaluation['outcome'] ?? null) === 'REPAIR') {
                if ($job->getStage() !== EnrichmentJobStage::GENERATE
                    || !is_array($evaluation['candidate'] ?? null)
                    || !self::validUuid($evaluation['job']['job_id'] ?? null)
                    || $evaluation['job']['job_id'] === $job->getActiveLlmJobId()
                    || ($evaluation['job']['model'] ?? null) !== $job->getModel()) {
                    throw new \RuntimeException('Invalid NLP repair transition.');
                }
                $job->repair($evaluation['candidate'], $evaluation['job']['job_id']);
                return $evaluation['job'];
            }
            if (($evaluation['outcome'] ?? null) !== 'COMPLETED') {
                throw new \RuntimeException('Invalid NLP evaluation response.');
            }
            $payload = $evaluation['enrichment'];
            $enrichment = new VocabularyEnrichmentResult(
                translationPl: $payload['translation_pl'], definitionEn: $payload['definition_en'],
                meaningInContext: $payload['meaning_in_context'], simpleExample: $payload['simple_example'],
                cefrLevel: $payload['cefr_level'], provider: $job->getProvider(),
                model: $job->getModel(), promptVersion: $job->getPromptVersion(),
            );
            $this->persister->save($job->getPublicationVocabulary(), $enrichment, $job->getRequestSnapshot()['context_sentence']);
            // Flush enrichment before refreshing cards, under the same context lock
            // and transaction as completion. Failed saves must not change card content.
            $this->entityManager->flush();
            $this->learningCardGenerator->synchronize($job->getPublicationVocabulary());
            $job->complete();
            return null;
        });
        if ($repair !== null) {
            // REPAIR UUID/candidate are committed BEFORE the publish.
            $this->publish($job, $repair);
        }
        return $disposition;
    }

    private function publish(PublicationVocabularyEnrichmentJob $job, array $message): void
    {
        $failure = null;
        try {
            $this->gateway->publish($message);
        } catch (\Throwable) {
            $failure = 'PUBLICATION_UNCONFIRMED';
        }
        $this->locked($job, function () use ($job, $message, $failure): void {
            // A fast result/new submission may already have completed or superseded this stage.
            if ($job->getActiveLlmJobId() !== $message['job_id'] || !$this->current($job)) { return; }
            if ($failure !== null) { $job->fail($failure); } else { $job->processing(); }
        });
    }

    private function fail(PublicationVocabularyEnrichmentJob $job, string $reason): void
    {
        $this->locked($job, function () use ($job, $reason): void {
            if ($this->current($job)) { $job->fail($reason); }
        });
    }

    private function current(PublicationVocabularyEnrichmentJob $job): bool
    {
        if (!$job->isPending()) { return false; }
        $context = $job->getPublicationVocabulary();
        if ($context->isDeleted()) { $job->fail('VOCABULARY_REMOVED'); return false; }
        if ($context->getEnrichmentRevision() !== $job->getRequestRevision()) {
            $job->fail('SUPERSEDED');
            return false;
        }
        return true;
    }

    private function locked(PublicationVocabularyEnrichmentJob $job, callable $work): mixed
    {
        return $this->entityManager->wrapInTransaction(function () use ($job, $work): mixed {
            $this->entityManager->lock($job->getPublicationVocabulary(), LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($job->getPublicationVocabulary());
            $this->entityManager->refresh($job);
            return $work();
        });
    }

    private function assertPrepared(array $prepared): void
    {
        if (!self::validUuid($prepared['job']['job_id'] ?? null)) {
            throw new \RuntimeException('Invalid prepared job ID.');
        }
        foreach (['model' => 128, 'provider' => 64, 'prompt_version' => 64] as $field => $limit) {
            $value = $field === 'model' ? ($prepared['job']['model'] ?? null) : ($prepared[$field] ?? null);
            if (!is_string($value) || trim($value) === '' || strlen($value) > $limit) {
                throw new \RuntimeException('Invalid prepared job metadata.');
            }
        }
    }

    public static function validUuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $value) === 1;
    }
}

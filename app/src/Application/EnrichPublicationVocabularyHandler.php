<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\PublicationVocabulary;
use App\Entity\PublicationVocabularyEnrichment;
use App\Enrichment\EnrichmentRequestFactory;
use App\Enrichment\EnrichmentPersister;
use App\Enrichment\VocabularyEnrichmentException;
use App\Enrichment\VocabularyEnrichmentProviderInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class EnrichPublicationVocabularyHandler
{
    public function __construct(
        private EnrichmentRequestFactory $requests,
        private EnrichmentPersister $persister,
        private VocabularyEnrichmentProviderInterface $provider,
        private EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(PublicationVocabulary $publicationVocabulary): PublicationVocabularyEnrichment
    {
        // Expected eligibility failures must not close Doctrine's entity manager
        // while the unchanged bulk action continues with other vocabulary rows.
        $this->requests->create($publicationVocabulary);
        [$request, $revision] = $this->entityManager->wrapInTransaction(function () use ($publicationVocabulary): array {
            $this->entityManager->lock($publicationVocabulary, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($publicationVocabulary);
            $request = $this->requests->create($publicationVocabulary);
            return [$request, $publicationVocabulary->beginEnrichmentRequest()];
        });
        $result = $this->provider->enrich($request);

        $enrichment = $this->entityManager->wrapInTransaction(function () use ($publicationVocabulary, $request, $result, $revision): ?PublicationVocabularyEnrichment {
            $this->entityManager->lock($publicationVocabulary, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($publicationVocabulary);
            if ($publicationVocabulary->isDeleted() || $publicationVocabulary->getEnrichmentRevision() !== $revision) {
                return null;
            }
            return $this->persister->save($publicationVocabulary, $result, $request->contextSentence);
        });
        if ($enrichment === null) {
            throw new VocabularyEnrichmentException('Enrichment request was superseded or removed.');
        }
        return $enrichment;
    }
}

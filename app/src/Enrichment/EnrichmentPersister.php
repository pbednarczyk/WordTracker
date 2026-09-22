<?php

declare(strict_types=1);

namespace App\Enrichment;

use App\Entity\PublicationVocabulary;
use App\Entity\PublicationVocabularyEnrichment;
use Doctrine\ORM\EntityManagerInterface;

/** Caller owns the transaction, revision check and flush. */
final readonly class EnrichmentPersister
{
    private const PROMPT_VERSION = 'word-enrichment-v1';
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function save(PublicationVocabulary $publicationVocabulary, VocabularyEnrichmentResult $result, string $sourceSentence): PublicationVocabularyEnrichment
    {
        $enrichment = $publicationVocabulary->getEnrichment();
        if ($enrichment === null) {
            $enrichment = new PublicationVocabularyEnrichment(
                publicationVocabulary: $publicationVocabulary,
                translationPl: $result->translationPl,
                definitionEn: $result->definitionEn,
                meaningInContext: $result->meaningInContext,
                simpleExample: $result->simpleExample,
                cefrLevel: $result->cefrLevel,
                sourceSentence: $sourceSentence,
                provider: $result->provider,
                model: $result->model,
                promptVersion: $result->promptVersion ?? self::PROMPT_VERSION,
            );
            $this->entityManager->persist($enrichment);
        } else {
            $enrichment->update(
                translationPl: $result->translationPl,
                definitionEn: $result->definitionEn,
                meaningInContext: $result->meaningInContext,
                simpleExample: $result->simpleExample,
                cefrLevel: $result->cefrLevel,
                sourceSentence: $sourceSentence,
                provider: $result->provider,
                model: $result->model,
                promptVersion: $result->promptVersion ?? self::PROMPT_VERSION,
            );
        }

        return $enrichment;
    }
}

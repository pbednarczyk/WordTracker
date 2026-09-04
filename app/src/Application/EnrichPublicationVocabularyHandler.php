<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\PublicationVocabulary;
use App\Entity\PublicationVocabularyEnrichment;
use App\Enrichment\VocabularyEnrichmentException;
use App\Enrichment\VocabularyEnrichmentProviderInterface;
use App\Enrichment\VocabularyEnrichmentRequest;
use App\Repository\VocabularyOccurrenceRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class EnrichPublicationVocabularyHandler
{
    private const PROMPT_VERSION = 'word-enrichment-v1';

    public function __construct(
        private VocabularyOccurrenceRepository $vocabularyOccurrenceRepository,
        private VocabularyEnrichmentProviderInterface $provider,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(PublicationVocabulary $publicationVocabulary): PublicationVocabularyEnrichment
    {
        $publication = $publicationVocabulary->getPublication();
        $item = $publicationVocabulary->getVocabularyItem();
        if ($publication->getLanguage() !== 'en' || $item->getLanguage() !== 'en') {
            throw new VocabularyEnrichmentException('AI enrichment currently supports English source vocabulary only.');
        }

        $occurrence = $this->vocabularyOccurrenceRepository->findRepresentativeForPublicationVocabulary($publicationVocabulary);
        if ($occurrence === null) {
            throw new VocabularyEnrichmentException('Cannot generate enrichment because no occurrence is available.');
        }

        $sourceSentence = trim((string) $occurrence->getSentence());
        if ($sourceSentence === '') {
            throw new VocabularyEnrichmentException('Cannot generate enrichment because the occurrence has no context sentence.');
        }

        $result = $this->provider->enrich(new VocabularyEnrichmentRequest(
            lemma: $item->getLemma(),
            partOfSpeech: $item->getPartOfSpeech(),
            originalForm: $occurrence->getOriginalForm(),
            contextSentence: $sourceSentence,
            sourceLanguage: 'en',
            targetLanguage: 'pl',
        ));

        return $this->entityManager->wrapInTransaction(function () use ($publicationVocabulary, $result, $sourceSentence): PublicationVocabularyEnrichment {
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

            $this->entityManager->flush();

            return $enrichment;
        });
    }
}

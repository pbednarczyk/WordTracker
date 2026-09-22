<?php

declare(strict_types=1);

namespace App\Enrichment;

use App\Entity\PublicationVocabulary;
use App\Repository\VocabularyOccurrenceRepository;

final readonly class EnrichmentRequestFactory
{
    public function __construct(private VocabularyOccurrenceRepository $vocabularyOccurrenceRepository) {}

    public function create(PublicationVocabulary $publicationVocabulary): VocabularyEnrichmentRequest
    {
        if ($publicationVocabulary->isDeleted()) {
            throw new VocabularyEnrichmentException('Cannot generate enrichment for removed publication vocabulary.');
        }

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

        return new VocabularyEnrichmentRequest(
            lemma: $item->getLemma(), partOfSpeech: $item->getPartOfSpeech(),
            originalForm: $occurrence->getOriginalForm(), contextSentence: $sourceSentence,
            sourceLanguage: 'en', targetLanguage: 'pl',
        );
    }
}

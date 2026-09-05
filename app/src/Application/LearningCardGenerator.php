<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\LearningCard;
use App\Entity\PublicationVocabulary;
use App\Entity\VocabularyOccurrence;
use App\Enum\LearningCardType;
use App\Repository\LearningCardRepository;
use App\Repository\VocabularyOccurrenceRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class LearningCardGenerator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LearningCardRepository $learningCardRepository,
        private VocabularyOccurrenceRepository $vocabularyOccurrenceRepository,
    ) {
    }

    public function generate(PublicationVocabulary $publicationVocabulary): LearningCardGenerationResult
    {
        $enrichment = $publicationVocabulary->getEnrichment();
        if ($enrichment === null) {
            return new LearningCardGenerationResult(created: 0, existing: 0, skippedWithoutEnrichment: 1, skippedCloze: 0);
        }

        $item = $publicationVocabulary->getVocabularyItem();
        $existingTypes = $this->learningCardRepository->existingTypesForPublicationVocabulary($publicationVocabulary);
        $contextSentence = $enrichment->getSourceSentence();
        $representativeOccurrence = $this->vocabularyOccurrenceRepository->findRepresentativeForPublicationVocabulary($publicationVocabulary);
        $created = 0;
        $existing = 0;
        $skippedCloze = 0;

        foreach ($this->buildCandidates($publicationVocabulary, $representativeOccurrence, $contextSentence) as $candidate) {
            if (in_array($candidate['type'], $existingTypes, true)) {
                ++$existing;
                continue;
            }

            if ($candidate['type'] === LearningCardType::CLOZE && $candidate['clozeSentence'] === null) {
                ++$skippedCloze;
                continue;
            }

            $this->entityManager->persist(new LearningCard(
                vocabularyItem: $item,
                publicationVocabulary: $publicationVocabulary,
                publicationVocabularyEnrichment: $enrichment,
                type: $candidate['type'],
                front: $candidate['front'],
                back: $candidate['back'],
                contextSentence: $candidate['contextSentence'],
                clozeSentence: $candidate['clozeSentence'],
            ));
            ++$created;
        }

        if ($created > 0) {
            $this->entityManager->flush();
        }

        return new LearningCardGenerationResult(
            created: $created,
            existing: $existing,
            skippedWithoutEnrichment: 0,
            skippedCloze: $skippedCloze,
        );
    }

    /**
     * @param list<PublicationVocabulary> $publicationVocabularyRows
     */
    public function generateMany(array $publicationVocabularyRows): LearningCardGenerationResult
    {
        $result = new LearningCardGenerationResult(created: 0, existing: 0, skippedWithoutEnrichment: 0, skippedCloze: 0);
        foreach ($publicationVocabularyRows as $publicationVocabulary) {
            $result = $result->merge($this->generate($publicationVocabulary));
        }

        return $result;
    }

    /**
     * @return list<array{type: LearningCardType, front: string, back: string, contextSentence: ?string, clozeSentence: ?string}>
     */
    private function buildCandidates(
        PublicationVocabulary $publicationVocabulary,
        ?VocabularyOccurrence $representativeOccurrence,
        string $contextSentence,
    ): array {
        $item = $publicationVocabulary->getVocabularyItem();
        $enrichment = $publicationVocabulary->getEnrichment();
        \assert($enrichment !== null);

        $lemma = $item->getLemma();
        $translation = $enrichment->getTranslationPl();
        $clozeSentence = $this->buildClozeSentence($contextSentence, $representativeOccurrence?->getOriginalForm() ?? $lemma);

        return [
            [
                'type' => LearningCardType::FORWARD,
                'front' => $lemma,
                'back' => $translation,
                'contextSentence' => $contextSentence,
                'clozeSentence' => null,
            ],
            [
                'type' => LearningCardType::REVERSE,
                'front' => $translation,
                'back' => $lemma,
                'contextSentence' => $contextSentence,
                'clozeSentence' => null,
            ],
            [
                'type' => LearningCardType::CLOZE,
                'front' => $clozeSentence ?? '',
                'back' => $lemma,
                'contextSentence' => $contextSentence,
                'clozeSentence' => $clozeSentence,
            ],
            [
                'type' => LearningCardType::CONTEXT_MEANING,
                'front' => sprintf("What does \"%s\" mean in this sentence?\n\n\"%s\"", $lemma, $contextSentence),
                'back' => $enrichment->getMeaningInContext(),
                'contextSentence' => $contextSentence,
                'clozeSentence' => null,
            ],
        ];
    }

    private function buildClozeSentence(string $sentence, string $originalForm): ?string
    {
        $sentence = trim($sentence);
        $originalForm = trim($originalForm);
        if ($sentence === '' || $originalForm === '') {
            return null;
        }

        $pattern = '/(?<![\p{L}\p{N}_])'.preg_quote($originalForm, '/').'(?![\p{L}\p{N}_])/iu';
        $matches = [];
        $matchCount = preg_match_all($pattern, $sentence, $matches);

        if ($matchCount !== 1) {
            return null;
        }

        $cloze = preg_replace($pattern, '_____', $sentence, 1);
        if (!is_string($cloze)) {
            return null;
        }

        return $cloze;
    }
}

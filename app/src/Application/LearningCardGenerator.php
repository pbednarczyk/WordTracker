<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\LearningCard;
use App\Entity\PublicationVocabulary;
use App\Enum\LearningCardType;
use App\Repository\LearningCardRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class LearningCardGenerator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LearningCardRepository $learningCardRepository,
        private LearningCardGenerationPolicy $generationPolicy,
    ) {
    }

    public function generate(PublicationVocabulary $publicationVocabulary): LearningCardGenerationResult
    {
        if ($publicationVocabulary->isDeleted()) {
            return new LearningCardGenerationResult(created: 0, existing: 0, skippedWithoutEnrichment: 1, skippedCloze: 0);
        }

        $enrichment = $publicationVocabulary->getEnrichment();
        if ($enrichment === null) {
            return new LearningCardGenerationResult(created: 0, existing: 0, skippedWithoutEnrichment: 1, skippedCloze: 0);
        }

        $item = $publicationVocabulary->getVocabularyItem();
        $existingTypes = $this->learningCardRepository->existingTypesForPublicationVocabulary($publicationVocabulary);
        $contextSentence = $enrichment->getSourceSentence();
        $created = 0;
        $existing = 0;
        $skippedCloze = 0;

        foreach ($this->buildCandidates($publicationVocabulary, $contextSentence) as $candidate) {
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
        string $contextSentence,
    ): array {
        $item = $publicationVocabulary->getVocabularyItem();
        $enrichment = $publicationVocabulary->getEnrichment();
        \assert($enrichment !== null);

        $lemma = $item->getLemma();
        $translation = $enrichment->getTranslationPl();
        $candidates = [];

        foreach ($this->generationPolicy->defaultTypesFor($publicationVocabulary) as $type) {
            $candidates[] = match ($type) {
                LearningCardType::FORWARD => [
                    'type' => LearningCardType::FORWARD,
                    'front' => $lemma,
                    'back' => $translation,
                    'contextSentence' => $contextSentence,
                    'clozeSentence' => null,
                ],
                LearningCardType::REVERSE => [
                    'type' => LearningCardType::REVERSE,
                    'front' => sprintf("Recall the target English word:\n\n%s", $translation),
                    'back' => $lemma,
                    'contextSentence' => $contextSentence,
                    'clozeSentence' => null,
                ],
                LearningCardType::CONTEXT_MEANING => [
                    'type' => LearningCardType::CONTEXT_MEANING,
                    'front' => sprintf("What does \"%s\" mean in this context?\n\n\"%s\"", $lemma, $contextSentence),
                    'back' => $enrichment->getMeaningInContext(),
                    'contextSentence' => $contextSentence,
                    'clozeSentence' => null,
                ],
                LearningCardType::CLOZE => [
                    'type' => LearningCardType::CLOZE,
                    'front' => '',
                    'back' => $lemma,
                    'contextSentence' => $contextSentence,
                    'clozeSentence' => null,
                ],
            };
        }

        return $candidates;
    }
}

<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\Publication;
use App\Entity\PublicationVocabulary;
use App\Entity\VocabularyItem;
use App\Entity\VocabularyOccurrence;
use App\Nlp\AnalyzedToken;
use App\Nlp\TextAnalysis;
use App\Nlp\TextAnalyzerInterface;
use App\Repository\VocabularyItemRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AnalyzePublicationHandler
{
    public function __construct(
        private TextAnalyzerInterface $textAnalyzer,
        private EntityManagerInterface $entityManager,
        private VocabularyItemRepository $vocabularyItemRepository,
    ) {
    }

    public function __invoke(Publication $publication): AnalyzePublicationResult
    {
        $rawText = $publication->getRawText();
        if ($rawText === null || trim($rawText) === '') {
            throw new PublicationAnalysisException('Publication rawText must not be empty.');
        }

        $analysis = $this->textAnalyzer->analyze($rawText);

        return $this->persistAnalysis($publication, $analysis);
    }

    private function persistAnalysis(Publication $publication, TextAnalysis $analysis): AnalyzePublicationResult
    {
        return $this->entityManager->wrapInTransaction(function () use ($publication, $analysis): AnalyzePublicationResult {
            $this->deleteExistingAnalysis($publication);

            $itemsByIdentity = [];
            $aggregation = [];
            $ignoredProperNouns = 0;
            $vocabularyOccurrences = 0;

            foreach ($analysis->tokens as $token) {
                if ($token->isProperNoun) {
                    ++$ignoredProperNouns;
                    continue;
                }

                $vocabularyItem = $this->resolveVocabularyItem($analysis->language, $token, $itemsByIdentity);
                $this->entityManager->persist(new VocabularyOccurrence(
                    publication: $publication,
                    vocabularyItem: $vocabularyItem,
                    originalForm: $token->text,
                    sentence: $token->sentence,
                    position: $token->position,
                ));

                $identity = $this->identity($analysis->language, $token->lemma, $token->pos);
                $aggregation[$identity] = [
                    'item' => $vocabularyItem,
                    'occurrences' => ($aggregation[$identity]['occurrences'] ?? 0) + 1,
                ];
                ++$vocabularyOccurrences;
            }

            foreach ($aggregation as $aggregate) {
                $this->entityManager->persist(new PublicationVocabulary(
                    publication: $publication,
                    vocabularyItem: $aggregate['item'],
                    occurrences: $aggregate['occurrences'],
                ));
            }

            $publication->markAnalyzed();
            $this->entityManager->persist($publication);
            $this->entityManager->flush();

            return new AnalyzePublicationResult(
                tokenCount: $analysis->tokenCount,
                wordCount: $analysis->wordCount,
                vocabularyOccurrences: $vocabularyOccurrences,
                uniqueVocabularyItems: count($aggregation),
                ignoredProperNouns: $ignoredProperNouns,
            );
        });
    }

    private function deleteExistingAnalysis(Publication $publication): void
    {
        $this->entityManager->createQuery('DELETE FROM App\Entity\PublicationVocabulary pv WHERE pv.publication = :publication')
            ->setParameter('publication', $publication)
            ->execute();

        $this->entityManager->createQuery('DELETE FROM App\Entity\VocabularyOccurrence vo WHERE vo.publication = :publication')
            ->setParameter('publication', $publication)
            ->execute();
    }

    /**
     * @param array<string, VocabularyItem> $itemsByIdentity
     */
    private function resolveVocabularyItem(string $language, AnalyzedToken $token, array &$itemsByIdentity): VocabularyItem
    {
        $language = trim($language);
        $lemma = trim($token->lemma);
        $partOfSpeech = trim($token->pos);

        if ($language === '' || $lemma === '' || $partOfSpeech === '') {
            throw new PublicationAnalysisException('NLP analysis contains a token with empty language, lemma, or POS.');
        }

        $identity = $this->identity($language, $lemma, $partOfSpeech);
        if (isset($itemsByIdentity[$identity])) {
            return $itemsByIdentity[$identity];
        }

        $vocabularyItem = $this->vocabularyItemRepository->findOneByIdentity($language, $lemma, $partOfSpeech);
        if ($vocabularyItem === null) {
            $vocabularyItem = new VocabularyItem($language, $lemma, $partOfSpeech);
            $this->entityManager->persist($vocabularyItem);
        }

        $itemsByIdentity[$identity] = $vocabularyItem;

        return $vocabularyItem;
    }

    private function identity(string $language, string $lemma, string $partOfSpeech): string
    {
        return $language."\0".$lemma."\0".$partOfSpeech;
    }
}

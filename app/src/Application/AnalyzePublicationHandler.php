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
use App\Repository\PublicationVocabularyRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AnalyzePublicationHandler
{
    public function __construct(
        private TextAnalyzerInterface $textAnalyzer,
        private EntityManagerInterface $entityManager,
        private VocabularyItemRepository $vocabularyItemRepository,
        private PublicationVocabularyRepository $publicationVocabularyRepository,
    ) {
    }

    private const IGNORED_ENTITY_TYPES = [
        'PERSON' => true,
        'GPE' => true,
        'ORG' => true,
        'LOC' => true,
        'NORP' => true,
        'FAC' => true,
        'PRODUCT' => true,
        'EVENT' => true,
        'WORK_OF_ART' => true,
        'LAW' => true,
        'LANGUAGE' => true,
    ];

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
            $existingPublicationVocabulary = $this->findExistingPublicationVocabulary($publication);
            $this->deleteExistingOccurrences($publication);

            $itemsByIdentity = [];
            $aggregation = [];
            $ignoredNamedEntities = 0;
            $vocabularyOccurrences = 0;

            foreach ($analysis->tokens as $token) {
                if ($this->shouldIgnoreToken($token)) {
                    ++$ignoredNamedEntities;
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

            foreach ($aggregation as $identity => $aggregate) {
                if (isset($existingPublicationVocabulary[$identity])) {
                    $existingPublicationVocabulary[$identity]->updateOccurrences($aggregate['occurrences']);
                    continue;
                }

                $this->entityManager->persist(new PublicationVocabulary(
                    publication: $publication,
                    vocabularyItem: $aggregate['item'],
                    occurrences: $aggregate['occurrences'],
                ));
            }

            foreach ($existingPublicationVocabulary as $identity => $publicationVocabulary) {
                if (!isset($aggregation[$identity])) {
                    $this->entityManager->remove($publicationVocabulary);
                }
            }

            $publication->markAnalyzed();
            $this->entityManager->persist($publication);
            $this->entityManager->flush();

            return new AnalyzePublicationResult(
                tokenCount: $analysis->tokenCount,
                wordCount: $analysis->wordCount,
                vocabularyOccurrences: $vocabularyOccurrences,
                uniqueVocabularyItems: count($aggregation),
                ignoredNamedEntities: $ignoredNamedEntities,
            );
        });
    }

    private function shouldIgnoreToken(AnalyzedToken $token): bool
    {
        if ($token->entityType === null) {
            return false;
        }

        return self::IGNORED_ENTITY_TYPES[$token->entityType] ?? false;
    }

    private function deleteExistingOccurrences(Publication $publication): void
    {
        $this->entityManager->createQuery('DELETE FROM App\Entity\VocabularyOccurrence vo WHERE vo.publication = :publication')
            ->setParameter('publication', $publication)
            ->execute();
    }

    /**
     * @return array<string, PublicationVocabulary>
     */
    private function findExistingPublicationVocabulary(Publication $publication): array
    {
        $rows = $this->publicationVocabularyRepository->findForPublicationOrdered($publication);
        $publicationVocabulary = [];

        foreach ($rows as $row) {
            $item = $row->getVocabularyItem();
            $publicationVocabulary[$this->identity($item->getLanguage(), $item->getLemma(), $item->getPartOfSpeech())] = $row;
        }

        return $publicationVocabulary;
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

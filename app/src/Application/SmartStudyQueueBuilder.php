<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\LearningCard;
use App\Enum\LearningCardType;
use Random\Randomizer;

final readonly class SmartStudyQueueBuilder
{
    public function __construct(
        private int $studyMinSiblingDistance = 5,
        private ?Randomizer $randomizer = null,
    ) {
    }

    /**
     * @param list<LearningCard> $cards
     *
     * @return list<int>
     */
    public function build(array $cards, int $limit): array
    {
        if ($cards === [] || $limit <= 0) {
            return [];
        }

        $groups = $this->groupCards($this->deduplicate($cards));
        $queue = [];
        $lastSiblingIndex = [];
        $recentTypes = [];

        while ($groups !== [] && count($queue) < $limit) {
            $position = count($queue);
            $groupKey = $this->chooseGroupKey($groups, $position, $lastSiblingIndex, $recentTypes);
            $card = $this->takeBestCard($groups[$groupKey], $recentTypes);

            if ($groups[$groupKey] === []) {
                unset($groups[$groupKey]);
            }

            $id = $card->getId();
            if ($id === null) {
                continue;
            }

            $queue[] = $id;
            $lastSiblingIndex[$this->siblingKey($card)] = $position;
            $recentTypes[] = $card->getType();
            if (count($recentTypes) > 2) {
                array_shift($recentTypes);
            }
        }

        return $queue;
    }

    /**
     * @param list<LearningCard> $cards
     *
     * @return list<LearningCard>
     */
    private function deduplicate(array $cards): array
    {
        $deduplicated = [];
        $seen = [];

        foreach ($cards as $card) {
            $id = $card->getId();
            if ($id === null || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $deduplicated[] = $card;
        }

        return $deduplicated;
    }

    /**
     * @param list<LearningCard> $cards
     *
     * @return array<string, list<LearningCard>>
     */
    private function groupCards(array $cards): array
    {
        $groups = [];
        foreach ($this->shuffleCards($cards) as $card) {
            $groups[$this->siblingKey($card)][] = $card;
        }

        foreach ($groups as $key => $groupCards) {
            $groups[$key] = $this->shuffleCards($groupCards);
        }

        return $groups;
    }

    /**
     * @param array<string, list<LearningCard>> $groups
     * @param array<string, int> $lastSiblingIndex
     * @param list<LearningCardType> $recentTypes
     */
    private function chooseGroupKey(array $groups, int $position, array $lastSiblingIndex, array $recentTypes): string
    {
        $eligible = [];
        foreach ($groups as $key => $cards) {
            $distance = $this->siblingDistanceForKey($key, $position, $lastSiblingIndex);
            if ($distance >= $this->studyMinSiblingDistance) {
                $eligible[$key] = $cards;
            }
        }

        return $eligible !== []
            ? $this->bestEligibleGroupKey($eligible, $recentTypes)
            : $this->bestFallbackGroupKey($groups, $position, $lastSiblingIndex, $recentTypes);
    }

    /**
     * @param array<string, list<LearningCard>> $groups
     * @param list<LearningCardType> $recentTypes
     */
    private function bestEligibleGroupKey(array $groups, array $recentTypes): string
    {
        $bestKey = array_key_first($groups);
        \assert(is_string($bestKey));
        $bestScore = PHP_INT_MIN;

        foreach ($groups as $key => $cards) {
            $score = count($cards) * 100 + $this->bestTypeDiversityScore($cards, $recentTypes);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestKey = $key;
            }
        }

        return $bestKey;
    }

    /**
     * @param array<string, list<LearningCard>> $groups
     * @param array<string, int> $lastSiblingIndex
     * @param list<LearningCardType> $recentTypes
     */
    private function bestFallbackGroupKey(array $groups, int $position, array $lastSiblingIndex, array $recentTypes): string
    {
        $bestKey = array_key_first($groups);
        \assert(is_string($bestKey));
        $bestScore = PHP_INT_MIN;

        foreach ($groups as $key => $cards) {
            $score = $this->siblingDistanceForKey($key, $position, $lastSiblingIndex) * 100
                + count($cards) * 10
                + $this->bestTypeDiversityScore($cards, $recentTypes);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestKey = $key;
            }
        }

        return $bestKey;
    }

    /**
     * @param list<LearningCard> $cards
     * @param list<LearningCardType> $recentTypes
     */
    private function takeBestCard(array &$cards, array $recentTypes): LearningCard
    {
        $bestIndex = 0;
        $bestScore = PHP_INT_MIN;

        foreach ($cards as $index => $card) {
            $score = $this->typeDiversityScore($card, $recentTypes);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        $card = $cards[$bestIndex];
        array_splice($cards, $bestIndex, 1);

        return $card;
    }

    /**
     * @param list<LearningCard> $cards
     * @param list<LearningCardType> $recentTypes
     */
    private function bestTypeDiversityScore(array $cards, array $recentTypes): int
    {
        $bestScore = PHP_INT_MIN;
        foreach ($cards as $card) {
            $bestScore = max($bestScore, $this->typeDiversityScore($card, $recentTypes));
        }

        return $bestScore;
    }

    /**
     * @param list<LearningCardType> $recentTypes
     */
    private function typeDiversityScore(LearningCard $card, array $recentTypes): int
    {
        $type = $card->getType();
        $score = 0;

        if (($recentTypes[array_key_last($recentTypes)] ?? null) !== $type) {
            $score += 2;
        }

        if (count($recentTypes) >= 2 && $recentTypes[0] === $type && $recentTypes[1] === $type) {
            $score -= 4;
        }

        return $score;
    }

    /**
     * @param array<string, int> $lastSiblingIndex
     */
    private function siblingDistanceForKey(string $key, int $position, array $lastSiblingIndex): int
    {
        if (!isset($lastSiblingIndex[$key])) {
            return PHP_INT_MAX;
        }

        return $position - $lastSiblingIndex[$key] - 1;
    }

    private function siblingKey(LearningCard $card): string
    {
        return 'vocabulary-'.$card->getVocabularyItem()->getId();
    }

    /**
     * @param list<LearningCard> $cards
     *
     * @return list<LearningCard>
     */
    private function shuffleCards(array $cards): array
    {
        return ($this->randomizer ?? new Randomizer())->shuffleArray($cards);
    }
}

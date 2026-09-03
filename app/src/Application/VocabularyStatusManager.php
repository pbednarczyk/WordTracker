<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\VocabularyItem;
use App\Enum\VocabularyStatus;
use App\Repository\VocabularyItemRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class VocabularyStatusManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private VocabularyItemRepository $vocabularyItemRepository,
    ) {
    }

    public function updateOne(VocabularyItem $item, VocabularyStatus $status): void
    {
        $this->applyStatus($item, $status);
        $this->entityManager->flush();
    }

    /**
     * @param list<int> $ids
     *
     * @return int number of updated vocabulary items
     */
    public function updateManyByIds(array $ids, VocabularyStatus $status): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }

        return $this->entityManager->wrapInTransaction(function () use ($ids, $status): int {
            $items = $this->vocabularyItemRepository->findBy(['id' => $ids]);
            if (count($items) !== count($ids)) {
                throw new \InvalidArgumentException('One or more vocabulary items do not exist.');
            }

            foreach ($items as $item) {
                $this->applyStatus($item, $status);
            }

            $this->entityManager->flush();

            return count($items);
        });
    }

    private function applyStatus(VocabularyItem $item, VocabularyStatus $status): void
    {
        match ($status) {
            VocabularyStatus::KNOWN => $item->markKnown(),
            VocabularyStatus::UNKNOWN => $item->markUnknown(),
        };
    }
}

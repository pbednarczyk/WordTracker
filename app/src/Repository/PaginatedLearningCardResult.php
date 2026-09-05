<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LearningCard;

final readonly class PaginatedLearningCardResult
{
    /**
     * @param list<LearningCard> $items
     */
    public function __construct(
        public array $items,
        public int $totalItems,
        public int $page,
        public int $perPage,
        public int $totalPages,
    ) {
    }

    public function firstItem(): int
    {
        return $this->totalItems === 0 ? 0 : (($this->page - 1) * $this->perPage) + 1;
    }

    public function lastItem(): int
    {
        return $this->totalItems === 0 ? 0 : min($this->totalItems, $this->page * $this->perPage);
    }
}

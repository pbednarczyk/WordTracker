<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PublicationVocabulary;

final readonly class PaginatedPublicationVocabularyResult
{
    /**
     * @param list<PublicationVocabulary> $items
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
        if ($this->totalItems === 0) {
            return 0;
        }

        return (($this->page - 1) * $this->perPage) + 1;
    }

    public function lastItem(): int
    {
        if ($this->totalItems === 0) {
            return 0;
        }

        return min($this->totalItems, $this->page * $this->perPage);
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\LearningCardType;
use App\Enum\ReviewRating;

final readonly class LearningReviewQuery
{
    public const DEFAULT_PAGE = 1;
    public const DEFAULT_PER_PAGE = 50;

    /**
     * @var list<int>
     */
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    public function __construct(
        public string $search = '',
        public ?ReviewRating $rating = null,
        public ?LearningCardType $type = null,
        public ?int $publicationId = null,
        public int $page = self::DEFAULT_PAGE,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function fromParameters(array $parameters): self
    {
        $page = filter_var($parameters['page'] ?? self::DEFAULT_PAGE, FILTER_VALIDATE_INT);
        if (!is_int($page) || $page < 1) {
            $page = self::DEFAULT_PAGE;
        }

        $perPage = filter_var($parameters['perPage'] ?? self::DEFAULT_PER_PAGE, FILTER_VALIDATE_INT);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        $publicationId = filter_var($parameters['publication'] ?? null, FILTER_VALIDATE_INT);

        return new self(
            search: trim(self::stringValue($parameters['q'] ?? '')),
            rating: self::parseRating(self::stringValue($parameters['rating'] ?? '')),
            type: self::parseType(self::stringValue($parameters['type'] ?? '')),
            publicationId: is_int($publicationId) && $publicationId > 0 ? $publicationId : null,
            page: $page,
            perPage: $perPage,
        );
    }

    public function withPage(int $page): self
    {
        return new self(
            search: $this->search,
            rating: $this->rating,
            type: $this->type,
            publicationId: $this->publicationId,
            page: max(1, $page),
            perPage: $this->perPage,
        );
    }

    /**
     * @return array<string, string|int>
     */
    public function toUrlParameters(bool $includePagination = true): array
    {
        $parameters = [
            'q' => $this->search,
            'rating' => $this->rating?->value ?? 'all',
            'type' => $this->type?->value ?? 'all',
            'publication' => $this->publicationId ?? 'all',
        ];

        if ($includePagination) {
            $parameters['page'] = $this->page;
            $parameters['perPage'] = $this->perPage;
        }

        return $parameters;
    }

    private static function parseRating(string $rating): ?ReviewRating
    {
        $normalized = strtoupper(trim($rating));
        if ($normalized === '' || $normalized === 'ALL') {
            return null;
        }

        return ReviewRating::tryFrom($normalized);
    }

    private static function parseType(string $type): ?LearningCardType
    {
        $normalized = strtoupper(trim($type));
        if ($normalized === '' || $normalized === 'ALL') {
            return null;
        }

        return LearningCardType::tryFrom($normalized);
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}

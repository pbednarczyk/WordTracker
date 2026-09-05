<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\LearningCardType;
use App\Enum\VocabularyStatus;

final readonly class LearningCardQuery
{
    public const ACTIVE_ALL = 'all';
    public const ACTIVE_YES = 'yes';
    public const ACTIVE_NO = 'no';

    public const SORT_LEMMA = 'lemma';
    public const SORT_TYPE = 'type';
    public const SORT_CREATED_AT = 'createdAt';
    public const SORT_PUBLICATION = 'publication';

    public const DIRECTION_ASC = 'asc';
    public const DIRECTION_DESC = 'desc';

    public const DEFAULT_SORT = self::SORT_CREATED_AT;
    public const DEFAULT_DIRECTION = self::DIRECTION_DESC;
    public const DEFAULT_PAGE = 1;
    public const DEFAULT_PER_PAGE = 50;
    public const STUDY_LIMIT = 100;

    /**
     * @var list<int>
     */
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    /**
     * @param positive-int|null $publicationId
     */
    public function __construct(
        public string $search = '',
        public ?LearningCardType $type = null,
        public ?int $publicationId = null,
        public ?VocabularyStatus $status = null,
        public string $active = self::ACTIVE_YES,
        public string $sort = self::DEFAULT_SORT,
        public string $direction = self::DEFAULT_DIRECTION,
        public int $page = self::DEFAULT_PAGE,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function fromParameters(array $parameters): self
    {
        $sort = self::stringValue($parameters['sort'] ?? '');
        if (!in_array($sort, [self::SORT_LEMMA, self::SORT_TYPE, self::SORT_CREATED_AT, self::SORT_PUBLICATION], true)) {
            $sort = self::DEFAULT_SORT;
        }

        $direction = strtolower(self::stringValue($parameters['direction'] ?? ''));
        if (!in_array($direction, [self::DIRECTION_ASC, self::DIRECTION_DESC], true)) {
            $direction = self::defaultDirectionForSort($sort);
        }

        $active = strtolower(self::stringValue($parameters['active'] ?? self::ACTIVE_YES));
        if (!in_array($active, [self::ACTIVE_ALL, self::ACTIVE_YES, self::ACTIVE_NO], true)) {
            $active = self::ACTIVE_YES;
        }

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
            type: self::parseType(self::stringValue($parameters['type'] ?? '')),
            publicationId: is_int($publicationId) && $publicationId > 0 ? $publicationId : null,
            status: self::parseStatus(self::stringValue($parameters['status'] ?? '')),
            active: $active,
            sort: $sort,
            direction: $direction,
            page: $page,
            perPage: $perPage,
        );
    }

    public static function defaultDirectionForSort(string $sort): string
    {
        return $sort === self::SORT_CREATED_AT ? self::DIRECTION_DESC : self::DIRECTION_ASC;
    }

    public function withPage(int $page): self
    {
        return new self(
            search: $this->search,
            type: $this->type,
            publicationId: $this->publicationId,
            status: $this->status,
            active: $this->active,
            sort: $this->sort,
            direction: $this->direction,
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
            'type' => $this->type?->value ?? 'all',
            'publication' => $this->publicationId ?? 'all',
            'status' => strtolower($this->status?->value ?? 'all'),
            'active' => $this->active,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ];

        if ($includePagination) {
            $parameters['page'] = $this->page;
            $parameters['perPage'] = $this->perPage;
        }

        return $parameters;
    }

    private static function parseType(string $type): ?LearningCardType
    {
        $normalized = strtoupper(trim($type));
        if ($normalized === '' || $normalized === 'ALL') {
            return null;
        }

        return LearningCardType::tryFrom($normalized);
    }

    private static function parseStatus(string $status): ?VocabularyStatus
    {
        $normalized = strtoupper(trim($status));
        if ($normalized === '' || $normalized === 'ALL') {
            return null;
        }

        return VocabularyStatus::tryFrom($normalized);
    }

    private static function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\VocabularyStatus;
use App\Vocabulary\PartOfSpeech;

final readonly class PublicationVocabularyQuery
{
    public const ENRICHED_ALL = 'all';
    public const ENRICHED_YES = 'yes';
    public const ENRICHED_NO = 'no';

    public const SORT_LEMMA = 'lemma';
    public const SORT_POS = 'pos';
    public const SORT_STATUS = 'status';
    public const SORT_OCCURRENCES = 'occurrences';
    public const SORT_ENRICHED = 'enriched';

    public const DIRECTION_ASC = 'asc';
    public const DIRECTION_DESC = 'desc';

    public const DEFAULT_SORT = self::SORT_OCCURRENCES;
    public const DEFAULT_DIRECTION = self::DIRECTION_DESC;
    public const DEFAULT_PAGE = 1;
    public const DEFAULT_PER_PAGE = 25;

    /**
     * @var list<int>
     */
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    /**
     * @var list<string>
     */
    public const SORTS = [
        self::SORT_LEMMA,
        self::SORT_POS,
        self::SORT_STATUS,
        self::SORT_OCCURRENCES,
        self::SORT_ENRICHED,
    ];

    /**
     * @var list<string>
     */
    public const ENRICHED_VALUES = [
        self::ENRICHED_ALL,
        self::ENRICHED_YES,
        self::ENRICHED_NO,
    ];

    public function __construct(
        public string $search = '',
        public ?VocabularyStatus $status = null,
        public string $enriched = self::ENRICHED_ALL,
        public ?string $partOfSpeech = null,
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
        $sort = self::stringValue($parameters['sort'] ?? null);
        if (!in_array($sort, self::SORTS, true)) {
            $sort = self::DEFAULT_SORT;
        }

        $direction = strtolower(self::stringValue($parameters['direction'] ?? ''));
        if (!in_array($direction, [self::DIRECTION_ASC, self::DIRECTION_DESC], true)) {
            $direction = self::defaultDirectionForSort($sort);
        }

        $enriched = strtolower(self::stringValue($parameters['enriched'] ?? self::ENRICHED_ALL));
        if (!in_array($enriched, self::ENRICHED_VALUES, true)) {
            $enriched = self::ENRICHED_ALL;
        }

        $perPage = filter_var($parameters['perPage'] ?? self::DEFAULT_PER_PAGE, FILTER_VALIDATE_INT);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        $page = filter_var($parameters['page'] ?? self::DEFAULT_PAGE, FILTER_VALIDATE_INT);
        if (!is_int($page) || $page < 1) {
            $page = self::DEFAULT_PAGE;
        }

        return new self(
            search: trim(self::stringValue($parameters['q'] ?? '')),
            status: self::parseStatus(self::stringValue($parameters['status'] ?? '')),
            enriched: $enriched,
            partOfSpeech: PartOfSpeech::normalize(self::stringValue($parameters['pos'] ?? '')),
            sort: $sort,
            direction: $direction,
            page: $page,
            perPage: $perPage,
        );
    }

    public static function defaultDirectionForSort(string $sort): string
    {
        return match ($sort) {
            self::SORT_OCCURRENCES, self::SORT_ENRICHED => self::DIRECTION_DESC,
            default => self::DIRECTION_ASC,
        };
    }

    public function withPage(int $page): self
    {
        return new self(
            search: $this->search,
            status: $this->status,
            enriched: $this->enriched,
            partOfSpeech: $this->partOfSpeech,
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
            'status' => strtolower($this->status?->value ?? 'all'),
            'enriched' => $this->enriched,
            'pos' => $this->partOfSpeech ?? 'all',
            'sort' => $this->sort,
            'direction' => $this->direction,
        ];

        if ($includePagination) {
            $parameters['page'] = $this->page;
            $parameters['perPage'] = $this->perPage;
        }

        return $parameters;
    }

    /**
     * @return array<string, string|int>
     */
    public function toHiddenFields(): array
    {
        return [
            'currentQuery' => $this->search,
            'currentStatus' => strtolower($this->status?->value ?? 'all'),
            'currentEnriched' => $this->enriched,
            'currentPos' => $this->partOfSpeech ?? 'all',
            'currentSort' => $this->sort,
            'currentDirection' => $this->direction,
            'currentPage' => $this->page,
            'currentPerPage' => $this->perPage,
        ];
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
